// node --test "tests/js/*.test.mjs"   (needs jsdom)
//
// media:play - a player that starts announces itself on document, the others
// pause; plain <audio>/<video> elements take part by themselves.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const here = dirname(fileURLToPath(import.meta.url));
const script = readFileSync(join(here, '../../assets/media/media.js'), 'utf8');

function page(body = '') {
    const dom = new JSDOM(`<!doctype html><html><body>${body}</body></html>`, { runScripts: 'outside-only' });
    dom.window.eval(script);
    const { window } = dom;
    const heard = [];
    window.document.addEventListener('media:play', (event) => heard.push(event.detail));

    /** jsdom plays nothing: an element "plays" when the test says so. */
    const element = (id, { muted = false } = {}) => {
        const media = window.document.getElementById(id);
        let paused = true;
        Object.defineProperty(media, 'paused', { get: () => paused });
        media.muted = muted;
        media.pause = () => { paused = true; };
        media.start = () => { paused = false; media.dispatchEvent(new window.Event('play')); };
        return media;
    };

    return { window, document: window.document, MediaPlay: window.MediaPlay, heard, element };
}

test('the published script is its source', () => {
    assert.equal(readFileSync(join(here, '../../public/js/media.js'), 'utf8'), script);
});

test('a player that starts pauses the others, not itself', () => {
    const { MediaPlay, heard } = page();
    const bar = { name: 'bar', paused: 0 };
    const film = { name: 'film', paused: 0 };
    MediaPlay.join(bar, () => bar.paused++);
    const leave = MediaPlay.join(film, () => film.paused++);

    MediaPlay.announce(bar, { kind: 'audio', id: 12 });
    assert.deepEqual([bar.paused, film.paused], [0, 1]);
    assert.equal(heard[0].player, bar);
    assert.equal(heard[0].kind, 'audio');
    assert.equal(heard[0].id, 12);
    assert.equal(MediaPlay.current, bar);

    MediaPlay.announce(film);
    assert.deepEqual([bar.paused, film.paused], [1, 1]);

    leave();
    MediaPlay.announce(bar);
    assert.deepEqual([bar.paused, film.paused], [1, 1], 'a player that left is no longer paused');
});

test('any script may take part with the event alone', () => {
    const { document, window, MediaPlay } = page();
    const bar = { paused: 0 };
    MediaPlay.join(bar, () => bar.paused++);

    const embed = {};
    document.dispatchEvent(new window.CustomEvent('media:play', { detail: { player: embed } }));
    assert.equal(bar.paused, 1);
    assert.equal(MediaPlay.current, embed);
});

test('a plain element that starts with its sound on announces itself and is paused by another', () => {
    const { MediaPlay, heard, element } = page('<audio id="a"></audio><video id="v"></video>');
    const audio = element('a');
    const video = element('v');
    const bar = { paused: 0 };
    MediaPlay.join(bar, () => bar.paused++);

    audio.start();
    assert.equal(heard.length, 1);
    assert.equal(heard[0].player, audio);
    assert.equal(heard[0].kind, 'audio');
    assert.equal(bar.paused, 1);

    video.start();
    assert.equal(audio.paused, true, 'the audio stops when the video starts');
    assert.equal(video.paused, false);

    MediaPlay.announce(bar);
    assert.equal(video.paused, true, 'and the video when the bar starts');
});

test('a muted loop neither announces nor is paused, until it gets its sound', () => {
    const { window, MediaPlay, heard, element } = page('<video id="hero"></video>');
    const hero = element('hero', { muted: true });
    const bar = { paused: 0 };
    MediaPlay.join(bar, () => bar.paused++);

    hero.start();
    assert.equal(heard.length, 0);
    MediaPlay.announce(bar);
    assert.equal(hero.paused, false);

    hero.muted = false;
    hero.dispatchEvent(new window.Event('volumechange'));
    assert.equal(heard.at(-1).player, hero);
    assert.equal(bar.paused, 1);
});

test('data-media-play="off" leaves an element out', () => {
    const { MediaPlay, heard, element } = page('<div data-media-play="off"><audio id="fx"></audio></div>');
    const fx = element('fx');

    fx.start();
    assert.equal(heard.length, 0);
    MediaPlay.announce({});
    assert.equal(fx.paused, false);
});

test('a failing player does not keep the others from pausing', () => {
    const { MediaPlay, window } = page();
    window.console.error = () => {};
    const film = { paused: 0 };
    MediaPlay.join({}, () => { throw new Error('gone'); });
    MediaPlay.join(film, () => film.paused++);

    MediaPlay.announce({});
    assert.equal(film.paused, 1);
});
