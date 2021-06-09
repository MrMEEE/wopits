<?php
/**
  Javascript plugin - Notes workers

  Scope: Note
  Element: .pwork
  Description: Manage notes workers
*/

  require_once (__DIR__.'/../prepend.php');

  $Plugin = new Wopits\jQueryPlugin ('pwork', '', 'postitElement');
  echo $Plugin->getHeader ();

?>

  let $_mainPopup,
      $_editPopup;

  /////////////////////////// PUBLIC METHODS ////////////////////////////

  // Inherit from Wpt_postitCountPlugin
  Plugin.prototype = Object.create(Wpt_postitCountPlugin.prototype);
  Object.assign (Plugin.prototype,
  {
    // METHOD init ()
    init (args)
    {
      const settings = this.settings;

      if (!args.shared || settings.readonly && !settings.count)
        this.element[0].style.display = "none";

      $(`<i data-action="pwork" class="fa-fw fas fa-users-cog"></i><span ${settings.count?"":`style="display:none"`} class="wpt-badge">${settings.count||0}</span></div>`).appendTo (this.element);

      return this;
    },

    // METHOD display ()
    display ()
    {
      const plugin = this,
            readonly = plugin.settings.readonly;

      H.loadPopup ("usearch", {
        open: false,
        template: "pwork",
        settings: {
          caller: "pwork",
          cb_add: ()=> plugin.incCount (),
          cb_remove: ()=> plugin.decCount ()
        },
        cb: ($p)=>
        {
          const _args = $p.usearch ("getIds");

          $p.usearch ("reset", {full: true,
                                readonly: !!plugin.settings.readonly});

          // Refresh counter (needed when some users have been deleted)
          _args.cb_after = (c)=> plugin.setCount(c);

          $p.usearch ("displayUsers", _args);

          H.openModal ($p);
        }
      });
    },

    // METHOD notifyNewUsers ()
    notifyNewUsers (ids)
    {
      const {wallId, cellId, postitId} = this.getIds ();

      H.request_ws (
        "PUT",
        `wall/${wallId}/cell/${cellId}/postit/${postitId}/notifyWorkers`,
        {ids, postitTitle: this.postit().getTitle()});
    },

    // METHOD open ()
    open (refresh)
    {
      const plugin = this;

      this.postit().edit ({}, ()=>
        {
          this.display ();
        });
    },
  });

  /////////////////////////// AT LOAD INIT //////////////////////////////

  if (!H.isLoginPage ())
    $(function()
      {
        setTimeout (()=>{

        // EVENT click on workers count
        $(document).on("click", ".pwork", function (e)
          {
            if (H.checkAccess ("<?=WPT_WRIGHTS_RW?>"))
              $(this).pwork ("open");
            else
            {
              $(this).closest(".postit").postit ("setCurrent");
              $(this).pwork ("display");
            }
          });

          // EVENT hidden on popup (only for devices without mouse)
          $(document).on ("hide.bs.modal", "#pworkPopup",  function (e)
            {
              if (S.get ("still-closing")) return;

              // INTERNAL FUNCTION __close ()
              const __close = ()=>
                {
                  S.getCurrent("postit").postit (
                    H.checkAccess ("<?=WPT_WRIGHTS_RW?>") ?
                      "unedit" : "unsetCurrent");
                };

              const $popup = $(this),
                    pwork = S.getCurrent("postit").postit("getPlugin", "pwork"),
                    newUsers = $popup.usearch ("getNewUsers");

              e.stopImmediatePropagation ();

              if (newUsers.length)
              {
                e.preventDefault ();

                H.openConfirmPopup ({
                  type: "notify-users",
                  icon: "save",
                  content: `<?=_("Notify new users?")?>`,
                  cb_ok: ()=> pwork.notifyNewUsers (newUsers),
                  cb_close: ()=>
                  {
                    S.set ("still-closing", true, 500);
                    $popup.modal ("hide");

                    __close ();
                  }
                });
              }
              else
                __close ();
            });

        }, 0);
      });

<?php echo $Plugin->getFooter ()?>