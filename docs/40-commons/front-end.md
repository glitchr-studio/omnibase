---
title: Front end
order: 48
---

# Glitch Art's signature

The same on every site: the year on two lines beside the pixel monkey (it
dances on hover), Source Code Pro bold 18pt/21px, linking to
https://www.glitchr.io. Its stylesheet (`bundles/base/css/credits.css`) is
linked by the partial.

```twig
{% include '@Base/partials/_credits.html.twig' %}                                   {# black #}
{% include '@Base/partials/_credits.html.twig' with {color: 'white', outline: true} %}
{% include '@Base/partials/_credits.html.twig' with {mirror: true, rule: true, site_name: 'Apfelschule'} %}
```

| Parameter | Values | |
|---|---|---|
| `color` | `black` (default), `white` | the text and the monkey; grey on hover. Never a colour of the site. |
| `outline` | `false`, `true` | a white contour, on a photo or a busy background |
| `mirror` | `false`, `true` | the detail line on the right of the signature instead of the left: for a signature set on the left of its footer |
| `rule` | `false`, `true` | a dotted rule between the detail's two lines |
| `detail` | `true`, `false` | the detail line at all |
| `site_name` | text | its second line |

The detail line (the address, the time the page took, the site's name) fades
in while the signature is pointed at or focused, and is always shown without a
pointer (`@media (hover: none)`). Filling it differently:

```twig
{% embed '@Base/partials/_credits.html.twig' with {color: 'black'} %}
    {% block credits_detail %}{{ parent() }}<span class="ga-credits-line" lang="de">Ein Apfel am Tag</span>{% endblock %}
{% endembed %}
```

The signature stands against the edge the site sets it on, whether its detail
is shown or not: where the detail fades in and out (`@media (hover: hover)`) it
holds no room and overflows on its own side. It is declared on the inner side:
the default (detail on the left) for a signature on the right of its footer,
`mirror: true` (detail on the right) for one on the left - else the detail
opens outwards, over the page's margin. Without a pointer the detail is always
shown and part of the block.

Nothing else is customisable: neither the font nor the colours.

# `Base.boot()`

What every site's `assets/app-defer.js` repeated: `Sticky.ready()`,
`StickyStops.attach()` and `Transparent.ready()` in that order, the stops
attached again after each navigation, the back office as a website-in-website.
`@glitchr/stickyjs` (^1.4) and `@glitchr/transparentjs` stay two libraries.

```js
import '@glitchr/stickyjs';
import '@glitchr/transparentjs';
import Base from '../vendor/glitchr/omnibase/assets/boot.js';

Base.boot({
    nest: ['/admin*'],                                  // default
    stops: { reach: 140, settle: 320, duration: 700 },  // StickyStops' options; true: its defaults; false: none
    viewTransitions: false,
    exceptions: ['/agenda.ics'],                        // after /_*, /login*, /logout*, /register*, /locale/*, /connect/*
    current: '.site-nav a',                             // aria-current="page" follows the page
    transparent: { exit_duration: 380 },                // anything else for Transparent.ready()
});
```

It also makes jQuery's `closestScrollable` safe, keeps the `<style>` blocks
scripts inject into `<head>` across swaps (`data-headlock`), and sends a site
page that lands inside the nest frame to the main window. The routes that
open in the nest answer `X-Transparent-Nest` (omnibase/admin's
`NestHeaderSubscriber`: every page under `/admin`, and any route with
`defaults: ['_nest' => true]`).

# `media:play`: one thing sounds at a time

A page may hold several players - the audio bar of omnibase/music, the video
player of omnibase/video, a film in its card, a third-party embed, a plain
`<audio>`. They share nothing but this contract:

1. a player that **starts sounding** dispatches `media:play` on `document`,
   with `detail: { player, kind?, id?, … }` - `player` is any object that is
   this player and no other;
2. a player that **hears** `media:play` from another player pauses itself.

No queue, no common state, no interface to implement: each player keeps its
own. `media.js` (no dependency) is those two lines written once:

```twig
<script src="{{ asset('bundles/base/js/media.js') }}" defer></script>
```

```js
import '../vendor/glitchr/omnibase/assets/media/media.js';     // bundled: the same script, window.MediaPlay

const leave = MediaPlay.join(player, () => player.pause());      // 2. paused when another starts
MediaPlay.announce(player, { kind: 'audio', id: track.id });     // 1. when this one starts
leave();                                                         // the player is destroyed
MediaPlay.current;                                               // the player that sounded last, or null
```

A script that does not load `media.js` takes part with the event alone:

```js
document.dispatchEvent(new CustomEvent('media:play', { detail: { player: me } }));
document.addEventListener('media:play', (event) => { if (event.detail.player !== me) pause(); });
```

Plain elements need nothing. An `<audio>` or a `<video>` that starts with its
sound on announces itself (`detail.player` is the element, `kind` its tag),
and is paused when another player starts. A **muted** one - a hero's silent
loop, a preview on hover - neither announces nor is paused; unmuted while
playing, it announces itself then. `data-media-play="off"` on an element, or
on anything around it, leaves it out (a sound effect; an element driven by a
player that calls `announce()` itself).

A third-party player that cannot be paused (an embed without an API) is
removed, or its frame reloaded, by the function given to `join()`; a player
whose pause fails does not keep the others from pausing.

# Stimulus `poll`

Ask a JSON address every few seconds and react when a value grows - a
kitchen board, a queue.

```js
// assets/bootstrap.js
import Poll from '../vendor/glitchr/omnibase/assets/controllers/poll_controller.js';
app.register('poll', Poll);
```

```twig
<div data-controller="poll" data-poll-url-value="{{ path('app_kitchen_state') }}"
     data-poll-key-value="latest" data-poll-since-value="{{ latest }}"
     data-poll-chime-value="true" data-poll-awake-value="true"
     data-action="poll:change->board#show">
    <button data-action="poll#arm">Activer les alertes</button>
    <button data-action="poll#silence">Vu</button>
</div>
```

The answer must hold the compared field (`{"latest": 42, ...}`). On a larger
value: `poll:change` (detail: the answer), the class `is-ringing`, and with
`chime` three notes every two seconds until `poll#silence`. `arm()` is the
touch browsers ask before a page may make sound; it also keeps the screen on
(`awake`). A hidden page is not polled.

# Nothing from elsewhere

A page of a site loads its scripts, stylesheets and fonts from the site
itself: no CDN sees the visitor (`tests/Http/NothingFromElsewhereHttpTest`
reads the contact page of the harness - which links `base` and `form` as a
site's layout does - and what its files pull in).

The editor's code blocks (editorjs-code-highlight) are coloured by
highlight.js's default theme. The package's own bundle `@import`s it from
cdnjs.cloudflare.com, and injects it with its styles on every page the
editor's script is on - every page with a form. That `@import` is dropped
at build (`assets/loaders/no-remote-import.js`); the theme is the bundle's
copy, `bundles/base/css/highlight.js/default.css` (highlight.js 11.6.0,
BSD 3-Clause, its licence beside it as `LICENSE.txt`), linked by the
editor's script once, and only on a page that shows an editor or a block of
code.

Still reaching elsewhere, and only where the field is: the emoji picker
(`EmojiPickerType`, entry `form.emoji`) fetches its data from jsDelivr.
