// A webpack loader for a package's own bundle that carries a stylesheet
// @import from another host - editorjs-code-highlight imports highlight.js's
// theme from cdnjs.cloudflare.com, and injects it with the rest of its styles
// on every page the editor's script is on. The @import is dropped: a page of
// the site loads nothing from anyone else. The theme is the bundle's own
// copy (css/highlight.js/, copied from the highlight.js package, BSD-3-Clause,
// its licence beside it), linked by form-type-editor.js only where an editor
// or a block of code is shown.
module.exports = function (source) {
    return source.replace(/@import\s+url\(\s*['"]?(?:https?:)?\/\/[^)'"]+['"]?\s*\)\s*;?/g, '');
};
