// AirPlay Manager — Alpine.js global store
// Uses WebKit AirPlay APIs (Safari only, no external SDK required)

document.addEventListener('alpine:init', () => {
    Alpine.store('airplay', {
        isSupported: false,
        isAvailable: false,
        isCasting: false,
        showHint: false,
        currentStreamUrl: null,
        _activeVideoElement: null,
        _onStopCallback: null,
        _hintTimer: null,

        // Bound listener references for clean removal
        _boundOnAvailability: null,
        _boundOnWirelessChanged: null,

        init() {
            if (!('webkitShowPlaybackTargetPicker' in HTMLVideoElement.prototype)) {
                return;
            }

            // On iOS, ALL browsers use WebKit, so this API exists even in
            // Chrome/Firefox on iOS. If the Google Cast SDK loaded successfully,
            // Chromecast takes priority over AirPlay.
            if (window.cast && window.cast.framework) {
                console.log('[AirPlayManager] Cast SDK already loaded, deferring to Chromecast');
                return;
            }

            this.isSupported = true;
            console.log('[AirPlayManager] AirPlay support detected');

            // The Cast SDK loads asynchronously. If it initialises after Alpine
            // boots, disable AirPlay in favour of Chromecast.
            Alpine.effect(() => {
                const castStore = Alpine.store('cast');
                if (castStore && castStore.isReady) {
                    this.isSupported = false;
                    console.log('[AirPlayManager] Cast SDK initialised late, disabling AirPlay');
                }
            });
        },

        /**
         * Attach AirPlay event listeners to a video element.
         * Call this whenever a new video element begins playback on Safari.
         */
        attachToVideo(videoElement) {
            if (!this.isSupported || !videoElement) {
                return;
            }

            // Detach from any previous video element first
            this.detachFromVideo();

            this._activeVideoElement = videoElement;

            // Create bound listeners so we can remove them later
            this._boundOnAvailability = (event) => {
                this.isAvailable = event.availability === 'available';
                console.log('[AirPlayManager] Target availability changed', {
                    availability: event.availability,
                    isAvailable: this.isAvailable,
                });
            };

            this._boundOnWirelessChanged = () => {
                const isWireless = videoElement.webkitCurrentPlaybackTargetIsWireless;
                console.log('[AirPlayManager] Wireless playback changed', {
                    isWireless,
                });

                if (isWireless) {
                    this.isCasting = true;
                } else {
                    this._handleAirPlayEnded();
                }
            };

            videoElement.addEventListener(
                'webkitplaybacktargetavailabilitychanged',
                this._boundOnAvailability
            );

            videoElement.addEventListener(
                'webkitcurrentplaybacktargetiswirelesschanged',
                this._boundOnWirelessChanged
            );

            console.log('[AirPlayManager] Attached to video element');
        },

        /**
         * Remove AirPlay event listeners from the current video element.
         */
        detachFromVideo() {
            if (!this._activeVideoElement) {
                return;
            }

            if (this._boundOnAvailability) {
                this._activeVideoElement.removeEventListener(
                    'webkitplaybacktargetavailabilitychanged',
                    this._boundOnAvailability
                );
            }

            if (this._boundOnWirelessChanged) {
                this._activeVideoElement.removeEventListener(
                    'webkitcurrentplaybacktargetiswirelesschanged',
                    this._boundOnWirelessChanged
                );
            }

            this._activeVideoElement = null;
            this._boundOnAvailability = null;
            this._boundOnWirelessChanged = null;

            console.log('[AirPlayManager] Detached from video element');
        },

        /**
         * Open the AirPlay device picker for a video element.
         *
         * Must be called synchronously from a user gesture (click/tap) —
         * webkitShowPlaybackTargetPicker() is gated on user activation by iOS.
         *
         * @param {HTMLVideoElement} videoElement  The video element to AirPlay
         * @param {string}           castUrl       Cast-safe HLS URL (kept for state tracking)
         * @param {Function}         onCastStopped Called when AirPlay ends
         */
        startAirPlay(videoElement, castUrl, onCastStopped) {
            if (!this.isSupported || !videoElement) {
                console.warn('[AirPlayManager] AirPlay not supported or no video element');
                return;
            }

            this._onStopCallback = typeof onCastStopped === 'function' ? onCastStopped : null;
            this.currentStreamUrl = castUrl || videoElement.src || null;

            // Ensure we're listening on this video element
            this.attachToVideo(videoElement);

            // Open the AirPlay device picker immediately — do NOT swap the video
            // source first. The picker requires the video to be in a playable
            // state, and changing src puts it into HAVE_NOTHING. AirPlay will
            // hand off whatever the video is currently playing.
            try {
                videoElement.webkitShowPlaybackTargetPicker();
                console.log('[AirPlayManager] AirPlay picker opened');
            } catch (e) {
                console.error('[AirPlayManager] Failed to open AirPlay picker:', e);
            }
        },

        /**
         * Re-open the AirPlay picker to let the user disconnect.
         * There is no programmatic way to stop AirPlay from JavaScript.
         */
        stopAirPlay() {
            if (this._activeVideoElement) {
                try {
                    this._activeVideoElement.webkitShowPlaybackTargetPicker();
                } catch (e) {
                    console.warn('[AirPlayManager] Failed to open AirPlay picker:', e);
                }
            }
        },

        /**
         * Internal handler when AirPlay ends.
         */
        _handleAirPlayEnded() {
            const wasCasting = this.isCasting;
            const callback = this._onStopCallback;

            this.isCasting = false;
            this.currentStreamUrl = null;
            this._onStopCallback = null;

            if (wasCasting && typeof callback === 'function') {
                console.log('[AirPlayManager] AirPlay ended, invoking callback');
                callback();
            }
        },

        /**
         * Convert a possibly-relative URL to absolute so the AirPlay device can reach it.
         */
        _toAbsoluteUrl(url) {
            if (!url) {
                return url;
            }
            if (url.startsWith('http://') || url.startsWith('https://')) {
                return url;
            }
            return window.location.origin + (url.startsWith('/') ? '' : '/') + url;
        },
    });
});

