#!/usr/bin/php
<?php

require_once (__DIR__."/../class/Wpt_common.php");
require_once (__DIR__."/../class/Wpt_editQueue.php");
require_once (__DIR__."/../class/Wpt_group.php");
require_once (__DIR__."/../class/Wpt_postit.php");
require_once (__DIR__.'/../libs/vendor/autoload.php');

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Session
{
  public $conn;
  public $id;
  public $settings;
  public $username;
  public $openedChats = [];
  private $slocale;

  public function init (Wopits $wpt, ConnectionInterface $conn)
  {
    $ret = false;

    $headers = $conn->httpRequest->getHeaders ();
    $token = $wpt->getQueryParams($conn)['token'];
    $User = new Wpt_user ();

    if ( ($r = $User->loadByToken ($token, $headers['X-Forwarded-For'][0])) )
    {
      $this->conn = $conn;
      $this->sessionId = $conn->resourceId;
      $this->username = $r['username'];
      $this->id = $GLOBALS['userId'] = $r['users_id'];

      $l = $headers['Accept-Language'] ?? null;
      $GLOBALS['Accept-Language'] = (empty ($l)) ? null : $l[0];

      $this->slocale = Wpt_common::getsLocale ($User);

      $this->settings = json_decode ($User->getSettings()??'{}');

      // Register user opened walls
      $wpt->registerOpenedWalls (
        $this->sessionId, $this->id, null, $this->settings);

      // Register user active wall
      $wpt->registerActiveWall (
        $this->sessionId, $this->id, null, $this->settings);

      $ret = true;
    }

    return $ret;
  }

  // Load context for wopits framework
  public function loadContext ()
  {
    $GLOBALS['sessionId'] = $this->sessionId;
    $GLOBALS['userId'] = $this->id;
    $GLOBALS['slocale'] = $this->slocale;
    $GLOBALS['locale'] = Wpt_common::changeLocale ($this->slocale);
  }
}

class Wopits implements MessageComponentInterface
{
  private $clients = [];
  private $usersUnique = [];
  private $activeWalls = [];
  private $activeWallsUnique = [];
  private $openedWalls = [];
  private $chatUsers = [];
  private $internals = [];

  public function onOpen (ConnectionInterface $conn)
  {
    $connId = $conn->resourceId;

    // Internal wopits client
    if (!$conn->httpRequest->getHeader ('X-Forwarded-Server'))
    {
      $this->_log ($conn, 'info', "wopits INTERNAL connection");

      $this->internals[$connId] = 1;
    }
    else
    {
      $client = new Session ();

      // Common wopits client
      if ($client->init ($this, $conn))
      {
        $userId = $client->id;
        $this->clients[$connId] = $client;
  
        $this->_log ($conn, 'info',
          "OPEN connection (".count($this->clients)." connected clients)");
  
        if (isset ($client->settings->activeWall))
          $this->_pushWallsUsersCount ([$client->settings->activeWall]);
  
        // Close all sessions if multiple sessions has been detected
        if (isset ($this->usersUnique[$userId]))
        {
          $this->clients[$this->usersUnique[$userId]]->conn->send (
            '{"action": "sessionexists"}');
          $this->clients[$connId]->conn->send (
            '{"action": "sessionexists"}');
        }
        else
          $this->usersUnique[$userId] = $connId;
      }
      else
        throw new Exception ("UNAUTHORIZED login attempt!");
    }
  }

