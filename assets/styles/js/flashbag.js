
$.fn.flashNotification = function(method) {

    var methods = {
        init: function(options) {

            methods.settings = $.extend({}, $.fn.flashNotification.defaults, options);
            methods.settings["container"] = $(this).length ? $(this)[0] : undefined;

            methods.display(".alert");

            methods.listenIncomingMessages();
        },

        reset: function(options) {
            $(document).unbind('ajaxComplete');
        },

        /**
         * Listen to AJAX responses and display messages if they contain some
         */
        listenIncomingMessages: function() {

            $(document).ajaxComplete(function(event, xhr, settings) {

                // "application/json; charset=UTF-8" counts too.
                var type = xhr ? String(xhr.getResponseHeader("Content-Type") || "") : "";
                if (type.split(";")[0].trim() != "application/json")
                    return;

                // JSON.parse, not $.parseJSON: jQuery 4 (the version this
                // bundle is built with) no longer has it, and every JSON
                // answer - a like, a follow - threw here, so the messages
                // they carry were never shown.
                var data;
                try { data = JSON.parse(xhr.responseText); } catch (e) { return; }
                if (data && data.flashbag) {
                    var flashbag = data.flashbag;

                    var i;
                    if (flashbag.error) {
                        for (i = 0; i < flashbag.error.length; i++) {
                            methods.addError(flashbag.error[i]);
                        }
                    }

                    if (flashbag.success) {
                        for (i = 0; i < flashbag.success.length; i++) {
                            methods.addSuccess(flashbag.success[i]);
                        }
                    }

                    if (flashbag.warning) {
                        for (i = 0; i < flashbag.warning.length; i++) {
                            methods.addWarning(flashbag.warning[i]);
                        }
                    }

                    if (flashbag.info) {
                        for (i = 0; i < flashbag.info.length; i++) {
                            methods.addInfo(flashbag.info[i]);
                        }
                    }

                    methods.display(".alert");
                }
            });
        },

        addSuccess: function(message) {
            var flashMessageElt = methods.getBasicFlash(message).addClass('alert-success');

            methods.addToList(flashMessageElt);
        },

        addError: function(message) {
            var flashMessageElt = methods.getBasicFlash(message).addClass('alert-error');

            methods.addToList(flashMessageElt);
        },

        addWarning: function(message) {
            var flashMessageElt = methods.getBasicFlash(message).addClass('alert-warning');

            methods.addToList(flashMessageElt);
        },

        addInfo: function(message) {
            var flashMessageElt = methods.getBasicFlash(message).addClass('alert-info');

            methods.addToList(flashMessageElt);
        },

        getBasicFlash: function(message) {
            var flashMessageElt = $('<div></div>')
                .hide()
                .addClass('alert alert-dismissible fade show')
                .append($('<span class="message"></span>').html(message))
                .append(methods.getCloseButton())
            ;

            return flashMessageElt;
        },

        getCloseButton: function()
        {
            var closeButtonElt = $('<button></button>')
                .addClass('btn-close')
                .attr('aria-label', 'Close')
                .attr('onclick', "this.closest('.alert').remove()");

            return closeButtonElt;
        },

        addToList: function(flashMessageElt) {

            var message  = flashMessageElt.find(".message").text().trim();
            var messages = $('#flash-messages').find(".message").map(function(){ return $.trim($(this).text()); }).toArray();

            var index = messages.indexOf(message);
            while( index != -1) {

                $('#flash-messages').find(".message").parent()[index].remove();

                messages = $('#flash-messages').find(".message").map(function(){ return $.trim($(this).text()); }).toArray();
                index = messages.indexOf(message);
            }

            flashMessageElt.appendTo(methods.settings.container);

            if(methods.settings.scrollUp) window.scrollTo(0, 0);
        },

        display: function(flashMessageElt) {

            setTimeout(
                function() {

                    $(flashMessageElt).show(methods.settings.animation ? 'slow' : 0);
                    if(methods.settings.autoHide)
                        $(flashMessageElt).delay(methods.settings.hideDelay).hide(methods.settings.animation ? 'fast' : 0, function() { $(this).remove(); } );

                },
                500
            );
        }
    };

    // Method calling logic
    if (methods[method]) {
        return methods[ method ].apply(this, Array.prototype.slice.call(arguments, 1));
    } else if (typeof method === 'object' || ! method) {
        return methods.init.apply(this, arguments);
    } else {
        $.error('Method ' +  method + ' does not exist on jQuery.flashNotification');
    }
};

$.fn.flashNotification.defaults = {
    'hideDelay'         : 9500,
    'autoHide'          : true,
    'animate'           : true,
    'scrollUp'          : true
};

window.addEventListener('beforeunload', function(event) { $('#flash-messages').flashNotification('reset'); });
window.addEventListener('load', function(event) { $('#flash-messages').flashNotification('init'); });
