/**
 * Support functions for Salsa WordPress plugin.
 *
 * @author Blake Schwendiman <blake.schwendiman@gmail.com>
 */
; var SalsaWP = function() {
  return {
    debug: true,
    connected: null,
    connected_uid: null,
    
    log: function(msg) {
      if (this.debug && window.console) {
        console.log(msg);
      }
    },
    
    displayError: function(section, message) {
      var err_id = '#salsa_errors_' + section;
      
      jQuery(err_id + ' p.salsa_errors_message').html(message);
      jQuery(err_id).show();
      
    },
    
    fbInit: function(api_key, xdr_path, cb) {
      api_key = jQuery.trim(jQuery('#facebook_api_key').val());
      var app_secret = jQuery.trim(jQuery('#facebook_app_secret').val());

      FB.init(api_key,
              xdr_path,
              {
                ifUserConnected: function (uid) {
                  SalsaWP.log('connected: ' + uid);
                  SalsaWP.connected_uid = uid;
                  SalsaWP.connected = true;
                  
                  if (cb) {
                    cb(true, uid);
                  }
                },
                ifUserNotConnected: function(uid) {
                  SalsaWP.log('not connected: ' + uid);
                  SalsaWP.connected_uid = uid;
                  SalsaWP.connected = false;
                  
                  if (cb) {
                    cb(false, null);
                  }
                }
               });
      
    },
    
    /**
     * call FB connect function only after ensuring that the libary is
     * properly initialized
     *
     * @param function callback
     */
    fbSafeCall: function(cb) {
      if (!this.initialized) {
        this.fbInit();
      }
      
      if (cb) {
        return FB.ensureInit(cb);
      }
      
      return null;
    },
    
    intConnect: function(cb_success, cb_fail) {
      this.fbSafeCall(function() {
        SalsaWP.log('requireSession');
        FB.Connect.requireSession(
          function() {
            if (cb_success) {
              SalsaWP.log('success connect');
              cb_success();
            }
          },
          function() {
            if (cb_fail) {
              SalsaWP.log('fail connect');
              cb_fail();
            }
          });
      });
    },
    
    fbPromptPermission: function(permission, cb) {
      try {
        this.log('fbPromptPermission: ' + permission);
        this.fbSafeCall(function() {
          FB.Connect.showPermissionDialog(permission, cb, true);
        });
      } catch (ex) {
        this.log('exception in SalsaWP::fbPromptPermission: ' + ex.description);
      }
    },
    
    fbCheckAppPermission: function(permission, cb) {
      try {
        this.fbSafeCall(function() {
          SalsaWP.log('checking permission: ' + permission);
          FB.Facebook.apiClient.users_hasAppPermission(permission, function(result) {
            SalsaWP.log('permission status: ' + result);
            cb(result);
          });
        });
      } catch (ex) {
        this.log('exception in SalsaWP::fbCheckAppPermission: ' + ex.description);
      }
    },
    
    fbEnsurePublish: function() {
      this.fbCheckAppPermission('publish_stream', function(result) {
        if (result === 0) {
          SalsaWP.fbPromptPermission('publish_stream', function(result) {
            SalsaWP.log(result);
            if (result == 0) {
              SalsaWP.displayError('fb', 'You have not yet fully set up Facebook connect');
            } else {
              SalsaWP.displayError('fb', 'Everything is set up!');
            }
          });
        }
      });
    },
    
    fbConnect: function(xdr_path) {
      var api_key = jQuery.trim(jQuery('#facebook_api_key').val());
      var app_secret = jQuery.trim(jQuery('#facebook_app_secret').val());
      
      if ((api_key == '') || (app_secret == '')) {
        this.displayError('fb', 'You must enter a valid Facebook API key and application secret to connect.');
        return false;
      } else {
        this.fbInit(api_key, xdr_path, function(is_connected, fb_uid) {
          if (!is_connected) {
            SalsaWP.intConnect(function() {
              // success function
              SalsaWP.fbEnsurePublish();
            },
            function () {
              // fail function
              SalsaWP.displayError('fb', 'Unable to connect to Facebook.');
            });
          } else {
            SalsaWP.fbEnsurePublish();
          }
        });
      }
      
      return false;
    },
    
    __d: function() {}
  }
}();