  public function onMessage (ConnectionInterface $conn, $_msg)
  {
    $connId = $conn->resourceId;
    $msg = json_decode ($_msg);

    // Common wopits client
    if (!isset ($this->internals[$connId]))
    {
      $data = ($msg->data) ? json_decode (urldecode ($msg->data)) : null;
      $wallId = null;
      $push = false;
      $action = '';
      $ret = [];
  
      $client = $this->clients[$connId];
      $client->loadContext ();

      //////////////////////////// ROUTING PATHS /////////////////////////////

      // ROUTE chat
      if (preg_match ('#^wall/(\d+)/chat$#', $msg->route, $m))
      {
        list (,$wallId) = $m;

        $push = true;
        $action = 'chat';
        $username = $client->username;

        $ret = [
          'method' => $msg->method,
          'wall' => ['id' => $wallId],
          'username' => $username
        ];

        if ($msg->method == 'POST')
          $ret['msg'] = preg_replace ('/<[^>]+>/', '', $data->msg);
        else
        {
          $ret['internal'] = 1;

          switch ($msg->method)
          {
            case 'PUT':
              if (!isset ($this->chatUsers[$wallId]))
                $this->chatUsers[$wallId] = [];

              $client->openedChats[$wallId] = 1;
              $this->chatUsers[$wallId][$connId] = [
                'id' => $client->id,
                'name' => $client->username
              ];

              $ret['msg'] = '_JOIN_';
              break;

            case 'DELETE':
              // Handle some random case (user close its browser...)
              if (isset ($this->chatUsers[$wallId]))
              {
                $this->_unsetChatUsers ($wallId, $connId);
                unset ($client->openedChats[$wallId]);

                $ret['msg'] = '_LEAVE_';
              }
              break;
          }

          $users = [];
          if (isset ($this->chatUsers[$wallId]))
          {
            foreach ($this->chatUsers[$wallId] as $_connId => $_userData)
              $users[] = $_userData;
          }
          $ret['userslist'] = $users;

          $ret['userscount'] = isset ($this->chatUsers[$wallId]) ?
            count ($this->chatUsers[$wallId]) - 1 : 0;
        }
      }
      // ROUTE User
      // (here we manage users active walls arrays)
      elseif (preg_match ('#^user/(settings|update)$#', $msg->route, $m))
      {
        list (,$type) = $m;

        $User = new Wpt_user (['data' => $data]);

        // User's settings
        if ($type == 'settings')
        {
          $oldSettings = json_decode ($User->getSettings()??'{}');
          $newSettings = json_decode ($data->settings);
  
          // Active wall
          $this->registerActiveWall (
            $connId, $client->id, $oldSettings, $newSettings);
          $client->settings->activeWall = $newSettings->activeWall ?? null;
  
          // Opened walls
          $this->registerOpenedWalls (
            $connId, $client->id, $oldSettings, $newSettings);
          $client->settings->openedWalls = $newSettings->openedWalls ?? [];

          $ret = $User->saveSettings ();
        }
        // User's data update
        elseif ($type == 'update')
          $ret = $User->update ();
      }
      // ROUTE edit queue
      // - PUT to block updates for other users on a item
      // - DELETE to release item and push updates to user's walls
      elseif (preg_match (
                '#^wall/(\d+)/editQueue/'.
                '(wall|cell|header|postit|group)/(\d+)$#', $msg->route, $m))
      {
        list (,$wallId, $item, $itemId) = $m;

        $EditQueue = new Wpt_editQueue ([
          'wallId' => $wallId,
          'data' => $data,
          'item' => $item,
          'itemId' => $itemId
        ]);

        switch ($msg->method)
        {
          // PUT
          case 'PUT':
            $ret = $EditQueue->addTo ();
            break;

          // DELETE
          case 'DELETE':
            $ret = $EditQueue->removeFrom ();
            if ($data && !isset ($ret['error_msg']) && !isset ($ret['error']))
            {
              $push = true;
              $action = (empty ($ret['wall']['removed'])) ?
                          'refreshwall' : 'deletedwall';
            }
            break;
        }
      }
      // ROUTE Generic groups
      elseif (preg_match (
                '#^group/?(\d+)?/?(addUser|removeUser)?/?(\d+)?$#',
                $msg->route, $m))
      {
        @list (,$groupId, $type, $userId) = $m;

        $Group = new Wpt_group ([
          'data' => $data,
          'groupId' => $groupId
        ]);

        switch ($msg->method)
        {
          // POST
          // For both generic and dedicated groups
          case 'POST':
            $ret = $Group->update ();
            break;

          // PUT
          case 'PUT':
            $ret = ($userId) ?
                     $Group->addUser (['userId' => $userId]) :
                     $Group->create (['type' => WPT_GTYPES['generic']]);
            break;

          // DELETE
          case 'DELETE':

            $ret = ($userId) ?
              $this->_removeUserFromGroup ([
                'obj' => $Group,
                'userId' => $userId,
                'wallIds' => $Group->getWallsByGroup (),
                'ret' => &$ret
              ]) : $Group->delete ();
            break;
        }
      }
      // ROUTE Dedicated groups
      elseif (preg_match (
                '#^wall/(\d+)/group/?(\d+)?/?'.
                '(addUser|removeUser|link|unlink)?/?(\d+)?$#',
                $msg->route, $m))
      {
        @list (, $wallId, $groupId, $type, $userId) = $m;

        $Group = new Wpt_group ([
          'wallId' => $wallId,
          'data' => $data,
          'groupId' => $groupId
        ]);

        switch ($msg->method)
        {
          // POST
          // For both generic and dedicated groups
          case 'POST':
            if ($type == 'link')
              $ret = $Group->link ();
            elseif ($type == 'unlink')
            {
              $ret = $Group->unlink ();
              if (!isset ($ret['error']))
              {
                $push = true;
                $action = 'unlinkedwall';
              }
            }
            break;

          // PUT
          case 'PUT':
            $ret = ($userId) ?
                     $Group->addUser (['userId' => $userId]) :
                     $Group->create (['type' => WPT_GTYPES['dedicated']]);
            break;

          // DELETE
          case 'DELETE':

            $ret = ($userId) ?
              $this->_removeUserFromGroup ([
                'obj' => $Group,
                'userId' => $userId,
                'wallIds' => [$wallId],
                'ret' => &$ret
              ]) : $Group->delete ();
            break;
        }
      }
      // ROUTE Wall users view
      //TODO We should use ajax instead of ws
      elseif (preg_match ('#^wall/(\d+)/usersview$#',
                $msg->route, $m))
      {
        @list (,$wallId) = $m;

        $Wall = new Wpt_wall (['wallId' => $wallId]);

        if ($msg->method == 'GET')
          $ret = $Wall->getUsersview (
            array_keys ($this->activeWallsUnique[$wallId]));
      }
      // ROUTE Postit creation
      elseif (preg_match ('#^wall/(\d+)/cell/(\d+)/postit$#', $msg->route, $m))
      {
        list (,$wallId, $cellId) = $m;
  
        $push = true;
        $action = 'refreshwall';
  
        $ret = (new Wpt_postit ([
          'wallId' => $wallId,
          'data' => $data,
          'cellId' => $cellId
        ]))->create ();
      }
      // ROUTE Col/row creation/deletion
      elseif (preg_match ('#^wall/(\d+)/(col|row)/?(\d+)?$#', $msg->route, $m))
      {
        @list (,$wallId, $item, $itemPos) = $m;

        $push = true;
        $action = 'refreshwall';
  
        $Wall = new Wpt_wall ([
          'wallId' => $wallId,
          'data' => $data
        ]);

        switch ($msg->method)
        {
          // PUT
          case 'PUT':
            $ret = $Wall->createWallColRow (['item' => $item]);
            break;

          // DELETE
          case 'DELETE':
            $ret = $Wall->deleteWallColRow ([
              'item' => $item,
              'itemPos' => $itemPos
            ]);
            break;
        }
      }
      // ROUTE for header's pictures
      elseif (preg_match ('#^wall/(\d+)/header/(\d+)/picture$#',
                $msg->route, $m))
      {
        list (,$wallId, $headerId) = $m;

        if ($msg->method == 'DELETE')
          $ret = (new Wpt_wall ([
            'wallId' => $wallId,
            'data' => $data
          ]))->deleteHeaderPicture (['headerId' => $headerId]);
      }
      // ROUTE Postit attachments
      elseif (preg_match (
                '#^wall/(\d+)/cell/(\d+)/postit/(\d+)/'.
                'attachment/?(\d+)?$#',
                $msg->route, $m))
      {
        @list (,$wallId, $cellId, $postitId, $itemId) = $m;

        if ($msg->method == 'DELETE')
          $ret = (new Wpt_postit ([
            'wallId' => $wallId,
            'cellId' => $cellId,
            'postitId' => $postitId
          ]))->deleteAttachment (['attachmentId' => $itemId]);
      }
      // ROUTE User profil picture
      elseif ($msg->route == 'user/picture')
      {
        if ($msg->method == 'DELETE')
          $ret = (new Wpt_user (['data' => $data]))->deletePicture ();
      }
      // ROUTE ping
      // Keep WS connection and database persistent connection alive
      elseif ($msg->route == 'ping')
      {
        $this->_ping ();
      }
      // ROUTE debug // PROD-remove
      // Debug // PROD-remove
      elseif ($msg->route == 'debug') // PROD-remove
      { // PROD-remove
        _debug ($data); // PROD-remove
      }// PROD-remove

      $ret['action'] = $action;

      // Boadcast results if needed
      if ($push)
      {
        $clients = ($action == 'chat') ? $this->openedWalls:$this->activeWalls;

        if (isset ($clients[$wallId]))
        {
          foreach ($clients[$wallId] as $_connId => $_userId)
            if ($_connId != $connId)
              $this->clients[$_connId]->conn->send (json_encode ($ret));
        }
      }

      // Respond to the sender
      $ret['msgId'] = $msg->msgId ?? null;
      $conn->send (json_encode ($ret));
    }
    // Internal wopits client (broadcast msg to all clients)
    else
    {
      switch ($msg->action)
      {
        case 'ping':
          $this->_ping ();
          break;
    
        case 'dump-all':
          $conn->send (
            "* activeWalls array:\n".
            print_r ($this->activeWalls, true)."\n".
            "* openedWalls array:\n".
            print_r ($this->openedWalls, true)."\n".
            "* chatUsers array:\n".
            print_r ($this->chatUsers, true)."\n"

          );
          break;

        case 'stat-users':
          $conn->send ("\n".
            '* Sessions: '.count($this->clients)."\n".
            '* Active walls: '.count($this->activeWalls)."\n".
            '* Opened walls: '.count($this->openedWalls)."\n".
            '* Current chats: '.count($this->chatUsers)."\n"
          );
          break;

        default:
          foreach ($this->clients as $_connId => $_client)
            $_client->conn->send ($_msg);
      }
    }
  }

