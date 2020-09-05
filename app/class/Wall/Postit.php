<?php

namespace Wopits\Wall;

require_once (__DIR__.'/../../config.php');

use Wopits\Helper;
use Wopits\Services\Task;
use Wopits\Wall;
use Wopits\User;

class Postit extends Wall
{
  private $cellId;
  private $postitId;

  public function __construct ($args = [], $ws = null)
  {
    parent::__construct ($args, $ws);

    $this->cellId = $args['cellId']??null;
    $this->postitId = $args['postitId']??null;
  }

  public function create ()
  {
    $ret = [];
    $dir = $this->getWallDir ();

    $r = $this->checkWallAccess (WPT_WRIGHTS_RW);
    if (!$r['ok'])
      return (isset ($r['id'])) ? $r :
        ['error_msg' =>
           _("You must have write access to perform this action.")];

    // Check for the col/row (it could have been removed while user was
    // creating the new post-it.
    $stmt = $this->prepare ('SELECT 1 FROM cells WHERE id = ?');
    $stmt->execute ([$this->cellId]);
    if (!$stmt->fetch ())
      return ['error_msg' => _("The row/column has been deleted!")];

    $data = [
      'cells_id' => $this->cellId,
      'width' => $this->data->width,
      'height' => $this->data->height,
      'item_top' => $this->data->item_top,
      'item_left' => $this->data->item_left,
      'classcolor' => $this->data->classcolor,
      'title' => $this->data->title,
      'content' => $this->data->content,
      'creationdate' => time ()
    ];

    try
    {
      $this->executeQuery ('INSERT INTO postits', $data);
      $this->postitId = $this->lastInsertId ();

      mkdir ("$dir/postit/".$this->postitId);

      $data['id'] = $this->postitId;
      $ret = ['wall' => [
        'id' => $this->wallId,
        'partial' => 'postit',
        'action' => 'insert',
        'postit' => $data
      ]];
    }
    catch (\Exception $e)
    {
      error_log (__METHOD__.':'.__LINE__.':'.$e->getMessage ());
      $ret['error'] = 1;
    }

    return $ret;
  }

