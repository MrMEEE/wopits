<?php
/**
  Javascript plugin - Notes color picker

  Scope: Note
  Element: .cpick
  Description: Manage notes color
*/

  require_once (__DIR__.'/../prepend.php');

  $Plugin = new Wopits\jQueryPlugin ('cpick');
  echo $Plugin->getHeader ();

?>

  const _COLOR_PICKER_COLORS = [<?='"color-'.join ('","color-', array_keys (WPT_MODULES['cpick']['items'])).'"'?>];
  let _width = 0,
      _height = 0,
      _cb_close,
      _cb_click;

  /////////////////////////// PUBLIC METHODS ////////////////////////////

  Plugin.prototype =
  {
    // METHOD init ()
    init ()
    {
      const $picker = this.element;
      let html = "";

      _COLOR_PICKER_COLORS.forEach (
        (cls, i) => html += `<div class="${cls}">&nbsp;</div>`);

      $picker.append (html);

      H.waitForDOMUpdate (() =>
        {
          _width = $picker.outerWidth ();
          _height = $picker.outerHeight ();
        }); 
    },

    // METHOD getColorsList ()
    getColorsList ()
    {
      return _COLOR_PICKER_COLORS;
    },

    // METHOD open ()
    open (args)
    {
      const $picker = this.element,
            wW = $(window).outerWidth (),
            wH = $(window).outerHeight ();
      let x = args.event.pageX + 5,
          y = args.event.pageY - 20;
     
      if (x + _width > wW)
        x = wW - _width - 20;

      if (y + _height > wH)
        y = wH - _height - 20;

      _cb_close = args.cb_close;
      _cb_click = args.cb_click;

      // EVENT "click" on colors
      const _eventC = (e)=>
        {
          e.stopImmediatePropagation ();

          // Update background color
          _cb_click (e.target);

          // Remove color picker
          document.getElementById("popup-layer").click ();

          const $f = S.getCurrent ("filters");
          if ($f.is (":visible"))
            $f.filters ("apply", {norefresh: true});
        };
      $picker[0].removeEventListener ("click", _eventC);
      $picker[0].addEventListener ("click", _eventC);

      H.openPopupLayer (() =>
        {
          this.close ();

          if (S.getCurrent ("postit").length)
            S.getCurrent ("postit").postit ("unedit");
        });

      $picker
        .css({top: y, left: x})
        .show ();
    },

    // METHOD close ()
    close ()
    { 
      const $picker = this.element;

      if ($picker.length)
      {
        $picker.hide ();

        if (_cb_close)
          _cb_close ();
      }
    }

  };

  /////////////////////////// AT LOAD INIT //////////////////////////////

  document.addEventListener ("DOMContentLoaded",
    ()=> (!H.isLoginPage ()) && setTimeout (()=> $("#cpick").cpick (), 0));

<?php echo $Plugin->getFooter ()?>

