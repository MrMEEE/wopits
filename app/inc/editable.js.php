<?php
  require_once (__DIR__.'/../class/Common.php');

  $Plugin = new Wopits\jQueryPlugin ('editable');
  echo $Plugin->getHeader ();
?>

  let _editing = false;

  /////////////////////////// PRIVATE METHODS ///////////////////////////

  // METHOD _clearSelection ()
  function _clearSelection ()
  {
    window.getSelection && window.getSelection().removeAllRanges () ||
      document.selection && document.selection.empty ();
  }

  // METHOD _getTextWidth ()
  function _getTextWidth (str, fontSize)
  {
    let ret = 0;

    if (str != "")
    {
      const sb = S.getCurrent("sandbox")[0];

      if (fontSize)
        sb.style.fontSize = fontSize;
      else
        sb.removeAttribute ("style");

      sb.innerText = str;

      ret = (sb.clientWidth+30)+"px";
    }

    return ret;
  }

  /////////////////////////// PUBLIC METHODS ////////////////////////////

  Plugin.prototype =
  {
    // METHOD init ()
    init (args)
    {
      const plugin = this,
            editable = plugin.element[0],
            settings = plugin.settings,
            cb = settings.callbacks;

      editable.classList.add ("editable");
      settings._timeoutEditing = 0;
      settings._intervalBlockEditing = 0;

      settings.container
        .on("click", function (e)
        {
          // Cancel if current relationship creation.
          if (S.get("link-from"))
            return false;

          if (!settings._intervalBlockEditing &&
              !editable.classList.contains ("editing") &&
              settings.triggerTags
                .indexOf (e.target.tagName.toLowerCase ()) != -1)
          {
            settings._intervalBlockEditing = setInterval (() =>
            {
              if (!_editing)
              {
                clearInterval (settings._intervalBlockEditing);
                settings._intervalBlockEditing = 0;

                cb.edit (()=>
                {
                  _editing = true;
                  plugin.disablePlugins (true);

                  settings._valueOrig = editable.innerText;

                  editable.classList.add ("editing");
                  editable.style.height = editable.clientHeight+"px";

                  editable.innerHTML = `<div style="visibility:hidden;height:0">${H.htmlEscape(settings._valueOrig)}</div><input type="text" value="${H.htmlEscape(settings._valueOrig)}" maxlength="${settings.maxLength}">`;

                  settings._input = editable.querySelector ("input");

                  cb.before && cb.before (plugin, settings._input.value);

                  plugin.resize ();

                  $(settings._input)
                    .focus()
                    .on("blur", function (e)
                    {
                      const title = this.value;

                      e.stopImmediatePropagation ();

                      editable.classList.remove ("editing");
                      editable.removeAttribute ("style");
                      this.remove ();

                      if (title != settings._valueOrig)
                        cb.update (title);
                      else
                      {
                        editable.innerText = title;
                        cb.unedit ();
                      }

                      _clearSelection ();

                      clearTimeout (settings._timeoutEditing);
                      _editing = false;
                      plugin.disablePlugins (false);
                    })
                    .on("keyup", function (e)
                    {
                      const k = e.which;

                      // ENTER Validate changes
                      if (k == 13)
                        this.blur ();
                      // ESC Cancel edition
                      else if (e.which == 27)
                      {
                        this.value = settings._valueOrig;
                        this.blur ();
                      }
                      // Exclude some control keys
                      else if (
                        k != 9 &&
                        (k < 16 || k > 20) &&
                        k != 27 &&
                        (k < 35 || k > 40) &&
                        k != 45 &&
                        // CTRL+A
                        !(e.ctrlKey && k == 65) &&
                        // CTRL+C
                        !(e.ctrlKey && k == 67) &&
                        // CTRL+V (managed by "paste" event)
                        !(e.ctrlKey && k == 86) &&
                        (k < 144 || k > 145))
                      {
                        plugin.resize ();
                      }
                    })
                    .on("paste", function (e)
                    {
                      plugin.resize (
                        e.originalEvent.clipboardData.getData ('text'));
                    })
                });
              }
            }, 250);
          }
        });
    },

    // METHOD setValue ()
    setValue (v)
    {
      this.settings._input.value = v;
    },

    // METHOD cancelAll ()
    cancelAll ()
    {
      this.element.each (function ()
      {
        $(this).editable ("cancel");
      });
    },

    // METHOD cancel ()
    cancel ()
    {
      if (this.element.hasClass ("editing"))
        $(this.settings._input).trigger ("blur");
    },

    // METHOD disablePlugins ()
    disablePlugins (type)
    {
      const settings = this.settings;
      let $plug;

      settings.wall.draggable ("option", "disabled", type);

      $plug = settings.container.closest (".ui-draggable");
      if ($plug.length)
        $plug.draggable ("option", "disabled", type);

      $plug = settings.container.closest ("ui-resizable");
      if ($plug.length)
        $plug.resizable ("option", "disabled", type);
    },

    // METHOD resize ()
    // We also pass the value to enable text pasting.
    resize (v)
    {
      const settings = this.settings,
            input = settings._input;

      input.style.width = _getTextWidth (v||input.value, settings.fontSize);

      // Commit change automatically if no activity since
      // 15s.
      clearTimeout (settings._timeoutEditing);
      settings._timeoutEditing = setTimeout (
        ()=> input.blur (), <?=WPT_TIMEOUTS['edit']*1000?>);
    }

  };

<?php echo $Plugin->getFooter ()?>
