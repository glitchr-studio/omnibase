// A webpack loader for picmo: its emoji data and their labels come from
// jsDelivr (cdn.jsdelivr.net/npm/emojibase-data@<version>/<locale>/...), the
// files fetched, and asked for again (HEAD) to see whether they changed.
// form-type-emoji.js gives the picker the bundle's own copy (emoji/<locale>/,
// emojibase-data, MIT, its licence beside it), so that it fetches nothing;
// this points what remains of picmo's own fetching at that copy too: a page
// of the site reaches nobody else, whatever path picmo takes.
module.exports = function (source) {
    const { base } = this.getOptions();

    return source.replace(/https:\/\/cdn\.jsdelivr\.net\/npm\/emojibase-data@\$\{\w+\}\//g, base);
};
