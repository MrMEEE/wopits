<?php
  require_once (__DIR__.'/../class/Wpt_jQueryPlugins.php');
  $Plugin = new Wpt_jQueryPlugins ('shareWall');
  echo $Plugin->getHeader ();
?>

  let $_groupPopup,
      $_groupAccessPopup,
      $_usersSearchPopup;

  /////////////////////////// PRIVATE METHODS ///////////////////////////

  // METHOD _displaySection ()
  function _displaySection ($div, type, items)
  {
    let html = '';

    $div.parent().removeClass ("scroll");

    items.forEach ((item) =>
      {
        if (item.type == type)
          html += `<li data-id="${item.id}" data-type="${item.type}" data-name="${H.htmlQuotes(item.name)}" class="list-group-item list-group-item-action is-wall-creator"><div class="userscount" data-action="users-search" data-toggle="tooltip" title="${item.userscount} <?=_("users in this group")?>"><i class="fas fa-layer-group fa-fw"></i> <span class="wpt-badge">${item.userscount}</span></div> <span class="name">${item.name}</span> <span class="desc">${item.description||''}</span><button data-action="delete-group" type="button" class="close" data-toggle="tooltip" title="<?=_("Delete this group")?>"><i class="fas fa-trash fa-fw fa-xs"></i></button><button data-action="users-search" type="button" class="close" data-toggle="tooltip" title="<?=_("Manage users")?>"><i class="fas fa-user-friends fa-fw fa-xs"></i></button><button data-action="link-group" type="button" class="close" data-toggle="tooltip" title="<?=_("Link this group")?>"><i class="fas fa-plus-circle fa-fw fa-xs"></i></button></li>`;
      });

    $div.html (html);

    if (html)
    {
      $div.parent().addClass ("scroll");

      if ($div.find("li").length == 1)
        $div.parent().addClass ("one");
      else
        $div.parent().removeClass ("one");
    }
  }

  /////////////////////////// PUBLIC METHODS ////////////////////////////

  // Inherit from Wpt_forms
  Plugin.prototype = Object.create(Wpt_forms.prototype);
  Object.assign (Plugin.prototype,
  {
    // METHOD init ()
    init: function (args)
    {
      const plugin = this,
            $share = plugin.element;

      $_groupPopup = $("#groupPopup");
      $_groupAccessPopup = $("#groupAccessPopup");
      $_usersSearchPopup = $("#usersSearchPopup");

      $(document)
        .on("click", "#shareWallPopup .list-group-item button,"+
                     "#shareWallPopup .list-group-item div.userscount",
        function (e)
        {
          const $btn = $(this),
                $row = $btn.parent(),
                groupType = $row[0].dataset.type,
                action = $btn[0].dataset.action,
                id = $row[0].dataset.id;

          e.stopImmediatePropagation ();

          $btn.tooltip ("hide");

          $row.addClass ("active todelete");

          switch (action)
          {
            case "users-search":

              const groupId = $row[0].dataset.id,
                    delegateAdminId = $row[0].dataset.delegateadminid||0;

              $_usersSearchPopup.usersSearch ("reset", {full: true});

              $_usersSearchPopup[0].dataset.delegateadminid = delegateAdminId;
              $_usersSearchPopup[0].dataset.groupid = groupId;
              $_usersSearchPopup[0].dataset.grouptype = groupType;
    
              $_usersSearchPopup
                .find(".desc").html ("<?=_("Add or remove users in the group « %s ».")?>".replace("%s", "<b>"+$row[0].dataset.name+"</b>"));
    
              $_usersSearchPopup.usersSearch (
                "displayUsers",
                {
                  wallId: S.getCurrent("wall").wall ("getId"),
                  groupId: groupId,
                  groupType: groupType
                });
    
              H.openModal ($_usersSearchPopup);
              break;

            case "delete-group":

              var content =
               ($row.parent().hasClass ("gtype-<?=WPT_GTYPES['dedicated']?>"))?
                 `<?=_("Delete this group?")?>`:
                 `<?=_("If you delete this <b>generic</b> group, it will no longer be available for the current wall or for your other walls.<p/>Delete it anyway?")?>`;

              H.openConfirmPopover ({
                 item: $btn,
                 placement: "left",
                 title: `<i class="fas fa-trash fa-fw"></i> <?=_("Delete")?>`,
                 content: content,
                 cb_close: () =>
                   $share.find("li.list-group-item.active")
                     .removeClass ("active todelete"),
                 cb_ok: () => plugin.deleteGroup ()
               });
              break;

            case "unlink-group":

              $share.find(".nav-link.active").removeClass ("active");
              $share.find(".tab-content .tab-pane.active")
                .removeClass ("show active");

              $share.find(".nav-tabs .gtype-"+groupType).addClass ("active");
              $share.find(".tab-content #gtype-"+groupType).tab ("show");

              plugin.unlinkGroup ({id: id});
              break;

            case "link-group":

              H.openModal ($_groupAccessPopup);
              break;
          }
        });

      $(document)
        .on("click", "#shareWallPopup .list-group-item.is-wall-creator",
        function (e)
        {
          const $row = $(this);

          $row.addClass ("active todelete");

          plugin.openUpdateGroup ({
            groupId: $row[0].dataset.id,
            name: $row.find(".name").text(),
            description: $row.find(".desc").text()
          });
        });

      $share.find("button")
        .on("click", function (e)
        {
          const $btn = $(this),
                action = $btn[0].dataset.action;

          if (action == "add-gtype-<?=WPT_GTYPES['generic']?>" ||
              action == "add-gtype-<?=WPT_GTYPES['dedicated']?>")
            plugin.openAddGroup (action.match(/add\-gtype\-([^\-]+)/)[1]);
        });

      $_groupPopup.find(".btn-primary")
        .on("click", function (e)
        {
          const type = $(this)[0].dataset.type,
                groupId = $(this)[0].dataset.groupid,
                $inputs = $_groupPopup.find("input");

          e.stopImmediatePropagation ();

          if (!groupId)
          {
            $_groupPopup[0].dataset.noclosure = true;

            if (plugin.checkRequired ($inputs))
              plugin.createGroup (type, {
                name: $inputs[0].value.trim (),
                description: $inputs[1].value.trim()||""
              });
          }
          else if (plugin.checkRequired ($inputs))
          {
            plugin.updateGroup ({
              groupId: groupId,
              name: $inputs[0].value.trim (),
              description: $inputs[1].value.trim()||""
            });
          }
        });
    },

    openAddGroup: function (type)
    {
      let title,
          desc;

      if (type == <?=WPT_GTYPES['generic']?>)
      {
        title = "<?=_("Add a <b>generic</b> group")?>";
        desc =  "<?=_("This new group will also be available for all your other walls.")?>";
      }
      else
      {
        title = "<?=_("Add a <b>dedicated</b> group")?>";
        desc = "<?=_("This new group will be available only for the current wall.")?>";
      }

      H.cleanPopupDataAttr ($_groupPopup);

      $_groupPopup[0].dataset.action = "create";

      $_groupPopup[0].dataset.noclosure = true;

      $_groupPopup.find(".modal-title span").html (title);
      $_groupPopup.find(".desc").html (desc);

      $_groupPopup.find("button.btn-primary")[0].dataset.type = type;
      $_groupPopup.find("button.btn-primary").text ("<?=_("Create")?>");

      H.openModal ($_groupPopup);
    },

    openUpdateGroup: function (args)
    {
      H.cleanPopupDataAttr ($_groupPopup);

      $_groupPopup[0].dataset.action = "update";

      $_groupPopup[0].dataset.noclosure = true;

      $_groupPopup.find(".modal-title span").html (
        "<?=_("Update this group")?>");

      $_groupPopup.find("input")[0].value = args.name;
      $_groupPopup.find("input")[1].value = args.description||"";

      $_groupPopup.find("button.btn-primary")[0].dataset.groupid = args.groupId;
      $_groupPopup.find("button.btn-primary").text ("<?=_("Save")?>");

      H.openModal ($_groupPopup);
    },

    // METHOD open ()
    open: function ()
    {
      this.displayGroups ();
    },

    // METHOD linkGroup ()
    linkGroup: function (args)
    {
      const wallId = S.getCurrent("wall").wall ("getId"),
            $group = this.element.find("li.todelete"),
            data = {
              type:
                $group.parent().hasClass("gtype-<?=WPT_GTYPES['dedicated']?>") ?
                   <?=WPT_GTYPES['dedicated']?> : <?=WPT_GTYPES['generic']?>,
              access:
                $_groupAccessPopup.find("input[name='access']:checked").val ()
            };

      H.request_ws (
        "POST",
        "wall/"+wallId+"/group/"+$group[0].dataset.id+"/link",
        data,
        // success cb
        (d) =>
        {
          if (d.error_msg)
            H.raiseError (null, d.error_msg);
          else
            this.displayGroups ();
        });
    },

    // METHOD unlinkGroup ()
    unlinkGroup: function (args)
    {
      const wallId = S.getCurrent("wall").wall ("getId");

      H.request_ws (
        "POST",
        "wall/"+wallId+"/group/"+args.id+"/unlink",
        null,
        // success cb
        (d) =>
        {
          if (d.error_msg)
            H.raiseError (null, d.error_msg);
          else
            this.displayGroups ();
        });
    },

    // METHOD deleteGroup ()
    deleteGroup: function ()
    {
      const $group = this.element.find("li.todelete"),
            service = ($group[0].dataset.type == <?=WPT_GTYPES['dedicated']?>) ?
              "wall/"+S.getCurrent("wall").wall("getId")+
                "/group/"+$group[0].dataset.id :
              "group/"+$group[0].dataset.id

      H.request_ws (
        "DELETE",
        service,
        null,
        // success cb
        (d) =>
        {
          if (d.error_msg)
            H.raiseError (null, d.error_msg);
          else
            this.displayGroups ();
        });
    },

    // METHOD createGroup ()
    createGroup: function (type, args)
    {
      const service = (type == <?=WPT_GTYPES['dedicated']?>) ?
              "wall/"+S.getCurrent("wall").wall("getId")+"/group" :
              "group";

      H.request_ws (
        "PUT",
        service,
        args,
        // success cb
        (d) =>
        {
          if (d.error_msg)
            H.displayMsg ({type: "warning", msg: d.error_msg});
          else
          {
            this.displayGroups ();
            $_groupPopup.modal ("hide");
          }
        });
    },

    // METHOD updateGroup ()
    updateGroup: function (args)
    {
      H.request_ws (
        "POST",
        "group/"+args.groupId,
        args,
        // success cb
        (d) =>
        {
          if (d.error_msg)
            H.displayMsg ({type: "warning", msg: d.error_msg});
          else
          {
            this.displayGroups ();
            $_groupPopup.modal ("hide");
          }
        });
    },

    displayGroups: function ()
    {
      const $share = this.element,
            $wall = S.getCurrent ("wall"),
            $body = $share.find (".modal-body");

      H.request_ajax (
        "GET",
        "wall/"+S.getCurrent("wall").wall("getId")+"/group",
        null,
        // success cb
        (d) =>
        {
          if (d.error_msg)
            return H.raiseError (null, d.error_msg);
        
          const $div = $body.find (".list-group.attr");
          let html = '';

          if (d.in.length)
          {
            $wall[0].dataset.shared = 1;

            $div.parent().addClass ("scroll");

            d.in.forEach ((item) =>
              {
                const isDed = (item.type == <?=WPT_GTYPES['dedicated']?>),
                      typeIcon = (d.delegateAdminId) ? '' : `<i class="${isDed ? "fas fa-asterisk":"far fa-circle"} fa-xs"></i>`,
                      unlinkBtn = (d.delegateAdminId) ? '' : `<button data-action="unlink-group" type="button" class="close" data-toggle="tooltip" title="${isDed ? "<?=_("Unlink this dedicated group")?>" : "<?=_("Unlink this group")?>"}"><i class="fas fa-minus-circle fa-fw fa-xs"></i></button>`;
                html += `<li data-id="${item.id}" data-type="${item.type}" data-name="${H.htmlQuotes(item.name)}" data-delegateadminid=${d.delegateAdminId||0} class="list-group-item list-group-item-action${d.delegateAdminId?'':' is-wall-creator'}"><div class="userscount" data-action="users-search" data-toggle="tooltip" title="${item.userscount} <?=_("users in this group")?>">${H.getAccessIcon(item.access)}<span class="wpt-badge">${item.userscount}</span></div> <span class="name">${typeIcon}${item.name}</span> <span class="desc">${item.description||""}</span>${unlinkBtn}<button data-action="users-search" type="button" class="close" data-toggle="tooltip" title="<?=_("Manage users")?>"><i class="fas fa-user-friends fa-fw fa-xs"></i></button></li>`;
              });

            if (d.in.length == 1)
              $div.parent().addClass ("one");
            else
              $div.parent().removeClass ("one");
          }
          else
          {
            $wall[0].removeAttribute ("data-shared");
            $wall.find("thead th:eq(0)").html ("&nbsp;");

            html = (d.delegateAdminId) ?
              "<span class='nogroup'><?=_("You cannot manage any of the existing groups.")?></span>" :
              "<span class='nogroup'><?=_("No group is yet linked with this wall.")?></span>";
            $div.parent().removeClass ("scroll");
          }

          $div.html (html);

          if (!d.delegateAdminId)
          {
            $body.find(".delegate-admin-only").hide ();

            _displaySection ($body.find (".list-group.gtype-<?=WPT_GTYPES['dedicated']?>.noattr"), <?=WPT_GTYPES['dedicated']?>, d.notin);

            _displaySection ($body.find (".list-group.gtype-<?=WPT_GTYPES['generic']?>.noattr"), <?=WPT_GTYPES['generic']?>, d.notin);
  
            $body.find(".creator-only").show ();
          }
          else
          {
            $body.find(".creator-only").hide ();
            $body.find(".delegate-admin-only").show ();
          }

          H.enableTooltips (
            $share.find(".modal-body [data-toggle='tooltip']"));

          H.openModal ($share);
        });
    }

  });

  /////////////////////////// AT LOAD INIT //////////////////////////////

  $(function ()
    {
      const $plugin = $("#shareWallPopup");

      if ($plugin.length)
        $plugin.shareWall ();
    });

<?php echo $Plugin->getFooter ()?>
