<?php
/**
  Javascript plugin - Display filters

  Scope: Wall
  Element: .filters
  Description: Manage filtering of notes display
*/

  require_once (__DIR__.'/../prepend.php');

  use Wopits\jQueryPlugin;

  $Plugin = new jQueryPlugin ('filters');
  echo $Plugin->getHeader ();

?>

let _body;

/////////////////////////// PUBLIC METHODS ////////////////////////////

  // Inherit from Wpt_toolbox
  Plugin.prototype = Object.create(Wpt_toolbox.prototype);
  Object.assign (Plugin.prototype,
  {
    // METHOD init ()
    init ()
    {
      const plugin = this,
            $filters = plugin.element;

      if (!_body)
      {
        let tags = "", colors = "";

        for (const t of S.getCurrent("tpick").tpick ("getTagsList"))
          tags +=
            `<div><i class="fa-${t} fa-fw fas" data-tag="${t}"></i></div>`;

        for (const c of $("#cpick").cpick ("getColorsList"))
          colors += `<div class="${c}">&nbsp;</div>`;

        _body = `<button type="button" class="btn-close"></button><h2><?=_("Filters")?></h2><div class="filters-items"><div class="tags"><h3><?=_("Tags:")?></h3>${tags}</div><div class="colors"><h3><?=_("Colors:")?></h3>${colors}</div></div>`;
      }

      $filters
        .draggable({
          distance: 10,
          cursor: "move",
          drag: (e, ui)=> plugin.fixDragPosition (ui),
          stop: ()=> S.set ("dragging", true, 500)
        })
        .resizable({
          handles: "all",
          autoHide: !$.support.touch
        })
        .append (_body);

      // EVENT "click" on close button
      $filters[0].querySelector(".btn-close")
        .addEventListener ("click", (e)=> plugin.hide ());

      // EVENT "click" on tags
      $filters[0].querySelector(".tags").addEventListener ("click", (e)=>
        {
          const el = e.target;

          if (el.tagName == "I")
          {
            if (H.disabledEvent ())
              return false;

            el.parentNode.classList.toggle ("selected");

            plugin.apply ();
          }
        });

      // EVENT "click" on colors
      $filters[0].querySelector(".colors").addEventListener ("click", (e)=>
        {
          const el = e.target;

          if (el.tagName == "DIV")
          {
            if (H.disabledEvent ())
              return false;

            el.classList.toggle ("selected");

            plugin.apply ();
          }
        });
    },

    // METHOD hide ()
    hide ()
    {
      if (this.element.is (":visible"))
        document.querySelector(`#main-menu li[data-action="filters"]`).click();
    },

    // METHOD hidePlugs ()
    hidePlugs ()
    {
      this.element.addClass ("plugs-hidden");
      S.getCurrent("wall").wall ("hidePostitsPlugs"); 
    },

    // METHOD showPlugs ()
    showPlugs ()
    {
      const wall = S.getCurrent("wall").wall ("getClass");;

      this.element.removeClass ("plugs-hidden");

      setTimeout (()=>
        {
          wall.showPostitsPlugs ();

          if (wall.isShared ())
            wall.refresh ();
        }, 0);
    },

    // METHOD toggle ()
    toggle ()
    {
      const $filters = this.element;

      if ($filters.is (":visible"))
      {
        $filters
          .hide()
          .find(".tags div.selected,"+
                ".colors div.selected").removeClass ("selected");
        this.apply ();
      }
      else
      {
        $filters.css({top: "60px", left: "5px", display: "table"});
        this.apply ({norefresh: true});
      }
    },

    // METHOD reset ()
    reset ()
    {
      this.element.find(".selected").removeClass ("selected");

      this.apply ();
    },

    // METHOD apply ()
    apply (args = {})
    {
      const plugin = this,
            $filters = plugin.element,
            $wall = S.getCurrent ("wall"),
            $tags = $filters.find(".tags .selected"),
            $colors = $filters.find(".colors .selected");

      if ($tags.length || $colors.length)
      {
        plugin.hidePlugs ();
        S.getCurrent("mmenu").mmenu ("reset");

        $wall[0].querySelectorAll("td.wpt").forEach ((cell)=>
        {
          const $cell = $(cell),
                pclass = cell.classList.contains ("list-mode") ?
                           ".postit-min" : ".postit";

          $cell.find(pclass)
            .removeClass("filter-display")
            .css ("visibility", "visible");

          $tags.each (function ()
            {
              const tag =
                $(this).find("i").attr("class").match (/fa\-([^ ]+) /)[1];

              $cell.find(`${pclass}[data-tags*=",${tag},"]`)
                .addClass("filter-display");
            });

          $colors.each (function ()
            {
              $cell.find(`${pclass}.${this.className.split(" ")[0]}`)
                .addClass("filter-display");
            });

          $cell.find(`${pclass}:not(.filter-display)`)
            .css ("visibility", "hidden");
        });
      }
      else
      {
        $wall[0].querySelectorAll("td.wpt").forEach ((cell)=>
        {
          const $cell = $(cell);

          $cell.find(cell.classList.contains ("list-mode")?
                       ".postit-min":".postit")
            .removeClass("filter-display")
            .css ("visibility", "visible");
        });

        if (!args.norefresh)
          plugin.showPlugs ();
      }
    }
  });

<?php echo $Plugin->getFooter ()?>
