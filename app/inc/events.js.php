$(function()
{
  "use strict";

  const $_confirmPopup = $("#confirmPopup"),
        $_walls = wpt_sharer.getCurrent ("walls");
  let unloadDone = false;

  // EVENT onbeforeunload
  window.onbeforeunload = function ()
    {
      // Nothing if unload has already been done
      if (unloadDone) return;

      unloadDone = true;

      $(".chatroom").each (function ()
        {
          const $chatroom = $(this);

          if ($chatroom.css("display") == "table")
            $chatroom.wpt_chatroom ("leave");
        });
    };

  // EVENT visibilitychange
  document.addEventListener ("visibilitychange", function ()
    {
      const isHidden = (document.visibilityState == "hidden");

      // Nothing if unload has already been done or hidden state
      if (isHidden && unloadDone) return;

      unloadDone = isHidden;

      $(".chatroom").each (function ()
        {
          const $chatroom = $(this);

          if ($chatroom.css("display") == "table")
            $chatroom.wpt_chatroom ((isHidden) ? "leave": "join");
        });
    });

  // EVENT resize on window
  if (!document.querySelector ("body.login-page"))
  {
    $(window)
      .on("resize orientationchange", function()
      {
        const $wall = wpt_sharer.getCurrent ("wall");
  
        wpt_fixMenuHeight ();
        wpt_fixMainHeight ();
  
        if ($wall.length)
        {
          const $zoom = $(".tab-content.walls"),
                $chatroom = wpt_sharer.getCurrent ("chatroom"),
                $filters = wpt_sharer.getCurrent ("filters"),
                $arrows = wpt_sharer.getCurrent ("arrows");

          // Fix plugs labels position
          $wall.wpt_wall ("repositionPostitsPlugs");
   
          // Fix chatroom position if it is out of bounds
          if ($chatroom.is (":visible"))
            $chatroom.wpt_chatroom ("fixPosition");
   
          // Fix chatroom position if it is out of bounds
          if ($filters.is (":visible"))
            $filters.wpt_filters ("fixPosition");

          if ($arrows.is(":visible"))
            $arrows.wpt_arrows ("reset");
   
          if ($zoom[0].dataset.zoomlevelorigin)
          {
            if ($zoom[0].dataset.zoomtype == "screen")
              $wall.wpt_wall ("zoom", {type:"screen"});
            else
            {
              $wall.wpt_wall ("zoom", {type: "="});
              $('.dropdown-menu li[data-action="zoom-screen"] a')
                .removeClass ("disabled");
            }
          }
     
          // Fix TinyMCE menu placement with virtual keyboard on touch devices
          if ($.support.touch && $("#postitUpdatePopup").is(":visible"))
            $(".tox-selected-menu").css ("top",
              ($(".tox-menubar")[0].getBoundingClientRect().bottom -
                document.body.getBoundingClientRect().bottom - 2)+"px");
        }
      });
  }

  // EVENT click on arrows tool (x)
  $(document).on("click", ".arrows .goto-box-x i", function (e)
    {
      const $btn = $(this),
            sleft = $_walls.scrollLeft ();

      e.stopImmediatePropagation ();

      if($btn[0].className.indexOf("right") != -1)
      {
        $_walls.scrollLeft (sleft +
          ($btn.hasClass("full-right") ? 100000 : 100));
      }
      else
        $_walls.scrollLeft (sleft -
          ($btn.hasClass("full-left") ? 100000 : 100));
    });

  // EVENT click on arrows tool (y)
  $(document).on("click", ".arrows .goto-box-y i", function (e)
    {
      const $btn = $(this),
            stop = $_walls.scrollTop ();

      e.stopImmediatePropagation ();

      if($btn[0].className.indexOf("up") != -1)
        $_walls.scrollTop (stop -
          ($btn.hasClass("full-up") ? 100000 : 100));
      else
        $_walls.scrollTop (stop +
          ($btn.hasClass("full-down") ? 100000 : 100));
    });

  // EVENT walls scroll
  let _timeoutScroll;
  $_walls.on("scroll", function()
    {
      const $wall = wpt_sharer.getCurrent ("wall");

      if ($wall.length)
      {
        const $arrows = wpt_sharer.getCurrent ("arrows");
        const $filters = wpt_sharer.getCurrent ("filters");

        if (!$wall.hasClass ("plugs-hidden"))
        {
          $wall.wpt_wall ("hidePostitsPlugs"); 
          $wall.addClass ("plugs-hidden");
        }

        // Fix postits plugs position
        if (!$filters || !$filters.hasClass ("plugs-hidden"))
        {
          clearTimeout (_timeoutScroll);
          _timeoutScroll = setTimeout (() =>
          {
            setTimeout (()=>
              {
                $wall.wpt_wall ("showPostitsPlugs");
                $wall.removeClass ("plugs-hidden");

              }, 0);

          }, 150);
        }

        // Fix arrows tool appearence
        if ($arrows.is (":visible"))
        {
          const bounding = $wall[0].getBoundingClientRect ();

          if ($_walls.scrollLeft() <= 0)
            $arrows.find(".goto-box-x i.left,.goto-box-x i.full-left")
              .addClass ("readonly");
          else
            $arrows.find(".goto-box-x i.left,.goto-box-x i.full-left")
              .removeClass ("readonly");
    
          if (bounding.right > (window.innerWidth ||
                                document.documentElement.clientWidth))
            $arrows.find(".goto-box-x i.right,.goto-box-x i.full-right")
              .removeClass ("readonly");
          else
            $arrows.find(".goto-box-x i.right,.goto-box-x i.full-right")
              .addClass ("readonly");
    
          if ($_walls.scrollTop() <= 0)
            $arrows.find(".goto-box-y i.up,.goto-box-y i.full-up")
              .addClass ("readonly");
          else
            $arrows.find(".goto-box-y i.up,.goto-box-y i.full-up")
              .removeClass ("readonly");
    
          if (bounding.bottom > (window.innerHeight ||
                                document.documentElement.clientHeight))
            $arrows.find(".goto-box-y i.down,.goto-box-y i.full-down")
              .removeClass ("readonly");
          else
            $arrows.find(".goto-box-y i.down,.goto-box-y i.full-down")
              .addClass ("readonly");
        }
      }
    });

  // EVENT click on main menu
  $(document).on("click", ".nav-link:not(.dropdown-toggle),"+
                          ".dropdown-item", wpt_closeMainMenu);

  // EVENT mousedown on walls tabs
  $(document).on("mousedown", ".nav-tabs.walls a.nav-link",
    function (e)
    {
      const close = $(e.target).hasClass ("close"),
            rename = (!close && $(this).hasClass ("active"));

      if (wpt_checkAccess ("<?=WPT_RIGHTS['walls']['admin']?>"))
      {
        if (rename)
          wpt_sharer.getCurrent("wall").wpt_wall (
            "openPropertiesPopup", {forRename: true});
      }

      if (!rename && !close)
      {
        const $chatroom = wpt_sharer.getCurrent ("chatroom");

        if ($chatroom)
          $chatroom.wpt_chatroom ("closeUsersTooltip");

        $("#settingsPopup").wpt_settings ("saveOpenedWalls",
          $(this).attr("href").split("-")[1]);
      }

    });

  // EVENT hidden.bs.tab on walls tabs
  $(document).on("hidden.bs.tab", ".walls a[data-toggle='tab']",
    function (e)
    {
      wpt_sharer.getCurrent ("walls").wpt_wall ("hidePostitsPlugs");
    });

  // EVENT shown.bs.tab on walls tabs
  $(document).on("shown.bs.tab", ".walls a[data-toggle='tab']",
    function (e)
    {
          // If we are massively restoring all walls, do nothing here
      if ($_walls.find(".wall[data-restoring]").length ||
          // If we are massively closing all walls, do nothing here
          wpt_sharer.get ("closingAll"))
        return;

      wpt_sharer.reset ();

      // New wall
      const $menu = $("#main-menu"),
            $wall = wpt_sharer.getCurrent ("wall");

      // Need wall
      if (!$wall.length) return;

      //FIXME $wall.wpt_wall ("showPostitsPlugs");

      // Reinit search plugin for the current wall
      $("#postitsSearchPopup").wpt_postitsSearch (
        "restore", $wall[0].dataset.searchstring||"");

      $wall.wpt_wall ("zoom", {type: "normal", "noalert": true});

      $("#walls")
          .scrollLeft(0)
          .scrollTop (0);

      // Manage chatroom checkbox menu
      const $chatroom = wpt_sharer.getCurrent ("chatroom"),
            chatRoomVisible = $chatroom.is (":visible");
      $menu
        .find("li[data-action='chatroom'] input")[0].checked = chatRoomVisible;
      if (chatRoomVisible)
      {
        $chatroom.wpt_chatroom ("removeAlert");
        $chatroom.wpt_chatroom ("setCursorToEnd");
      }

      // Manage filters checkbox menu
      $menu.find("li[data-action='filters'] input")[0].checked =
        wpt_sharer.getCurrent("filters").is (":visible");

      // Manage arrows checkbox menu
      const $arrows = wpt_sharer.getCurrent("arrows");
      $menu.find("li[data-action='arrows'] input")[0].checked =
        $arrows.is (":visible");
      $arrows.wpt_arrows ("reset");

      // Refresh wall if it has not just been opened
      if (!wpt_sharer.get ("newWall"))
        $wall.wpt_wall ("refresh");

      $wall.wpt_wall ("menu", {from: "wall", type: "have-wall"});

      $(window).trigger ("resize");
    });

  // CATCH <Enter> key on popups
  $(document).on("keypress", ".modal, .popover",
    function (e)
    {
      if (e.which == 13 && e.target.tagName == "INPUT")
      {
        const $popup = $(this);
        let $btn = $popup.find (".btn-primary");

        if (!$btn.length)
          $btn = $popup.find (".btn-success");

        if ($btn.length)
        {
          e.preventDefault ();
          $btn.trigger ("click");
        }
      }

    });

  // EVENT show.bs.modal on popups
  $(".modal").on("show.bs.modal",
    function(e)
    {
      const $popup = $(this),
            $dialog = $popup.find(".modal-dialog"),
            $postit = wpt_sharer.getCurrent ("postit"),
            modalsCount = $(".modal:visible").length;

      // If there is already opened modals
      if (modalsCount)
      {
        $dialog[0].dataset.toclean = 1;
        $dialog.addClass ("modal-sm shadow");
        $dialog.find("button.btn").addClass ("btn-sm");
      }
      else if ($dialog[0].dataset.toclean)
      {
        $dialog.find("button.btn").removeClass ("btn-sm");
        $dialog.removeClass ("modal-sm shadow");
        $dialog[0].removeAttribute ("data-toclean");
      }

      // Get postit color and set modal header color the same
      if (!modalsCount && $postit.length)
        $postit.wpt_postit ("setPopupColor", $(this));
      else
        $(this).find(".modal-header,.modal-title,.modal-footer").each (
          function ()
          {
            this.className = this.className.replace (/color\-[a-z]+/, "");
          });

      if (!$.support.touch)
        setTimeout (() => $popup.find("[autofocus]").focus (), 150);
    });  


  // EVENT hide.bs.modal on popups
  $(".modal").on("hide.bs.modal",
    function (e)
    {
      const $popup = $(this),
            $wall = wpt_sharer.getCurrent ("wall"),
            $postit = wpt_sharer.getCurrent("postit");

      // Need wall
      if (!$wall.length) return;

      switch (e.target.id)
      {
        case "postitUpdatePopup":

          const data = wpt_sharer.get ("postit-data");

          // Return if we are closing the postit modal from the confirmation
          // popup
          if (data && data.closing) return;

          const title = $("#postitUpdatePopupTitle").val (),
                content = tinymce.activeEditor.getContent (),
                cb_close = () =>
                  {
                    wpt_sharer.set ("postit-data", {closing: true});

                    //FIXME
                    $(".tox-toolbar__overflow").hide ();
                    $(".tox-menu").hide ();
    
                    $popup.modal ("hide");
    
                    $popup.find("input").val ("");
                    $postit.wpt_postit ("unedit");

                    wpt_sharer.unset ("postit-data");
                  };

          // If there is pending changes, ask confirmation to user
          if (data && (data.title != title || data.content != content))
          {
            e.preventDefault ();

            data.cb_close = cb_close;
            data.cb_confirm = () =>
              {
                $postit.wpt_postit ("setTitle", title);
                $postit.wpt_postit ("setContent", content);
              };
            wpt_sharer.set ("postit-data", data);

            wpt_cleanPopupDataAttr ($_confirmPopup);
            $_confirmPopup.find(".modal-body").html (
              "<?=_("Save changes?")?>");
            $_confirmPopup.find(".modal-title").html (
              '<i class="fas fa-save fa-lg fa-fw"></i> <?=_("Changes")?>');

            $_confirmPopup[0].dataset.popuptype = "save-postits-changes";
            wpt_openModal ($_confirmPopup);
          }
          else
            cb_close ();
          break;
      }

    });

  // EVENT hidden.bs.modal on popups
  $(".modal").on("hidden.bs.modal",
    function(e)
    {
      const $popup = $(this),
            $wall = wpt_sharer.getCurrent ("wall"),
            type = $popup[0].dataset.popuptype,
            openedModals = $(".modal:visible").length,
            $postit = wpt_sharer.getCurrent("postit"),
            $header = wpt_sharer.getCurrent("header");

      // Prevent child popups from removing scroll to their parent
      if (openedModals)
        $("body").addClass ("modal-open");

      // Reload app
      if (e.target.id == "infoPopup" &&
            (type == 'app-upgrade' || type == 'app-reload'))
        return location.href = '/r.php?u';

      // Need wall
      if (!$wall.length) return;

      switch (e.target.id)
      {
        case "plugPopup":

          const from = wpt_sharer.get ("link-from");

          if (from)
            from.cancelCallback ();
          break;

        case "updateOneInputPopup":

          switch (type)
          {
            case "set-col-row-name":
              if (!wpt_sharer.get ("no-unedit"))
                $header.wpt_header ("unedit");

              wpt_sharer.unset ("no-unedit");
              break;
          }

          break;

        case "wallPropertiesPopup":

          if (wpt_checkAccess ("<?=WPT_RIGHTS['walls']['admin']?>") &&
              !$popup[0].dataset.uneditdone)
            $wall.wpt_wall ("unedit");
          break;

        case "postitViewPopup":

          $postit.wpt_postit ("unsetCurrent");
          break;

        case "postitAttachmentsPopup":

          wpt_fixDownloadingHack ();
          $postit.wpt_postit ("unedit");
          break;

        case "confirmPopup":
        case "usersSearchPopup":
        case "groupAccessPopup":
        case "groupPopup":

          switch (type)
          {
            case "save-postits-changes":

              wpt_sharer.get("postit-data").cb_close ();
              break;

            case "reload-app":

              const tz = $popup[0].dataset.popupoldtimezone;

              if (tz !== undefined)
                $("#settingsPopup select.timezone").val (tz);
              break;

            case "delete-wall":

              $wall.wpt_wall ("unedit");
              break;
          }

          $(".modal").find("li.list-group-item.active")
            .removeClass ("active todelete");

          break;
      }

    });

  // EVENT click on popup buttons
  $(".modal .modal-footer .btn").on("click",
    function (e)
    {
      const $popup = $(this).closest (".modal"),
            $wall = wpt_sharer.getCurrent ("wall"),
            type = $popup[0].dataset.popuptype,
            closePopup = !!!$popup[0].dataset.noclosure,
            $postit = wpt_sharer.getCurrent ("postit"),
            $header = wpt_sharer.getCurrent ("header");

      $popup[0].removeAttribute ("data-noclosure");

      if ($(this).hasClass ("btn-primary"))
      {
        switch ($popup.attr ("id"))
        {
          case "plugPopup":
            wpt_sharer.get ("link-from")
              .confirmCallback ($popup.find("input").val());
            break;

          case "postitUpdatePopup":

            $postit.wpt_postit("setTitle",$("#postitUpdatePopupTitle").val ());
            $postit.wpt_postit("setContent",tinymce.activeEditor.getContent());

            wpt_sharer.unset ("postit-data");
            break;

          case "groupAccessPopup":

            $("#shareWallPopup").wpt_shareWall ("linkGroup");
            break;

          case "groupPopup":

            if ($popup[0].dataset.action == "update")
              $popup[0].dataset.noclosure = true;
            break;

          // Upload postit attachment
          case "postitAttachmentsPopup":

            $popup[0].dataset.noclosure = true;
            $popup.find("input.upload").trigger ("click");
            break;

          // Manage all confirmations
          case "confirmPopup":

            switch (type)
            {
              case "logout":

                $("<div/>").wpt_login ("logout");
                break;

              case "save-postits-changes":

                wpt_sharer.get("postit-data").cb_confirm ();
                break;

              // Delete account
              case "delete-account":

                $("#accountPopup").wpt_account ("delete");
                break;

              // Delete profil photo
              case "delete-account-picture":

                $("#accountPopup").wpt_account ("deletePicture");
                break;

              // Manage application reload
              case "reload-app":

                if ($popup[0].dataset.popupnewlocale)
                  $("#settingsPopup").wpt_settings (
                    "applyLocale",
                    $popup[0].dataset.popupnewlocale
                  );
                else if ($popup[0].dataset.popupnewtimezone)
                  $("#settingsPopup").wpt_settings (
                    "applyTimezone",
                    $popup[0].dataset.popupnewtimezone
                  );
                break;

              // Manage walls close confirmation
              case "close-walls":

                $wall.wpt_wall ("closeAllWalls");
                break;

              // DELETE wall
              case "delete-wall":

                $wall.wpt_wall ("delete");
                break;
              }

            break;

          // Manage all one field UPDATE
          case "updateOneInputPopup":

            const val = wpt_noHTML ($popup.find("input").val());

            switch (type)
            {
              // UPDATE name of column/row
              case "set-col-row-name":

                wpt_sharer.set ("no-unedit", true);
                $header.wpt_header ("setTitle", val, true);
//                $header.wpt_header ("unedit");
                break;

              // Create new wall
              case "name-wall":

                if ((new Wpt_forms()).checkRequired ($popup.find("input")))
                  $("<div/>").wpt_wall ("addNew", {
                    name: val,
                    grid: $popup.find("#w-grid")[0].checked
                  }, $popup);
                break;
            }
            break;

          // UPDATE wall name and description
          //TODO wpt_wall() method
          case "wallPropertiesPopup":

            const name =
                    wpt_noHTML ($popup.find(".name input").val ()),
                  description =
                    wpt_noHTML ($popup.find(".description textarea").val ());

            $popup[0].dataset.noclosure = true;

            if ((new Wpt_forms()).checkRequired ($popup.find(".name input")))
            {
              const oldName = $wall.wpt_wall ("getName");

              $wall.wpt_wall ("setName", name);
              $wall.wpt_wall ("setDescription", description);
   
              $wall.wpt_wall ("unedit",
                () =>
                {
                  $popup[0].dataset.uneditdone = 1;
                  $popup.modal ("hide");
                },
                () =>
                {
                  $wall.wpt_wall ("setName", oldName);
                  //FIXME
                  $wall.wpt_wall ("edit");
                });
            }
            return;
        }
      }

      if (closePopup)
        $popup.modal ("hide");
    });

  // EVENT click on logout button
  $("#logout").on("click",
    function (e)
    {
      wpt_closeMainMenu ();

      wpt_cleanPopupDataAttr ($_confirmPopup);
      $_confirmPopup.find(".modal-body").html (
        "<?=_("Do you really want to logout from wopits?")?>");
      $_confirmPopup.find(".modal-title").html (
        '<i class="fas fa-power-off fa-lg fa-fw"></i><?=_("Logout")?>');

      $_confirmPopup[0].dataset.popuptype = "logout";
      wpt_openModal ($_confirmPopup);
    });

});
