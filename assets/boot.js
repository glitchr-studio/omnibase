/*
 * Base.boot(): what every omnibase site's app-defer.js used to repeat before
 * its own effects - @glitchr/stickyjs and @glitchr/transparentjs started in
 * the right order, and the back office opened as a website-in-website.
 * Both libraries stay what they are (two packages, two repositories); this
 * only starts them. Import the two first, then:
 *
 *     import '@glitchr/stickyjs';
 *     import '@glitchr/transparentjs';
 *     import Base from '../vendor/glitchr/omnibase/assets/boot.js';
 *
 *     Base.boot({
 *         nest: ['/admin*'],                        // opens as a floating panel over the page
 *         stops: { reach: 140, settle: 320 },       // StickyStops' options; false: none
 *         viewTransitions: true,                    // Transparent's use_view_transitions
 *         exceptions: ['/ressources/*\/telecharger'], // loads whole, after the defaults
 *         current: '.as-nav a',                     // links marked aria-current="page"
 *         transparent: { exit_duration: 420 }       // anything else for Transparent.ready()
 *     });
 *
 * On load: Sticky.ready() (its own snapping off: the stops settle the scroll),
 * StickyStops.attach(window, stops), Transparent.ready({identifier: '#content',
 * nest, ...}); after each navigation (transparent:load) the stops are attached
 * again and the current link marked. Also, once: jQuery's closestScrollable
 * made safe, the <style> blocks scripts inject into <head> kept across swaps
 * (data-headlock), and a site page that lands inside the back office's nest
 * frame goes to the main window instead.
 */
var EXCEPTIONS = ['/_*', '/login*', '/logout*', '/register*', '/locale/*', '/connect/*'];

function guardScrollable() {
    var $ = window.jQuery;
    if (!$ || !$.fn || !$.fn.closestScrollable || $.fn.closestScrollable.guarded) return;
    var closestScrollable = $.fn.closestScrollable;
    $.fn.closestScrollable = function () {
        try {
            var result = closestScrollable.apply(this, arguments);
            return (result && typeof result.prop === 'function') ? result : $(document.documentElement);
        } catch (e) { return $(document.documentElement); }
    };
    $.fn.closestScrollable.guarded = true;
}

function lockStyles(root) {
    (root.tagName === 'STYLE' ? [root] : root.querySelectorAll('style')).forEach(function (style) {
        if (!style.hasAttribute('data-headlock')) style.setAttribute('data-headlock', 'true');
    });
}

function leaveNest() {
    try {
        if (window.top !== window.self && window.frameElement && window.frameElement.closest('#transparent-nest')) {
            window.top.location.href = document.baseURI;
        }
    } catch (e) { /* a cross-origin frame: not a nest */ }
}

function markCurrent(selector) {
    if (!selector) return;
    var path = location.pathname.replace(/\/$/, '');
    document.querySelectorAll(selector).forEach(function (a) {
        var own = new URL(a.href, location.href).pathname.replace(/\/$/, '');
        if (own !== '' && (path === own || path.indexOf(own + '/') === 0)) a.setAttribute('aria-current', 'page');
        else a.removeAttribute('aria-current');
    });
}

function attachStops(stops) {
    if (stops && window.StickyStops) window.StickyStops.attach(window, stops === true ? undefined : stops);
}

var Base = window.Base || {};
Base.boot = function (options) {
    options = options || {};
    var stops = options.stops === undefined ? true : options.stops;
    leaveNest();
    guardScrollable();
    lockStyles(document.head);
    new MutationObserver(function (mutations) {
        mutations.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) lockStyles(n); }); });
    }).observe(document.head, { childList: true });

    window.addEventListener('transparent:load', function () {
        attachStops(stops);
        markCurrent(options.current);
    });

    // Once only: a repeated Transparent.ready() races itself.
    window.addEventListener('load', function () {
        guardScrollable();
        if (window.Sticky) window.Sticky.ready(Object.assign({ scrollsnap: false, autoscroll: false, swipehint: false }, options.sticky || {}));
        attachStops(stops);
        if (window.Transparent) window.Transparent.ready(Object.assign({
            identifier: '#content',
            exceptions: EXCEPTIONS.concat(options.exceptions || []),
            nest: options.nest || ['/admin*'],
            use_view_transitions: !!options.viewTransitions,
            headlock: ['_wdt', '_profiler'].concat(options.headlock || []),
            cache_version: window.APP_DEPLOY_VERSION,
            loader: '#loader',
            progress_bar: 'off'
        }, options.transparent || {}));
        markCurrent(options.current);
    }, { once: true });

    return Base;
};
window.Base = Base;
export default Base;
