import { Controller } from '@hotwired/stimulus';

/*
 * poll: ask a JSON address every few seconds and say when what it answers
 * changes - a kitchen board waiting for orders, a queue, a status. Generalised
 * from Nakaya's kitchen board (assets/nakaya/kitchen.js).
 *
 *     <div data-controller="poll"
 *          data-poll-url-value="{{ path('app_kitchen_state') }}"
 *          data-poll-interval-value="10000"      ms between two asks (10 s)
 *          data-poll-key-value="latest"          the field compared ("latest": 42)
 *          data-poll-since-value="{{ latest }}"  what the page already shows
 *          data-poll-chime-value="true"          ring on a change (after a tap: see arm)
 *          data-poll-awake-value="true">         keep the screen on while armed
 *         <button data-action="poll#arm">Activer les alertes</button>
 *         <button data-action="poll#silence">Vu</button>
 *     </div>
 *
 * On a change it dispatches poll:change (detail: the answer) on the element,
 * adds is-ringing, and with chime rings - three notes, again every two seconds
 * - until poll#silence. A page that is not shown is not polled; it asks again
 * as soon as it is. Browsers only let a page make sound after a touch: arm()
 * is that touch (and remembers it on this device, under poll/<url>).
 * Register it in assets/bootstrap.js:
 *
 *     import Poll from '../vendor/glitchr/omnibase/assets/controllers/poll_controller.js';
 *     app.register('poll', Poll);
 */
export default class extends Controller {
    static values = { url: String, interval: { type: Number, default: 10000 }, key: { type: String, default: 'latest' },
        since: { type: Number, default: 0 }, chime: Boolean, awake: Boolean };

    connect() {
        this.known = this.sinceValue;
        this.onVisible = () => { if (document.visibilityState === 'visible') { this.poll(); this.stayAwake(); } };
        document.addEventListener('visibilitychange', this.onVisible);
        this.timer = setInterval(() => this.poll(), this.intervalValue);
        try { if (localStorage.getItem(this.storageKey())) this.element.classList.add('is-armed'); } catch (e) { /* private mode */ }
    }

    disconnect() {
        clearInterval(this.timer);
        clearInterval(this.ringing);
        document.removeEventListener('visibilitychange', this.onVisible);
        if (this.wakeLock) this.wakeLock.release().catch(() => {});
    }

    storageKey() { return 'poll/' + this.urlValue; }

    /** The touch that lets the page ring and keep the screen on. */
    arm() {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (AudioContext && !this.audio) this.audio = new AudioContext();
        if (this.audio && this.audio.state === 'suspended') this.audio.resume();
        if (this.chimeValue) this.ring(); // the tap's test ring: the volume is right
        this.element.classList.add('is-armed');
        try { localStorage.setItem(this.storageKey(), '1'); } catch (e) { /* private mode */ }
        this.stayAwake();
        this.poll();
    }

    silence() {
        clearInterval(this.ringing);
        this.ringing = null;
        this.element.classList.remove('is-ringing');
        this.dispatch('silence');
    }

    poll() {
        if (!this.urlValue || document.visibilityState === 'hidden') return;
        fetch(this.urlValue, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : null))
            .then((state) => {
                if (!state) return;
                const value = Number(state[this.keyValue]) || 0;
                if (value > this.known) {
                    this.known = value;
                    this.changed(state);
                }
            })
            .catch(() => { /* offline a moment: next time */ });
    }

    changed(state) {
        this.element.classList.add('is-ringing');
        this.dispatch('change', { detail: state });
        if (!this.chimeValue || this.ringing) return;
        this.ring();
        this.ringing = setInterval(() => this.ring(), 2000);
    }

    ring() {
        if (!this.audio) return;
        const t = this.audio.currentTime + 0.02;
        [880, 1175, 1568].forEach((freq, i) => this.tone(t + i * 0.35, freq));
        if (navigator.vibrate) navigator.vibrate([300, 100, 300]);
    }

    tone(at, freq) {
        const osc = this.audio.createOscillator();
        const gain = this.audio.createGain();
        osc.type = 'square';
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(0.9, at + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.32);
        osc.connect(gain).connect(this.audio.destination);
        osc.start(at);
        osc.stop(at + 0.35);
    }

    stayAwake() {
        if (!this.awakeValue || !this.element.classList.contains('is-armed')) return;
        if (!('wakeLock' in navigator) || document.visibilityState !== 'visible') return;
        navigator.wakeLock.request('screen').then((lock) => { this.wakeLock = lock; }).catch(() => {});
    }
}
