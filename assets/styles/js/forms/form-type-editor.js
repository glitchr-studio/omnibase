import $ from 'jquery';
import EditorJs from '@editorjs/editorjs';
import Embed from '@editorjs/embed';
import Warning from '@editorjs/warning';
import NestedList from '@editorjs/nested-list';
import Checklist from '@editorjs/checklist';
import Alert from 'editorjs-alert';
import Table from '@editorjs/table';
import Marker from '@editorjs/marker';
import InlineCode from '@editorjs/inline-code';
import Underline from '@editorjs/underline';
import CodeTool from 'editorjs-code-highlight';
import Quote from '@editorjs/quote';
// import Undo from 'editorjs-undo';

import Header from 'editorjs-header';
import Paragraph from 'editorjs-paragraph';
import Mention from 'editorjs-mention';
import {ImageTool, ImageToolTune} from 'editorjs-image';

// editorjs-yjs (and, transitively, yjs/y-websocket) is loaded lazily, not
// as a static top-level import. This file's entry (form-defer.editor.js)
// and form-type-collab-presence.js's entry (the always-loaded
// form-defer.js) both depend on yjs, and Encore builds each entry as an
// independent bundle with no shared-chunk config between them - two
// static imports meant two separate copies of yjs's module code landing
// on the same page, and yjs's own module-identity check logged "Yjs was
// already imported. This breaks constructor checks..." the moment both
// were present (confirmed live on an article edit page). Loading
// editorjs-yjs only inside the collab_live branch below means its module
// code, and yjs's, never runs at all unless a field actually turns on
// collab_live.
function loadEditorYjs() {
    return import('editorjs-yjs').then(function (m) { return m.default || m; });
}

function json_decode(str) {
    try {
        return JSON.parse(str);
    } catch (e) {
        return undefined;
    }
}

function randid(length)
{
    let result = '';
    const characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    const charactersLength = characters.length;
    let counter = 0;
    while (counter < length) {
      result += characters.charAt(Math.floor(Math.random() * charactersLength));
      counter += 1;
    }
    return result;
}

// ── Optional debounced autosave and conflict guard ───────────────────────────
// This code uses no WebSocket relay. This code sends plain POST requests
// to ux_editorjs_autosave. Each field controls this feature through
// EditorType's "collab_autosave" option. The default value of this
// option is false. Refer to Base\Controller\UX\EditorController::
// Autosave() for the server-side code.
function collabAutosave(editor, holder, collab) {
    if (!collab || !collab.autosave) return null;

    var state = { version: collab.version || null, timer: null, pending: false, banner: null };

    function clearBanner() {
        if (state.banner) { state.banner.remove(); state.banner = null; }
    }

    // This function creates a minimal, self-contained conflict UI. This
    // code has no connection to a translation catalog yet. This code
    // uses plain text instead. A future update must add the translation
    // catalog connection.
    function showConflict(remoteBlocks, remoteVersion) {
        clearBanner();

        var banner = document.createElement("div");
        banner.className = "collab-conflict-banner";

        var text = document.createElement("span");
        text.className = "collab-conflict-banner__text";
        text.textContent = "Ce contenu a été modifié par quelqu'un d'autre depuis votre dernière lecture.";

        var restoreBtn = document.createElement("button");
        restoreBtn.type = "button";
        restoreBtn.className = "collab-conflict-banner__restore";
        restoreBtn.textContent = "Restaurer ma version";
        restoreBtn.addEventListener("click", function () {
            // This action keeps the local content. This action saves
            // the local content again, over the newer server version.
            // This action is an explicit overwrite. Only a direct user
            // action can trigger this overwrite.
            state.version = remoteVersion;
            clearBanner();
            doSave();
        });

        var suppressBtn = document.createElement("button");
        suppressBtn.type = "button";
        suppressBtn.className = "collab-conflict-banner__suppress";
        suppressBtn.textContent = "Accepter l'autre version";
        suppressBtn.addEventListener("click", function () {
            // This action accepts the incoming remote content. This
            // action replaces the local blocks with the remote blocks.
            // Autosave then continues on top of this new content.
            state.version = remoteVersion;
            clearBanner();
            if (remoteBlocks) editor.render(remoteBlocks);
        });

        banner.appendChild(text);
        banner.appendChild(restoreBtn);
        banner.appendChild(suppressBtn);

        holder.parentNode.insertBefore(banner, holder);
        state.banner = banner;
    }

    function scheduleSave() {
        if (state.timer) clearTimeout(state.timer);
        state.timer = setTimeout(doSave, 4000);
    }

    function doSave() {
        if (state.pending) return;
        state.pending = true;

        editor.save().then(function (savedData) {
            var body = JSON.stringify({
                token: collab.token,
                fqcn: collab.fqcn,
                id: collab.id,
                field: collab.field,
                locale: collab.locale,
                value: JSON.stringify(savedData),
                baseVersion: state.version,
            });

            fetch(collab.autosaveUrl, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: body,
            }).then(function (res) {
                return res.json().then(function (json) { return { status: res.status, json: json }; });
            }).then(function (result) {
                state.pending = false;

                if (result.status === 409 && result.json && result.json.conflict) {
                    showConflict(json_decode(result.json.value), result.json.version);
                    return;
                }

                if (result.json && result.json.version) {
                    state.version = result.json.version;
                    clearBanner();
                }
            }).catch(function () {
                state.pending = false;
            });
        });
    }

    return { scheduleSave: scheduleSave };
}