  public function onClose (ConnectionInterface $conn)
  {
    $connId = $conn->resourceId;

    // Internal wopits client
    if (isset ($this->internals[$connId]))
    {
      unset ($this->internals[$connId]);
    }
    // Common wopits client
    elseif ( ($client = $this->clients[$connId] ?? null))
    {
      $client->loadContext ();
      $userId = $client->id;

      if ( ($wallId = $client->settings->activeWall ?? null) )
      {
        // Remove user's action from DB edit queue
        (new Wpt_editQueue())->removeUser ();

        // Remove user from local view queue
        $this->_unsetActiveWalls ($wallId, $connId); 
        $this->_unsetActiveWallsUnique ($wallId, $userId);

        $this->_pushWallsUsersCount ([$wallId]);
      }

      if (isset ($client->settings->openedWalls))
      {
        foreach ($client->settings->openedWalls as $_wallId)
          $this->_unsetOpenedWalls ($_wallId, $connId);
      }

      foreach ($client->openedChats as $_wallId => $_dum)
        unset ($this->chatUsers[$_wallId][$connId]);

      unset ($this->clients[$connId]);
      unset ($this->usersUnique[$userId]);

      //FIXME
      //https://github.com/ratchetphp/Ratchet/issues/662#issuecomment-454886034
      gc_collect_cycles ();

      $this->_log ($conn, 'info',
        "CLOSE connection (".count($this->clients)." connected clients)");
    }
  }