// Listen for direct-cast requests from table actions.
// When on Safari (AirPlay supported, Cast SDK not loaded), handle these as AirPlay.
window.addEventListener('startDirectCast', (event) => {
    const store = window.Alpine && Alpine.store('airplay');
    if (!store || !store.isSupported) {
        return;
    }

    // If the Cast SDK is active, let cast-manager handle this event instead
    const castStore = Alpine.store('cast');
    if (castStore && castStore.isReady) {
        return;
    }

    let detail = event.detail;
    if (Array.isArray(detail)) detail = detail[0];

    const { cast_url } = detail;
    if (!cast_url) {
        console.warn('[AirPlayManager] No cast URL in direct-cast event');
        return;
    }

    // AirPlay requires a playing video element with a direct user gesture.
    // Table actions go through a Livewire roundtrip which loses the gesture,
    // and there may be no video on the page at all. Open the stream in the
    // floating player instead — the user can then tap its AirPlay button.
    if (window.Livewire) {
        console.log('[AirPlayManager] Opening floating player for AirPlay');
        Livewire.dispatch('openFloatingStream', [detail]);

        // Show a hint pointing to the AirPlay button on the floating player
        if (store._hintTimer) clearTimeout(store._hintTimer);
        store.showHint = true;
        store._hintTimer = setTimeout(() => {
            store.showHint = false;
            store._hintTimer = null;
        }, 5000);
    }
});