// ── Optional live collaboration (editorjs-yjs) ───────────────────────────────
// This function requests a ticket from ux_editorjs_collabTicket. This
// action is also the only source of the relay's WebSocket URL. Refer to
// Base\Service\Collab\CollabTicketFactory::getWsUrl(). Because of this,
// this code must fetch the ticket before it creates the EditorYjs
// instance. This order is necessary because the EditorYjs instance
// creates the presence Tune, and the presence Tune must exist before
// this code creates the EditorJs instance. This function returns a
// Promise. This Promise resolves to an object with this format:
// {wsUrl, ticket}. This Promise resolves to null in one case: the
// server has no collaboration configuration. In that case, the
// ux_editorjs_collabTicket action returns a 503 response, because no
// relay is deployed.
function fetchCollabTicket(collab) {
    return fetch(collab.ticketUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({ token: collab.token, room: collab.room }),
    }).then(function (res) {
        return res.json().then(function (json) {
            if (!res.ok || !json.success || !json.ticket) return null;
            return { wsUrl: json.wsUrl, ticket: json.ticket };
        });
    }).catch(function () {
        return null;
    });
}

$(window).off("DOMContentLoaded.edjs");
$(window).on("DOMContentLoaded.edjs", function() {

    $("[data-edjs]").each(function() {

        $(this).removeAttr("edjs");
        $(this).attr("id", $(this).attr("id") ?? "editorjs-"+randid(10));

        edjs(undefined, $(this).attr("id"), this.dataset.edjs);
    });
});

// highlight.js's theme for the code blocks: the bundle's own copy (css/highlight.js/, BSD-3-Clause,
// its licence beside it), linked once, and only on a page that shows an editor or a block of code -
// editorjs-code-highlight's own @import of it from cdnjs is dropped at build
// (assets/loaders/no-remote-import.js).
function codeStylesheet()
{
    if (document.querySelector("link[data-code-stylesheet]")) return;

    var link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = __webpack_public_path__ + "css/highlight.js/default.css";
    link.setAttribute("data-code-stylesheet", "");
    document.head.appendChild(link);
}

function showsCode(data)
{
    return !!data && Array.isArray(data.blocks) && data.blocks.some(function (block) { return block && block.type === "code"; });
}