  public function onError (ConnectionInterface $conn, \Exception $e)
  {
    $this->_log ($conn, 'error', "ERROR {$e->getMessage()}");

    $conn->close ();
  }

  public function registerActiveWall ($connId, $userId,
                                      $oldSettings, $newSettings)
  {
    if ($oldSettings)
    {
      // Deassociate previous wall from user
      if ( ($oldWallId = $oldSettings->activeWall ?? null) )
      {
        $this->_unsetActiveWalls ($oldWallId, $connId);
        $this->_unsetActiveWallsUnique ($oldWallId, $userId);
      }
    }

    // Associate new wall to user
    if ( ($newWallId = $newSettings->activeWall ?? null) )
    {
      if (!isset ($this->activeWalls[$newWallId]))
      {
        $this->activeWalls[$newWallId] = [];
        $this->activeWallsUnique[$newWallId] = [];
      }

      $this->activeWalls[$newWallId][$connId] = $userId;
      $this->activeWallsUnique[$newWallId][$userId] = $connId;
    }

    $this->_pushWallsUsersCount ((!$oldSettings) ?
      [$newWallId] : [$oldWallId, $newWallId]);
  }

  public function registerOpenedWalls ($connId, $userId, 
                                       $oldSettings, $newSettings)
  {
    $haveOld = ($oldSettings && isset ($oldSettings->openedWalls));

    if ($haveOld)
    {
      foreach ($oldSettings->openedWalls as $_oldWallId)
        $this->_unsetOpenedWalls ($_oldWallId, $connId);

      foreach ($diff = array_diff ($oldSettings->openedWalls,
                                   $newSettings->openedWalls??[]) as $_wallId)
        $this->_unsetChatUsers ($_wallId, $connId);
    }

    // Associate new wall to user
    if (isset ($newSettings->openedWalls))
    {
      foreach ($newSettings->openedWalls as $_newWallId)
      {
        if (!isset ($this->openedWalls[$_newWallId]))
          $this->openedWalls[$_newWallId] = [];

        $this->openedWalls[$_newWallId][$connId] = $userId;
      }
    }

    if ($haveOld)
    {
      foreach ($diff = array_diff ($oldSettings->openedWalls,
                                   $newSettings->openedWalls??[]) as $_wallId)
      {
        $this->_unsetChatUsers ($_wallId, $connId);

        if (isset ($this->openedWalls[$_wallId]))
        {
          foreach ($this->openedWalls[$_wallId] as $_connId => $_userId)
          {
            if ( ($client = $this->clients[$_connId] ?? null) &&
                 isset ($this->chatUsers[$_wallId]))
              $client->conn->send (
                json_encode ([
                  'action' => 'chatcount',
                  'count' => count ($this->chatUsers[$_wallId]) - 1,
                  'wall' => ['id' => $_wallId]
                ]));
          }
        }
      }
    }
  }

