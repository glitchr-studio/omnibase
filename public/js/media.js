/*
 * media:play - one thing sounds at a time (glitchr/omnibase, no dependency).
 *
 * The contract, for every player of a page - the audio bar, a video player,
 * a third-party embed, a plain <audio> or <video>:
 *
 *   1. A player that starts sounding dispatches `media:play` on `document`:
 *          detail: { player, kind?, id?, ... }     player: any object that is this player and no other
 *   2. A player that hears `media:play` from another player pauses itself.
 *
 * Nothing else is shared between players: no queue, no state, no interface.
 * This module is the two lines above written once:
 *
 *     const off = MediaPlay.join(player, () => player.pause());   // 2. pause when another starts
 *     MediaPlay.announce(player, { kind: 'audio', id: 12 });      // 1. when this one starts
 *     off();                                                      // the player is destroyed
 *
 * Plain elements need nothing: an <audio> or a <video> of the page that
 * starts with its sound on announces itself, and is paused when another
 * player starts. A muted one (a hero's silent loop) neither announces nor is
 * paused - until it is unmuted while playing, which announces it.
 * `data-media-play="off"` on an element, or on anything around it, leaves it
 * out altogether (a sound effect, a player that manages the element itself
 * and calls announce()).
 *
 *     MediaPlay.current      the player that sounded last, or null
 *
 * As a script: <script src="{{ asset('bundles/base/js/media.js') }}" defer></script>
 * Bundled:     import '../vendor/glitchr/omnibase/assets/media/media.js';   (window.MediaPlay)
 */
(function () {
    'use strict';
    if (typeof window === 'undefined' || window.MediaPlay) return;

    var EVENT = 'media:play';
    var players = [];   // [{player, pause}]
    var current = null;

    function isMedia(target) {
        return !!target && (target.tagName === 'AUDIO' || target.tagName === 'VIDEO');
    }

    /** Left out by data-media-play="off", on the element or around it. */
    function optedOut(element) {
        var holder = element.closest ? element.closest('[data-media-play]') : null;
        return !!holder && holder.getAttribute('data-media-play') === 'off';
    }

    function sounding(element) {
        return !element.paused && !element.muted && element.volume !== 0;
    }

    /** 1. This player starts sounding. */
    function announce(player, detail) {
        if (!player) return;
        current = player;
        var data = { player: player };
        if (detail) Object.keys(detail).forEach(function (key) { if (key !== 'player') data[key] = detail[key]; });
        document.dispatchEvent(new CustomEvent(EVENT, { detail: data }));
    }

    /** 2. Pause this player when another announces itself. Returns the function that leaves. */
    function join(player, pause) {
        var entry = { player: player, pause: pause };
        players.push(entry);
        return function () {
            var at = players.indexOf(entry);
            if (at >= 0) players.splice(at, 1);
            if (current === player) current = null;
        };
    }

    document.addEventListener(EVENT, function (event) {
        var starting = event.detail && event.detail.player;
        if (!starting) return;
        current = starting;

        players.slice().forEach(function (entry) {
            if (entry.player === starting) return;
            try { entry.pause(event.detail); } catch (e) { if (window.console) console.error(e); }
        });

        // The page's plain elements that sound, but the one starting.
        document.querySelectorAll('audio, video').forEach(function (element) {
            if (element === starting || optedOut(element) || !sounding(element)) return;
            if (players.some(function (entry) { return entry.player === element; })) return; // it joined: paused above
            element.pause();
        });
    });

    // A plain element that starts with its sound on, or gets its sound back while playing.
    function onMedia(event) {
        var element = event.target;
        if (!isMedia(element) || optedOut(element) || !sounding(element)) return;
        if (current === element && event.type === 'volumechange') return; // already the one sounding
        announce(element, { kind: element.tagName.toLowerCase() });
    }
    document.addEventListener('play', onMedia, true);
    document.addEventListener('volumechange', onMedia, true);

    window.MediaPlay = {
        EVENT: EVENT,
        announce: announce,
        join: join,
        get current() { return current; }
    };
})();