function edjs(inputEl, holderId, value = {}, options = {})
{
    var holder = $("#"+holderId)[0] || undefined;
    if(holder == undefined) return;

    holder.innerHTML = ""; // delete existing editorjs instance

    var options  = JSON.parse(holder.getAttribute("data-editor-options")) || {};

    var endpointByFile    = holder.getAttribute("data-editor-upload-file") || undefined;
    var endpointByUrl     = holder.getAttribute("data-editor-upload-url")  || undefined;
    var endpointByUser    = holder.getAttribute("data-editor-endpoint-user")    || undefined;
    var endpointByThread  = holder.getAttribute("data-editor-endpoint-thread")  || undefined;
    var endpointByKeyword = holder.getAttribute("data-editor-endpoint-keyword") || undefined;
    
    var data = json_decode(value);
    if (data) Object.assign(options, {data:data});
    if (inputEl != undefined || showsCode(data)) codeStylesheet();
    
    var onSave = (savedData) => { if(inputEl != undefined) $(inputEl).val(JSON.stringify(savedData)); }

    if(inputEl == undefined) console.warn("EditorJS in read-only mode (some EventDispatcher .off() may appear)");
    Object.assign(options, {
        readOnly: (inputEl == undefined),
        tools: {

            warning: Warning,

            header: {
                class: Header,
                inlineToolbar: ['link', 'mention'],
            },

            paragraph: {

                class: Paragraph,
                inlineToolbar: true,
            },

            mention: {

                class: Mention,
                config: {

                    typingDelay:1000,
                    endpoints: {
                        'arobase': endpointByUser,
                        'hashtag': endpointByKeyword,
                        'dollar': endpointByThread
                    },
                }
            },
            
            imageTune: ImageToolTune,
            image: {
                class: ImageTool,
                tunes: [ 'imageTune' ],
                config: { 
                    accept: 'image/*',
                    endpoints: {
                        byFile: endpointByFile,
                        byUrl: endpointByUrl
                    },
                }
            },

            alert: Alert,
            underline: Underline,
            code: CodeTool,
            marker: {
                class: Marker,
                shortcut: 'CMD+SHIFT+M',
            },

            list: {
                class: NestedList,
                inlineToolbar: true,
            },

            quote: {
                class: Quote,
                inlineToolbar: true,
            },

            checklist: {
                class: Checklist,
                inlineToolbar: true,
            },

            table: {
                class: Table,
            },

            inlineCode: {
                class: InlineCode,
                shortcut: 'CMD+SHIFT+I',
            },

            embed: Embed,
        }
    });

    var collab = null; // This variable holds the collabAutosave state. Refer to the section above.

    // Live collaboration (editorjs-yjs) needs its ticket fetch to
    // complete first. This fetch is also the only source of the relay's
    // WebSocket URL. The presence Tune needs this ticket. The EditorJs
    // instance needs the presence Tune. Because of this order, this code
    // delays construction until the fetch completes, when the
    // collab_live option is active.
    function finishConstruction(collabYjs) {

        Object.assign(options, {

            holder  : holderId,
            onReady : () => {
                if(data == undefined && value != '') editor.blocks.renderFromHTML(value);
                // if(inputEl != undefined) new Undo({ editor }); // issue

                if(options.readOnly ?? false) {
                    $("#" + holderId).children(".codex-editor").addClass("read-only");
                }

                if (inputEl != undefined) collab = collabAutosave(editor, holder, options.collab);
                if (collabYjs) collabYjs.attach(editor, holderId);
            },
            onChange: async (api, event) => {

                if(options.readOnly) return;
                editor.save().then(onSave);
                if (collab) collab.scheduleSave();
                if (collabYjs) collabYjs.onChange(api, event);
            }
        });

        var editor = new EditorJs(options);

        // Keep the live instance reachable from the DOM. The per-field history
        // badge needs to REPLACE the content of an editor that is already
        // running, and rebuilding it from scratch is not equivalent when
        // collab_live is on - see the restore.form_type handler below.
        holder.__editorjs = editor;
    }

    if (options.collab && options.collab.live && inputEl != undefined) {
        Promise.all([loadEditorYjs(), fetchCollabTicket(options.collab)]).then(function (all) {
            var EditorYjs = all[0];
            var result = all[1];

            if (!result) {
                // The relay has no configuration, or the relay is not
                // reachable. In this case, this code uses plain,
                // non-collaborative editing instead. The editor must
                // still load.
                finishConstruction(null);
                return;
            }

            var collabYjs = new EditorYjs({
                wsUrl: result.wsUrl,
                room: options.collab.room,
                ticket: result.ticket,
                getTicket: () => fetchCollabTicket(options.collab).then(function (r) { return r ? r.ticket : null; }),
                user: options.collab.user || undefined,
            });

            options.tools.presence = { class: collabYjs.Tune };
            options.tunes = (options.tunes || []).concat(['presence']);

            finishConstruction(collabYjs);
        });
    } else {
        finishConstruction(null);
    }
}

