import { createPopup } from '@picmo/popup-picker';
import { autoTheme, darkTheme, lightTheme } from 'picmo';

// The emojis and their labels: the bundle's own copy (emoji/<locale>/data.json
// and messages.json, emojibase-data 17.0.0, MIT, its licence beside them), in
// the page's language when the bundle has it. Given to picmo, which then
// fetches nothing itself (it went to jsDelivr; assets/loaders/local-emoji-data.js).
// Fetched once a page, on the first click on a field.
const EMOJI_LOCALES = ["en", "fr", "de", "ja"];
let emojiDataset = null;

function emojiLocale()
{
    const language = (document.documentElement.lang || "en").toLowerCase().split(/[-_]/)[0];
    return EMOJI_LOCALES.includes(language) ? language : "en";
}

function loadEmojiDataset()
{
    if (emojiDataset) return emojiDataset;

    const locale = emojiLocale();
    const base = __webpack_public_path__ + "emoji/" + locale + "/";
    const json = (file) => fetch(base + file, { credentials: "same-origin" }).then(function (response) {
        if (!response.ok) throw new Error(base + file + ": " + response.status);
        return response.json();
    });

    emojiDataset = Promise.all([json("data.json"), json("messages.json")]).then(function ([emojiData, messages]) {
        return { locale: locale, emojiData: emojiData, messages: messages };
    });
    emojiDataset.catch(function () { emojiDataset = null; });

    return emojiDataset;
}

window.addEventListener("load.form_type", function () {

    document.querySelectorAll("[data-emoji-field]").forEach((function (el) {

        // "load.form_type" is dispatched globally on every lazy load (a
        // collection's "load more" elsewhere on the page, ...), and this
        // querySelectorAll is unscoped - without this guard, every re-fire
        // created another picmo popup AND another native click listener on
        // the same field (native addEventListener doesn't dedupe distinct
        // closures), so one click opened N independent popups at once.
        // Same class of bug already found and fixed for flatpickr in
        // form-type-datetimepicker.js.
        if (el.dataset.emojiInitialized) return;
        el.dataset.emojiInitialized = "1";

        var popupOptions = {
            triggerElement: el,
            referenceElement: el
        };

        let popup = null;
        el.addEventListener("click", () => {
            if (popup) {
                popup.then((picker) => picker.toggle());
                return;
            }

            popup = loadEmojiDataset().then(function (dataset) {
                const picker = createPopup({
                    theme: autoTheme,
                    locale: dataset.locale,
                    emojiData: dataset.emojiData,
                    messages: dataset.messages
                }, popupOptions);
                picker.addEventListener('emoji:select', event => { el.value = event.emoji; });
                return picker;
            });
            popup.then((picker) => picker.toggle(), () => { popup = null; });
        });
    }));
});
