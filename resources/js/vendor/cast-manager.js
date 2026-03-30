// Chromecast Cast Manager — Alpine.js global store
// Uses Google Cast SDK with Default Media Receiver (CC1AD845)

// Capture Cast SDK readiness at module scope — the SDK may fire this callback
// before Alpine boots, so we store the flag and replay it once the store exists.
//
// On the popout player page Alpine is not loaded, so the inline script there
// handles Cast initialisation directly. We chain onto any existing callback
// rather than overwriting it.
let _castSdkReady = false;

const _previousOnGCastApiAvailable = window['__onGCastApiAvailable'];
window['__onGCastApiAvailable'] = (isAvailable) => {
    // Forward to any previously-registered callback (e.g. popout page inline script)
    if (typeof _previousOnGCastApiAvailable === 'function') {
        _previousOnGCastApiAvailable(isAvailable);
    }

    if (isAvailable) {
        _castSdkReady = true;
        // If the Alpine store already exists, initialise immediately
        if (window.Alpine && Alpine.store('cast')) {
            Alpine.store('cast')._initCastApi();
        }
    }
};

document.addEventListener('alpine:init', () => {
    Alpine.store('cast', {
        isReady: false,
        isAvailable: false,
        isCasting: false,
        currentStreamUrl: null,
        _session: null,
        _mediaSession: null,

        init() {
            // If the Cast SDK was already ready before Alpine booted, init now
            if (_castSdkReady || (window.cast && window.cast.framework)) {
                this._initCastApi();
            }
        },

        _initCastApi() {
            try {
                const context = cast.framework.CastContext.getInstance();

                this.isReady = true;

                context.setOptions({
                    receiverApplicationId: chrome.cast.media.DEFAULT_MEDIA_RECEIVER_APP_ID,
                    autoJoinPolicy: chrome.cast.AutoJoinPolicy.ORIGIN_SCOPED,
                });

                // Listen for cast state changes (device availability)
                context.addEventListener(
                    cast.framework.CastContextEventType.CAST_STATE_CHANGED,
                    (event) => {
                        const state = event.castState;
                        this.isAvailable = state !== cast.framework.CastState.NO_DEVICES_AVAILABLE;

                        if (state === cast.framework.CastState.NOT_CONNECTED) {
                            this.isCasting = false;
                            this.currentStreamUrl = null;
                            this._session = null;
                            this._mediaSession = null;
                        }
                    }
                );

                // Listen for session state changes
                context.addEventListener(
                    cast.framework.CastContextEventType.SESSION_STATE_CHANGED,
                    (event) => {
                        if (event.sessionState === cast.framework.SessionState.SESSION_ENDED) {
                            this.isCasting = false;
                            this.currentStreamUrl = null;
                            this._session = null;
                            this._mediaSession = null;
                        }
                    }
                );

                console.log('[CastManager] Cast SDK initialised');
            } catch (e) {
                console.warn('[CastManager] Failed to initialise Cast SDK:', e);
            }
        },

        /**
         * Open the Chrome device picker and cast a stream.
         *
         * @param {string} url       Stream URL (may be relative)
         * @param {string} format    Stream format: 'hls', 'm3u8', 'ts', 'mpegts'
         * @param {string} title     Channel/stream title
         * @param {string} logo      Channel logo URL (optional)
         */
        async startCast(url, format, title, logo) {
            if (!window.cast || !window.chrome?.cast) {
                console.warn('[CastManager] Cast SDK not available');
                return;
            }

            try {
                const context = cast.framework.CastContext.getInstance();

                // Request a session (opens device picker if none active)
                await context.requestSession();

                this._session = context.getCurrentSession();
                if (!this._session) {
                    console.warn('[CastManager] No cast session after request');
                    return;
                }

                // Build absolute URL so the Chromecast can reach the server
                const absoluteUrl = this._toAbsoluteUrl(url);

                // Determine MIME type
                const contentType = this._getMimeType(format, url);

                const mediaInfo = new chrome.cast.media.MediaInfo(absoluteUrl, contentType);
                mediaInfo.streamType = this._isLiveFormat(format, url)
                    ? chrome.cast.media.StreamType.LIVE
                    : chrome.cast.media.StreamType.BUFFERED;

                // Set metadata (title + image)
                const metadata = new chrome.cast.media.GenericMediaMetadata();
                metadata.title = title || 'Stream';
                if (logo) {
                    const absoluteLogo = this._toAbsoluteUrl(logo);
                    metadata.images = [new chrome.cast.Image(absoluteLogo)];
                }
                mediaInfo.metadata = metadata;

                const loadRequest = new chrome.cast.media.LoadRequest(mediaInfo);
                loadRequest.autoplay = true;

                await this._session.loadMedia(loadRequest);

                this.isCasting = true;
                this.currentStreamUrl = url;
                this._mediaSession = this._session.getMediaSession();

                console.log('[CastManager] Now casting:', title);
            } catch (e) {
                // User cancelled the picker or an error occurred
                if (e.code === 'cancel') {
                    console.log('[CastManager] User cancelled cast picker');
                } else {
                    console.error('[CastManager] Cast error:', e);
                }
            }
        },

        /**
         * Stop the current cast session.
         */
        stopCast() {
            try {
                const context = cast.framework.CastContext.getInstance();
                const session = context.getCurrentSession();
                if (session) {
                    session.endSession(true);
                }
            } catch (e) {
                console.warn('[CastManager] Error stopping cast:', e);
            }

            this.isCasting = false;
            this.currentStreamUrl = null;
            this._session = null;
            this._mediaSession = null;
        },

        /**
         * Convert a possibly-relative URL to absolute so the Chromecast device can reach it.
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

        /**
         * Determine the MIME type for a given stream format.
         */
        _getMimeType(format, url) {
            const f = (format || '').toLowerCase();
            if (f === 'hls' || f === 'm3u8' || (url && url.includes('.m3u8'))) {
                return 'application/x-mpegURL';
            }
            if (f === 'ts' || f === 'mpegts') {
                return 'video/mp2t';
            }
            return 'video/mp4';
        },

        /**
         * Check whether the stream should be treated as live.
         */
        _isLiveFormat(format, url) {
            const f = (format || '').toLowerCase();
            // HLS and TS streams from IPTV are typically live
            return f === 'hls' || f === 'm3u8' || f === 'ts' || f === 'mpegts'
                || (url && url.includes('.m3u8'));
        },
    });
});