// Restoring a previous value into a wysiwyg field (see form-type-history.js).
//
// EditorJS holds its content in its own instance, not in the input, so setting
// the input alone would be invisible in the editor AND overwritten by the next
// onChange. edjs() already rebuilds an editor from a value - it empties the
// holder and constructs a new EditorJs - so a restore is just that same call
// again with the old value, which is why this lives here rather than in the
// history module: edjs is deliberately private to this file.
window.addEventListener("restore.form_type", function (event) {

    var id = event.detail && event.detail.id;
    if (!id) return;

    var input = document.getElementById(id);
    var holder = document.getElementById(id + "_editor");
    if (!input || !holder) return; // not a wysiwyg field: let the generic setter have it

    var value = event.detail.value;
    value = (value === null || value === undefined) ? "" : String(value);

    var data = json_decode(value);
    var editor = holder.__editorjs;

    // Replace the content THROUGH the running editor rather than by rebuilding
    // it. On a collab_live field the text belongs to the shared Yjs document,
    // not to the `data` option: a rebuilt editor is re-synced from the room and
    // the restored value vanishes on the spot (observed - the badge reported
    // success while the editor still showed the current text). Going through
    // blocks.render() makes the restore an ordinary edit, which is also the
    // right collaborative semantics: everyone in the room sees it.
    if (editor && data && Array.isArray(data.blocks)) {

        Promise.resolve(editor.isReady)
            .then(function () { return editor.blocks.render({ blocks: data.blocks }); })
            .then(function () { return editor.save(); })
            .then(function (savedData) { $(input).val(JSON.stringify(savedData)); })
            .catch(function () {
                // Last resort for an editor that will not take the render:
                // rebuild it. Correct on a non-collab field, and no worse than
                // doing nothing on a collab one.
                $(input).val(value);
                edjs($(input), id + "_editor", value);
            });

    } else {
        $(input).val(value);
        edjs($(input), id + "_editor", value);
    }

    input.dispatchEvent(new Event("change", { bubbles: true }));
    event.preventDefault();
});

window.addEventListener("load.form_type", function (el) {

    document.querySelectorAll("[data-editor-field]").forEach((function (el) {

        var id    = el.getAttribute("data-editor-field");

        var input = $("#"+id);
        var value = $("#"+id).val();

        var editorId = id+"_editor";
        edjs(input, editorId, value);
    }));
});

// ── EditorJS image caption: empty-state flag (WYSIWYG) ───────────────────────
// CSS `:empty` can't tell that a caption the user cleared still holds a stray
// <br>, so it would treat a blank caption as filled and reserve a box below the
// image. We flag truly-empty captions (by trimmed text) with `caption-empty` so
// the stylesheet can overlay an empty caption ON the image (no layout gap) in the
// editor, hide it in the read-only viewer, and flow a filled one BELOW the image.
// Runs in both contexts because this file loads with both.
(function () {
    function markCaption(el) {
        if (el && el.classList && el.classList.contains('image-tool__caption')) {
            el.classList.toggle('caption-empty', (el.textContent || '').trim() === '');
        }
    }
    function markAll() {
        document.querySelectorAll('.image-tool__caption').forEach(markCaption);
    }
    document.addEventListener('input', function (e) { markCaption(e.target); }, true);
    document.addEventListener('DOMContentLoaded', markAll);
    window.addEventListener('load', markAll);
    if ('MutationObserver' in window) {
        var t;
        new MutationObserver(function () {
            clearTimeout(t);
            t = setTimeout(markAll, 100); // debounced: catch render + programmatic edits
        }).observe(document.documentElement, { subtree: true, childList: true });
    }
})();