  public function getPlugs ($all = false)
  {
    // Get postits plugs
    $stmt = $this->prepare ("
      SELECT item_start, item_end, label
      FROM postits_plugs
      WHERE ".(($all)?'walls_id':'item_start')." = ?");
    $stmt->execute ([($all)?$this->wallId:$this->postitId]);

    return $stmt->fetchAll ();
  }

  public function getPostit ()
  {
    $stmt = $this->prepare ("
      SELECT
        id, cells_id, width, height, item_top, item_left, classcolor,
        title, content, tags, creationdate, deadline, timezone, obsolete,
        attachmentscount
      FROM postits
      WHERE postits.id = ?");
    $stmt->execute ([$this->postitId]);

    return $stmt->fetch ();
  }

  public function getPostitAlertShift ()
  {
    $stmt = $this->prepare ("
      SELECT alertshift
      FROM postits_alerts
      WHERE postits_id = ? AND users_id = ?");
    $stmt->execute ([$this->postitId, $this->userId]);

    return ($r = $stmt->fetch ()) ? $r['alertshift'] : null;
  }

  public function checkDeadline ()
  {
    // Get all postits with a deadline, and associated alerts if available.
    $stmt = $this->query ('
      SELECT
        postits.id AS postit_id,
        postits.deadline AS postit_deadline,
        postits.title AS postit_title,
        users.id AS alert_user_id,
        users.email AS alert_user_email,
        users.fullname AS alert_user_fullname,
        postits_alerts.alertshift AS alert_shift,
        walls.id as wall_id
      FROM postits
        INNER JOIN cells ON cells.id = postits.cells_id
        INNER JOIN walls ON walls.id = cells.walls_id
        LEFT JOIN postits_alerts ON postits.id = postits_alerts.postits_id
        LEFT JOIN users ON postits_alerts.users_id = users.id
      WHERE postits.obsolete = 0
        AND deadline IS NOT NULL');

    $now = new \DateTime ();

    while ($item = $stmt->fetch ())
    {
      $deleteAlert = false;

      $dlEpoch = $item['postit_deadline'];
      $dl = new \DateTime("@{$dlEpoch}");
      $days = $dl->diff($now)->days;
      $hours = $dl->diff($now)->h;

      if ($hours)
       ++$days;

      if ($dlEpoch <= $now->format ('U'))
      {
        $this->exec ("
          UPDATE postits SET obsolete = 1 WHERE id = {$item['postit_id']}");

        if (!is_null ($item['alert_user_id']))
        {
          $deleteAlert = true;

          (new Task())->execute ([
            'event' => Task::EVENT_TYPE_SEND_MAIL,
            'method' => 'deadlineAlert_1',
            'userId' => $item['alert_user_id'],
            'email' => $item['alert_user_email'],
            'wallId' => $item['wall_id'],
            'postitId' => $item['postit_id'],
            'fullname' => $item['alert_user_fullname'],
            'title' => $item['postit_title']
          ]);
        }
      }
      elseif (!is_null ($item['alert_user_id']) &&
              $item['alert_shift'] >= $days)
      {
        $deleteAlert = true;

        (new Task())->execute ([
          'event' => Task::EVENT_TYPE_SEND_MAIL,
          'method' => 'deadlineAlert_2',
          'userId' => $item['alert_user_id'],
          'email' => $item['alert_user_email'],
          'wallId' => $item['wall_id'],
          'postitId' => $item['postit_id'],
          'fullname' => $item['alert_user_fullname'],
          'title' => $item['postit_title'],
          'days' => $days,
          'hours' => $hours
        ]);
      }

      if ($deleteAlert)
        $this->exec ("
          DELETE FROM postits_alerts
          WHERE postits_id = {$item['postit_id']}
            AND users_id = {$item['alert_user_id']}");
    }
  }

  public function addRemovePlugs ($plugs, $postitId = null)
  {
    if (!$postitId)
      $postitId = $this->postitId;

    $this
      ->prepare("
        DELETE FROM postits_plugs
        WHERE item_start = ? AND item_end NOT IN (".
          implode(",",array_map([$this, 'quote'], array_keys($plugs))).")")
      ->execute ([$postitId]);

    $stmt = $this->prepare ("
      INSERT INTO postits_plugs (
        walls_id, item_start, item_end, label
      ) VALUES (
        :walls_id, :item_start, :item_end, :label
      ) {$this->getDuplicateQueryPart (
           ['walls_id', 'item_start', 'item_end'])}
       label = :label_1");

    foreach ($plugs as $_id => $_label)
    {
      $this->checkDBValue ('postits_plugs', 'label', $_label);
      $stmt->execute ([
        ':walls_id' => $this->wallId,
        ':item_start' => $postitId,
        ':item_end' => $_id,
        ':label' => $_label,
        ':label_1' => $_label
      ]);
    }
  }

  public function deleteAttachment ($args)
  {
    $ret = [];
    $attachmentId = $args['attachmentId'];

    $r = $this->checkWallAccess (WPT_WRIGHTS_RW);
    if (!$r['ok'])
      return (isset ($r['id'])) ? $r : ['error' => _("Access forbidden")];

    try
    {
      $this->beginTransaction ();

      $stmt = $this->prepare ('
        SELECT link FROM postits_attachments WHERE id = ?');
      $stmt->execute ([$attachmentId]);
      $attach = $stmt->fetch ();

      $this
        ->prepare('DELETE FROM postits_attachments WHERE id = ?')
        ->execute ([$attachmentId]);

      $this
        ->prepare('
          UPDATE postits SET attachmentscount = attachmentscount - 1
          WHERE id = ?')
        ->execute ([$this->postitId]);
    
      $this->commit ();

      Helper::rm (WPT_ROOT_PATH.$attach['link']);
    }
    catch (\Exception $e)
    {
      $this->rollback ();

      error_log (__METHOD__.':'.__LINE__.':'.$e->getMessage ());
      $ret['error'] = 1;
    }

    return $ret;
  }
 
  public function updateAttachment ($args)
  {
    $ret = [];
    $attachmentId = $args['attachmentId'];

    $r = $this->checkWallAccess (WPT_WRIGHTS_RW);
    if (!$r['ok'])
      return (isset ($r['id'])) ? $r : ['error' => _("Access forbidden")];

    if (!empty ($this->data->title))
    {
      $stmt = $this->prepare ('
        SELECT 1 FROM postits_attachments
        WHERE postits_id = ? AND title = ? AND id <> ?');
      $stmt->execute ([$this->postitId, $this->data->title, $attachmentId]);
      if ($stmt->fetch ())
        return ['error_msg' => _("This title already exists.")];
    }

    try
    {
      $this->executeQuery ('UPDATE postits_attachments', [
        'title' => $this->data->title,
        'description' => $this->data->description,
      ],
      ['id' => $attachmentId]);
    }
    catch (\Exception $e)
    {
      error_log (__METHOD__.':'.__LINE__.':'.$e->getMessage ());
      $ret['error'] = 1;
    }

    return $ret;
  }

  public function addAttachment ()
  {
    $ret = [];
    $dir = $this->getWallDir ();
    $wdir = $this->getWallDir ('web');
    $currentDate = time ();

    $r = $this->checkWallAccess (WPT_WRIGHTS_RW);
    if (!$r['ok'])
      return (isset ($r['id'])) ? $r : ['error' => _("Access forbidden")];

    list ($ext, $content, $error) = $this->getUploadedFileInfos ($this->data);

    if ($error)
      $ret['error'] = $error;
    else
    {
      $rdir = 'postit/'.$this->postitId;
      $file = Helper::getSecureSystemName (
        "$dir/$rdir/attachment-".hash('sha1', $this->data->content).".$ext");
      $fname = basename ($file);

      $stmt = $this->prepare ('
        SELECT 1 FROM postits_attachments
        WHERE postits_id = ? AND link LIKE ?');
      $stmt->execute ([
        $this->postitId,
        '%'.substr($fname, 0, strrpos($fname, '.')).'%'
      ]);

      if ($stmt->fetch ())
        $ret['error_msg'] =
          _("The file is already linked to the sticky note.");
      else
      {
        file_put_contents (
          $file, base64_decode(str_replace(' ', '+', $content)));

        // Fix wrong MIME type for images
        if (preg_match ('/(jpe?g|gif|png)/i', $ext))
          list ($file, $this->data->item_type, $this->data->name) =
            Helper::checkRealFileType ($file, $this->data->name);

        $ret = [
          'postits_id' => $this->postitId,
          'walls_id' => $this->wallId,
          'users_id' => $this->userId,
          'creationdate' => $currentDate,
          'name' => $this->data->name,
          'size' => $this->data->size,
          'item_type' => $this->data->item_type,
          'link' => "$wdir/$rdir/".basename($file)
        ];

        try
        {
          $this->beginTransaction ();

          $this->executeQuery ('INSERT INTO postits_attachments', $ret);

          $ret['id'] = $this->lastInsertId ();

          $this
            ->prepare('
              UPDATE postits SET attachmentscount = attachmentscount + 1
              WHERE id = ?')
            ->execute ([$this->postitId]);
          
          $ret['icon'] = Helper::getImgFromMime ($this->data->item_type);
          $ret['link'] =
            "/api/wall/{$this->wallId}/cell/{$this->cellId}".
            "/postit/{$this->postitId}/attachment/{$ret['id']}";

          $this->commit ();
        }
        catch (\Exception $e)
        {
          $this->rollback ();

          error_log (__METHOD__.':'.__LINE__.':'.$e->getMessage ());
          $ret['error'] = 1;
        }
      }
    }

    return $ret;
  }

  public function getAttachment ($args)
  {
    $attachmentId = $args['attachmentId']??null;
    $ret = [];

    $r = $this->checkWallAccess (WPT_WRIGHTS_RO);
    if (!$r['ok'])
      return (isset ($r['id'])) ? $r : ['error' => _("Access forbidden")];

    // Return all postit attachments
    if (!$attachmentId)
    {
      $data = [];

      $stmt = $this->prepare ('
        SELECT
           postits_attachments.id
          ,postits_attachments.link
          ,postits_attachments.item_type
          ,postits_attachments.name
          ,postits_attachments.size
          ,postits_attachments.title
          ,postits_attachments.description
          ,users.id AS ownerid
          ,users.fullname AS ownername
          ,postits_attachments.creationdate
        FROM postits_attachments
          LEFT JOIN users
            ON postits_attachments.users_id = users.id
        WHERE postits_id = ?
        ORDER BY postits_attachments.creationdate DESC, name ASC');
      $stmt->execute ([$this->postitId]);

      while ($row = $stmt->fetch ())
      {
        $row['icon'] = Helper::getImgFromMime ($row['item_type']);
        $row['link'] =
          "/api/wall/{$this->wallId}/cell/{$this->cellId}/postit/".
          "{$this->postitId}/attachment/{$row['id']}";
        $data[] = $row;
      }

      $ret = ['files' => $data];
    }
    else
    {
      $stmt = $this->prepare ('
        SELECT * FROM postits_attachments WHERE id = ?');
      $stmt->execute ([$attachmentId]);

      // If the file has been deleted by admin while a user with readonly
      // access was taking a look at the attachments list.
      if ( !($data = $stmt->fetch ()) )
        $data = ['item_type' => 404];
      else
        $data['path'] = WPT_ROOT_PATH.$data['link'];

      Helper::download ($data);
    }

    return $ret;
  }

  public function addPicture ()
  {
    $ret = [];
    $dir = $this->getWallDir ();
    $wdir = $this->getWallDir ('web');

    $r = $this->checkWallAccess (WPT_WRIGHTS_RW);
    if (!$r['ok'])
      return (isset ($r['id'])) ? $r : ['error' => _("Access forbidden")];

    list ($ext, $content, $error) = $this->getUploadedFileInfos ($this->data);

    if ($error)
      $ret['error'] = $error;
    else
    {
      try
      {
        $rdir = 'postit/'.$this->postitId;
        $file = Helper::getSecureSystemName (
          "$dir/$rdir/picture-".hash('sha1', $this->data->content).".$ext");

        file_put_contents (
          $file, base64_decode (str_replace (' ', '+', $content)));

        if (!file_exists ($file))
          throw new \Exception (_("An error occured while uploading file."));

        list ($file, $this->data->item_type, $width, $height) =
          Helper::resizePicture ($file, 800, 0, false);

        $stmt = $this->prepare ('
          SELECT * FROM postits_pictures WHERE postits_id = ? AND link = ?');
        $stmt->execute ([$this->postitId, "$wdir/$rdir/".basename($file)]);

        $ret = $stmt->fetch ();

        if (!$ret)
        {
          $ret = [
            'postits_id' => $this->postitId,
            'walls_id' => $this->wallId,
            'users_id' => $this->userId,
            'creationdate' => time (),
            'name' => $this->data->name,
            'size' => filesize ($file),
            'item_type' => $this->data->item_type,
            'link' => "$wdir/$rdir/".basename($file)
          ];

          $this->executeQuery ('INSERT INTO postits_pictures', $ret);

          $ret['id'] = $this->lastInsertId ();
        }

        $ret['icon'] = Helper::getImgFromMime ($this->data->item_type);
        $ret['width'] = $width;
        $ret['height'] = $height;
        $ret['link'] =
          "/api/wall/{$this->wallId}/cell/{$this->cellId}".
          "/postit/{$this->postitId}/picture/{$ret['id']}";
      }
      catch (\ImagickException $e)
      {
        @unlink ($file);

        if ($e->getCode () == 425)
          return ['error' => _("The file type was not recognized.")];
        else
        {
          error_log (__METHOD__.':'.__LINE__.':'.$e->getMessage ());
          throw $e;
        }
      }
      catch (\Exception $e)
      {
        @unlink ($file);

        error_log (__METHOD__.':'.__LINE__.':'.$e->getMessage ());
        throw $e;
      }
    }

    return $ret;
  }

  public function getPicture ($args)
  {
    $picId = $args['pictureId'];

    $r = $this->checkWallAccess (WPT_WRIGHTS_RO);
    if (!$r['ok'])
      return (isset ($r['id'])) ? $r : ['error' => _("Access forbidden")];

    $stmt = $this->prepare ('SELECT * FROM postits_pictures WHERE id = ?');
    $stmt->execute ([$picId]);
    $data = $stmt->fetch ();

    $data['path'] = WPT_ROOT_PATH.$data['link'];

    Helper::download ($data);
  }

  public function deletePictures ($data)
  {
    $pics = (preg_match_all (
      "#/postit/\d+/picture/(\d+)#", $data->content, $m)) ? $m[1] : [];

    $stmt = $this->prepare ('
      SELECT id, link
      FROM postits_pictures
      WHERE postits_id = ?');
    $stmt->execute ([$data->id]);

    $toDelete = [];
    while ( ($pic = $stmt->fetch ()) )
    {
      if (!in_array ($pic['id'], $pics))
      {
        $toDelete[] = $pic['id'];
        Helper::rm (WPT_ROOT_PATH.$pic['link']);
      }
    }

    if (!empty ($toDelete))
      $this->exec ('
        DELETE FROM postits_pictures
        WHERE id IN ('.implode(',', $toDelete).')');
  }

  public function deletePostit ()
  {
    $ret = [];
    $dir = $this->getWallDir ();
    $newTransaction = (!\PDO::inTransaction ());

    $r = $this->checkWallAccess (WPT_WRIGHTS_RW);
    if (!$r['ok'])
      return (isset ($r['id'])) ? $r : ['error' => _("Access forbidden")];
    
    try
    {
      $this
        ->prepare('DELETE FROM postits WHERE id = ?')
        ->execute ([$this->postitId]);

      // Delete postit files
      Helper::rm ("$dir/postit/{$this->postitId}");
    }
    catch (\Exception $e)
    {
      error_log (__METHOD__.':'.__LINE__.':'.$e->getMessage ());

      if (\PDO::inTransaction ())
        throw $e;
      else
        $ret['error'] = 1;
    }

    return $ret;
  }
}
