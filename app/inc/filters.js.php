<?php

  require_once (__DIR__.'/../prepend.php');

  use Wopits\jQueryPlugin;

  $Plugin = new jQueryPlugin ('filters');
  echo $Plugin->getHeader ();

?>

/////////////////////////// PUBLIC METHODS ////////////////////////////

  // Inherit from Wpt_toolbox
  Plugin.prototype = Object.create(Wpt_toolbox.prototype);
  Object.assign (Plugin.prototype,
  {
    // METHOD init ()
    init ()
    {
      const plugin = this,
            $filters = plugin.element,
            tList = S.getCurrent("tag-picker").tagPicker ("getTagsList"),
            cList = $(".color-picker").colorPicker ("getColorsList");

      let tags = '';
      for (const t of tList)
        tags +=
          `<div><i class="fa-${t} fa-fw fas" data-tag="${t}"></i></div>`;

      let colors = '';
      for (const c of cList)
        colors += `<div class="${c}">&nbsp;</div>`;

      $filters
        //FIXME "distance" is deprecated -> is there any alternative?
        .draggable({distance:10})
        .resizable({
          handles: "all",
          autoHide: !$.support.touch
        })
        .append (`<button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h2><?=_("Filters")?></h2><div class="filters-items"><div class="tags"><h3><?=_("Tags:")?></h3>${tags}</div><div class="colors"><h3><?=_("Colors:")?></h3>${colors}</div></div>`);

      $filters.find(".close").on("click",
        function ()
        {
          plugin.hide ();            
        });

      $filters.find(".tags i").on("click",
        function (e)
        {
          $(this).parent().toggleClass ("selected");

          plugin.apply ();
        });

      $filters.find(".colors > div").on("click",
        function (e)
        {
          $(this).toggleClass ("selected");

          plugin.apply ();
        });
    },

    // METHOD hide ()
    hide ()
    {
      if (this.element.is (":visible"))
        $("#main-menu").find("li[data-action='filters'] a").click ();
    },

    hidePlugs ()
    {
      this.element.addClass ("plugs-hidden");
      S.getCurrent("wall").wall ("hidePostitsPlugs"); 
    },

    showPlugs ()
    {
      this.element.removeClass ("plugs-hidden");

      setTimeout (()=> S.getCurrent("wall").wall ("showPostitsPlugs"), 0);
    },

    // METHOD toggle ()
    toggle ()
    {
      const $filters = this.element,
            $wall = S.getCurrent ("wall");

      if ($filters.is (":visible"))
      {
        $filters
          .hide()
          .find(".tags div.selected,"+
                ".colors div.selected").removeClass ("selected");

        this.showPlugs ();
      }
      else
        $filters
          .css({top: "60px", left: "5px", display: "table"});

      this.apply ();
    },

    // METHOD reset ()
    reset ()
    {
      this.element.find(".selected").removeClass ("selected");

      this.apply ();
    },

    // METHOD apply ()
    apply ()
    {
      const plugin = this,
            $filters = plugin.element,
            $wall = S.getCurrent ("wall"),
            $postits = $wall.find(".postit"),
            $tags = $filters.find(".tags div.selected"),
            $colors = $filters.find(".colors div.selected");

      $wall.find(".postit")
        .removeClass("filter-display")
        .css ("visibility", "visible");

      if ($tags.length || $colors.length)
      {
        plugin.hidePlugs ();

        if ($tags.length)
          $tags.each (function ()
            {
              const tag =
                $(this).find("i").attr("class").match (/fa\-([^ ]+) /)[1];

              $wall.find(".postit[data-tags*=',"+tag+",']")
                .addClass("filter-display");
            });

        if ($colors.length)
          $colors.each (function ()
            {
              $wall.find(".postit."+this.className.split(" ")[0])
                .addClass("filter-display");
            });
 
        if ($wall.find(".postit.current:not(.filter-display)").length)
          $("#popup-layer").click ();

        $wall.find(".postit:not(.filter-display)").css ("visibility", "hidden");
      }
      else
        plugin.showPlugs ();
    }

  });

<?php echo $Plugin->getFooter ()?>