// On Safari (but NOT Chrome/Firefox on iOS where Cast SDK loads), swap all
// Chromecast icons and tooltips to AirPlay across the page. Filament table
// actions are rendered server-side with ->icon('svg-chromecast'), so we must
// patch the DOM client-side.
if ('webkitShowPlaybackTargetPicker' in HTMLVideoElement.prototype) {
    const CHROMECAST_PATH_PREFIX = 'M1 18v3h3';
    const CHROMECAST_PATH = 'M1 18v3h3c0-1.66-1.34-3-3-3zm0-4v2c2.76 0 5 2.24 5 5h2c0-3.87-3.13-7-7-7zm0-4v2c4.97 0 9 4.03 9 9h2c0-6.08-4.93-11-11-11zm20-7H3c-1.1 0-2 .9-2 2v3h2V5h18v14h-7v2h7c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z';
    const AIRPLAY_PATH_PREFIX = 'M6 22h12l';
    const AIRPLAY_PATH = 'M6 22h12l-6-6zM21 3H3c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h4v-2H3V5h18v12h-4v2h4c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z';

    let castSdkActive = false;

    function isCastSdkLoaded() {
        return !!(window.cast && window.cast.framework);
    }

    function swapToAirPlay(root) {
        if (castSdkActive) return;
        const paths = (root || document).querySelectorAll('svg path');
        for (const path of paths) {
            const d = path.getAttribute('d');
            if (!d || !d.startsWith(CHROMECAST_PATH_PREFIX)) continue;

            path.setAttribute('d', AIRPLAY_PATH);

            // Update Filament tooltip on the parent button
            const button = path.closest('button, a');
            if (button) {
                const tooltip = button.getAttribute('x-tooltip');
                if (tooltip && tooltip.includes('Cast to Chromecast')) {
                    button.setAttribute('x-tooltip', tooltip.replace('Cast to Chromecast', 'AirPlay'));
                }
                const ariaLabel = button.getAttribute('aria-label');
                if (ariaLabel && ariaLabel.includes('Cast to Chromecast')) {
                    button.setAttribute('aria-label', ariaLabel.replace('Cast to Chromecast', 'AirPlay'));
                }
                const title = button.getAttribute('title');
                if (title && title.includes('Cast to Chromecast')) {
                    button.setAttribute('title', title.replace('Cast to Chromecast', 'AirPlay'));
                }
            }
        }
    }

    function restoreToChromecast() {
        const paths = document.querySelectorAll('svg path');
        for (const path of paths) {
            const d = path.getAttribute('d');
            if (!d || !d.startsWith(AIRPLAY_PATH_PREFIX)) continue;

            path.setAttribute('d', CHROMECAST_PATH);

            const button = path.closest('button, a');
            if (button) {
                const tooltip = button.getAttribute('x-tooltip');
                if (tooltip && tooltip.includes('AirPlay')) {
                    button.setAttribute('x-tooltip', tooltip.replace('AirPlay', 'Cast to Chromecast'));
                }
                const ariaLabel = button.getAttribute('aria-label');
                if (ariaLabel && ariaLabel.includes('AirPlay')) {
                    button.setAttribute('aria-label', ariaLabel.replace('AirPlay', 'Cast to Chromecast'));
                }
                const title = button.getAttribute('title');
                if (title && title.includes('AirPlay')) {
                    button.setAttribute('title', title.replace('AirPlay', 'Cast to Chromecast'));
                }
            }
        }
    }

    // Debounce via requestAnimationFrame so rapid DOM mutations don't thrash
    let swapFrame = null;
    function scheduleSwap() {
        if (castSdkActive) return;
        if (swapFrame) return;
        swapFrame = requestAnimationFrame(() => {
            swapToAirPlay();
            swapFrame = null;
        });
    }

    // Run on initial page load (skip if Cast SDK already loaded)
    if (!isCastSdkLoaded()) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', scheduleSwap);
        } else {
            scheduleSwap();
        }
    }

    // Run after Livewire SPA navigations and component updates
    document.addEventListener('livewire:navigated', scheduleSwap);

    // Catch any DOM mutations (Livewire morphs, lazy-loaded table rows, etc.)
    const observer = new MutationObserver(scheduleSwap);
    const startObserving = () => {
        observer.observe(document.body, { childList: true, subtree: true });
    };

    if (document.body) {
        startObserving();
    } else {
        document.addEventListener('DOMContentLoaded', startObserving);
    }

    // If the Cast SDK initialises after we've already swapped icons, undo it.
    document.addEventListener('alpine:init', () => {
        Alpine.effect(() => {
            const castStore = Alpine.store('cast');
            if (castStore && castStore.isReady) {
                castSdkActive = true;
                observer.disconnect();
                restoreToChromecast();
            }
        });
    });
}