  public function getQueryParams (ConnectionInterface $conn)
  {
    parse_str ($conn->httpRequest->getUri()->getQuery(), $params);

    return $params;
  }

  private function _ping ()
  {
    (new Wpt_dao ())->ping ();
  }

  private function _removeUserFromGroup ($args)
  {
    $Group = $args['obj'];
    $userId = $args['userId'];
    $wallIds = $args['wallIds'];

    $args['ret'] = $Group->removeUser (['userId' => $userId]);

    if (!isset ($args['ret']['error']))
    {
      foreach ($wallIds as $_wallId)
        if (!empty ($this->openedWalls[$_wallId]))
        {
          $_ret = $args['ret'];
          $_ret['action'] = 'unlinkeduser';
          $_ret['wall']['id'] = $_wallId;

          foreach ($this->openedWalls[$_wallId] as $_connId => $_userId)
            if ($_userId == $userId)
              $this->clients[$_connId]->conn->send (json_encode ($_ret));
        }
    }
  }


  private function _unsetChatUsers ($wallId, $connId)
  {
    if (isset ($this->chatUsers[$wallId][$connId]))
    {
      unset ($this->chatUsers[$wallId][$connId]);

      if (empty ($this->chatUsers[$wallId]))
        unset ($this->chatUsers[$wallId]);
    }
  }

  private function _unsetActiveWalls ($wallId, $connId)
  {
    if (isset ($this->activeWalls[$wallId][$connId]))
    {
      unset ($this->activeWalls[$wallId][$connId]);

      if (empty ($this->activeWalls[$wallId]))
        unset ($this->activeWalls[$wallId]);
    }
  }

  private function _unsetActiveWallsUnique ($wallId, $userId)
  {
    if (isset ($this->activeWallsUnique[$wallId][$userId]))
    {
      $remove = true;

      if (isset ($this->activeWalls[$wallId]))
      {
        foreach ($this->activeWalls[$wallId] as $_connId => $_userId)
        {
          if ($_userId == $userId)
          {
            $remove = false;
            break;
          }
        }
      }

      if ($remove)
      {
        unset ($this->activeWallsUnique[$wallId][$userId]);

        if (empty ($this->activeWallsUnique[$wallId]))
          unset ($this->activeWallsUnique[$wallId]);
      }
    }
  }

  private function _unsetOpenedWalls ($wallId, $connId)
  {
    if (isset ($this->openedWalls[$wallId][$connId]))
    {
      unset ($this->openedWalls[$wallId][$connId]);

      if (empty ($this->openedWalls[$wallId]))
        unset ($this->openedWalls[$wallId]);
    }
  }

  private function _pushWallsUsersCount ($diff)
  {
    foreach ($diff as $_wallId)
    {
      if (isset ($this->activeWalls[$_wallId]))
      {
        $usersCount = count ($this->activeWallsUnique[$_wallId]);

        foreach ($this->activeWalls[$_wallId] as $_connId => $_userId)
        {
          if ( ($client = $this->clients[$_connId] ?? null) )
            $client->conn->send (
              json_encode ([
                'action' => 'viewcount',
                'count' => $usersCount - 1,
                'wall' => ['id' => $_wallId]
              ]));
        }
      }
    }
  }

  private function _log (ConnectionInterface $conn, $type, $msg)
  {
    printf ("%s:%s [%s][%s] %s\n",
      $conn->httpRequest->getHeader("X-Forwarded-For")[0]??'localhost',
      $conn->resourceId,
      date('Y-m-d H:i:s'),
      strtoupper ($type),
      $msg);
  }
}

function _debug ($data) // PROD-remove
{ // PROD-remove
  error_log (print_r ($data, true)); // PROD-remove
} // PROD-remove

echo "wopits WebSocket server is listening on port ".WPT_WS_PORT."\n\n";

(IoServer::factory(
  new HttpServer(new WsServer(new Wopits())), WPT_WS_PORT))->run();
