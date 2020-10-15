<?php

  require_once (__DIR__.'/../prepend.php');

  $Plugin = new Wopits\jQueryPlugin ('cell', 'width: 300, height: 200');
  echo $Plugin->getHeader ();

?>

  /////////////////////////// PUBLIC METHODS ////////////////////////////

  Plugin.prototype =
  {
    // METHOD init ()
    init ()
    {
      const plugin = this,
            $cell = plugin.element,
            settings = plugin.settings,
            $wall = settings.wall,
            writeAccess =
              H.checkAccess ("<?=WPT_WRIGHTS_RW?>", settings.access);
      // Coords of touchstart on touch devices
      let _coords = null;

      $cell.addClass ($wall[0].dataset.displaymode);

      $cell.prepend ($(`<div class="cell-menu"><span class="btn btn-sm btn-secondary btn-circle"><i class="fas fa-list-ul fa-fw"></i></span></div>`)
        // EVENT click on cell menu
        .on("click", function ()
        {
          if (!S.get ("still-dragging"))
            $(this).parent().cell ("toggleDisplayMode");
        }));

      if (writeAccess)
        $cell
          // Make cell DROPPABLE
          .droppable ({
            accept: ".postit",
            tolerance: "pointer",
            scope:"dzone",
            classes: {"ui-droppable-hover" : "droppable-hover"},
            drop: function (e, ui)
              {
                if (S.get("revertData").revert) return;

                const $target = $(this),
                      $postit = ui.draggable,
                      ptop = ui.offset.top - $target.offset().top,
                      pleft = ui.offset.left - $target.offset().left;
  
                $postit.postit ("setPosition", {
                  cellId: settings.id,
                  top: (ptop < 0) ? 0 : ptop,
                  left: (pleft < 0) ? 0 : pleft
                });
  
                $postit.appendTo ($target);
  
                $target.cell ("reorganize");
              }
          });

       $cell
        // Make cell resizable
        .resizable ({
          disabled: !writeAccess,
          autoHide: false,
          ghost: true,
          minWidth: settings.width,
          minHeight: settings.height,
          helper: "resizable-helper",
          resize:function(e, ui)
            {
              if (S.get("revertData").revert)
              {
                $("body")[0].removeAttribute ("style");

                return false;
              }
            },
          start: function(e, ui)
            {
              const $editable = $wall.find (".editable");

              // Cancel all editable (blur event is not triggered on resizing).
              if ($editable.length)
                $editable.editable ("cancelAll");

              S.set ("revertData", {
                revert: false,
                size: {
                  width: $cell.outerWidth (),
                  height: $cell.outerHeight ()
                }
              });

              plugin.edit (()=> S.get("revertData").revert = true);
            },
          stop:function(e, ui)
            {
              const revertData = S.get ("revertData");

              if (revertData.revert)
              {
                $cell.css ({
                  width: revertData.size.width,
                  height: revertData.size.height
                });

                S.unset ("revertData");
              }
              else
              {
                $wall.wall("fixSize", ui.originalSize.width, ui.size.width + 3);

                plugin.update ({
                  width: ui.size.width + 3,
                  height: ui.size.height
                });

                // Set height/width for all cells of the current row
                $wall.find("tbody tr:eq("+$cell.parent().index ()+") td")
                  .each (function ()
                  {
                    const $c = $(this);

                    this.style.height = (ui.size.height + 2)+"px";
                    this.style.width = this.clientWidth+"px";

                    $c.find(">div.ui-resizable-e")[0]
                      .style.height = (ui.size.height + 2)+"px";
                    $c.find(">div.ui-resizable-s")[0]
                      .style.width = this.clientWidth+"px";
                  });

                $wall.find("tbody td").cell ("reorganize");

                plugin.unedit ();
              }
            }
        });

       if (writeAccess)
       {
         const __dblclick = (e)=>
         {
           if (e.target.tagName != 'TD' &&
               !e.target.classList.contains("cell-list-mode"))
                return e.stopImmediatePropagation ();

           const cellOffset = $cell.offset (),
                 pTop = ((_coords && _coords.changedTouches) ?
                   _coords.changedTouches[0].clientY :
                   e.pageY) - cellOffset.top,
                 pLeft = ((_coords && _coords.changedTouches) ?
                   _coords.changedTouches[0].clientX :
                   e.pageX) - cellOffset.left;

           _coords = null;

           $wall.wall ("closeAllMenus");

           if (S.getCurrent("filters").is (":visible"))
             S.getCurrent("filters").filters ("reset");

           plugin.addPostit ({
             access: settings.access,
             item_top: pTop,
             item_left: pLeft - 15
           });
         };

         // Touch devices
         if ($.support.touch)
           $cell
            // EVENT touchstart on cell to retrieve touch coords
            .on("touchstart", function (e)
            {
              _coords = e;

              // Fix issue with some touch devices
              $(".navbar-nav,.dropdown-menu").collapse ("hide");
            })
            // EVENT MOUSEDOWN on cell
            .doubletap (__dblclick);
          // No touch device
          else
            $cell.dblclick (__dblclick);
        }

        let w, h;

        if ($cell.hasClass("size-init"))
        {
          w = $cell.outerWidth();
          h = $cell.outerHeight ();
        }
        else
        {
          const $trPrev = $cell.parent().prev (),
                $tdPrev = ($trPrev.length) ?
                  $trPrev.find("td:eq("+($cell.index() - 1)+")") : undefined;

          w = $tdPrev ? $tdPrev.css ("width") : settings.width;
          h = $tdPrev ? $tdPrev.css ("height") : settings.height;
        }

        plugin.update ({width: w, height: h});
    },

    // METHOD setPostitsDisplayMode ()
    setPostitsDisplayMode (type)
    {
      const $cell = this.element,
            $displayMode = $cell.find (".cell-menu i");

      // If we must display list
      // list-mode
      if (type == "list-mode")
      {
        const cellWidth = $cell[0].clientWidth,
              cellHeight = $cell[0].clientHeight,
              postits = Array.from($cell[0].querySelectorAll(".postit"));

        $cell.removeClass("postit-mode").addClass ("list-mode");

        $cell.resizable ("disable");

        $displayMode[0].classList.replace ("fa-list-ul", "fa-sticky-note");

        let html = "";
        postits
          // Sort by postit id DESC
          .sort((a, b)=>b.dataset.id.substring(7) - a.dataset.id.substring(7))
          .forEach ((p)=>
          {
            const color = (p.className.match (/ color\-([a-z]+)/))[1],
                  postitPlugin = $(p).postit ("getClass"),
                  title = postitPlugin.element.find(".title").text ();

            postitPlugin.closeMenu ();
            postitPlugin.hidePlugs ();

            p.style.visibility = "hidden";

            html += `<li class="color-${color} postit-min" data-pid="${p.dataset.id}" data-tags="${p.dataset.tags}"><span></span>${title}</li>`;
          });

        $cell.find(".cell-menu").append (
          `<span class="wpt-badge">${postits.length}</span>`);
        $cell.prepend (
          `<div class="cell-list-mode"><ul style="max-width:${cellWidth}px;max-height:${cellHeight-1}px">${html}</ul></div>`);
      }
      // If we must display full postit
      // postit-mode
      else
      {
        $cell.removeClass("list-mode").addClass ("postit-mode");

        $cell.find(".cell-list-mode").remove ();
        $cell.find(".cell-menu .wpt-badge").remove ();

        $cell[0].querySelectorAll(".postit").forEach ((p)=>
          {
            p.style.visibility = "visible";

            $(p).postit ("showPlugs");
          });

        $displayMode[0].classList.replace ("fa-sticky-note", "fa-list-ul");

        $cell.resizable ("enable");
      }
    },

    // METHOD toggleDisplayMode ()
    toggleDisplayMode (forceList = false)
    {
      const $cell = this.element;

      if ($cell.hasClass ("postit-mode") || forceList)
      {
        if (forceList)
        {
          $cell.find(".cell-list-mode").remove ();
          $cell.find(".cell-menu .wpt-badge").remove ();
        }

        this.setPostitsDisplayMode ("list-mode");
      }
      else
        this.setPostitsDisplayMode ("postit-mode");

      // Re-apply filters
      if (S.getCurrent("filters").is (":visible"))
        S.getCurrent("filters").filters ("apply");
    },

    // METHOD removePostitsPlugs ()
    removePostitsPlugs ()
    {
      this.element[0].querySelectorAll(".postit.with-plugs").forEach (
        (p)=> $(p).postit ("removePlugs", true));
    },

    // METHOD getId ()
    getId ()
    {
      return this.settings.id;
    },

    // METHOD reorganize ()
    reorganize ()
    {
      this.element.each (function ()
      {
        const cell = this,
              bbox = cell.getBoundingClientRect ();

        this.querySelectorAll(".postit").forEach (
          (postit) => $(postit).postit ("fixPosition",
                                        bbox,
                                        cell.clientHeight,
                                        cell.clientWidth));
      });
    },

    // METHOD serialize ()
    serialize ()
    {
      const cells = [];

      S.getCurrent("wall")[0].querySelectorAll("tbody td").forEach ((cell)=>
      {
        const $postits = $(cell).find (".postit"),
              bbox = cell.getBoundingClientRect ();

        cells.push ({
          id: cell.dataset.id.substring (5),
          width: Math.trunc (bbox.width),
          height: Math.trunc (bbox.height),
          item_row: cell.parentNode.rowIndex - 1,
          item_col: cell.cellIndex - 1,
          postits: $postits.length ? $postits.postit ("serialize") : null
        });
      });

      return cells;
    },

    // METHOD addPostit ()
    addPostit (args, noinsert)
    {
      const $cell = this.element,
            settings = this.settings,
            $postit = $("<div/>");

      args.wall = settings.wall;
      args.wallId = settings.wallId;
      args.cell = $cell;
      args.cellId = settings.id;
      args.plugsContainer = settings.plugsContainer;

      // CREATE post-it
      $postit.postit (args);

      // Add postit on cell
      $cell.append ($postit);

      this.reorganize ();

      // If we are refreshing wall and postit has been already created by
      // another user, do not add it again in DB
      if (!noinsert)
        $postit.postit ("insert");
      else if ($cell[0].classList.contains("postit-mode"))
        $postit.css ("visibility", "visible");
    },

    // METHOD update ()
    update (d)
    {
      const $cell = this.element,
            cell0 = $cell[0],
            chgH = (cell0.clientHeight + 1 != d.height),
            chgW = (cell0.clientWidth + 1 != d.width);

      if (chgH || chgW)
      {
        cell0.style.width = d.width+"px";
        cell0.style.height = d.height+"px";
 
        if (chgW)
          $cell.find(">div.ui-resizable-s").css ("width", d.width + 2);

        if (chgH)
        {
          $cell.closest("tr").find("th:first-child").css("height", d.height);
          $cell.find(">div.ui-resizable-e").css ("height", d.height + 2);
        }
      }
    },

    // METHOD edit ()
    edit (error_cb)
    {
      if (!this.settings.wall[0].dataset.shared)
        return;

      H.request_ws (
        "PUT",
        "wall/"+this.settings.wallId+"/editQueue/cell/"+this.settings.id,
        null,
        // success cb
        (d) => d.error_msg &&
                 H.raiseError (() => error_cb && error_cb (), d.error_msg)
      );
    },

    // METHOD unedit ()
    unedit ()
    {
      H.request_ws (
        "DELETE",
        "wall/"+this.settings.wallId+"/editQueue/cell/"+this.settings.id,
        {
          cells: this.serialize (),
          wall: {width: Math.trunc(this.settings.wall.outerWidth () - 1)}
        }
      );
    }
  };

  /////////////////////////// AT LOAD INIT //////////////////////////////

  $(function()
    {
      // EVENT click on postit min li
      $(document).on("click", ".cell-list-mode li", function (e)
        {
          const $cell = $(this).closest ("td");

          if (e.cancelable)
              e.preventDefault ();

          if (!S.get ("still-dragging"))
            $cell.find(".postit[data-id='"+this.dataset.pid+"']")
              .postit ("openPostit", $(this).find("span"));
        });
    });

<?php echo $Plugin->getFooter ()?>
