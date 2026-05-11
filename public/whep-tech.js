'use strict';

(function () {
  const Tech = videojs.getTech('Tech');

  class MediamtxWhepTech extends Tech {
    constructor(options, ready) {
      super(options, ready);
      this._reader = null;
      this._token = null;
      this._tokenInterval = null;
      this._stream = new URLSearchParams(window.location.search).get('stream');
      this._whepUrl = `https://stream.jrta.online/${this._stream}/whep`;
      if (!this._stream) throw new Error('Missing required ?stream= query param');
      this._initialising = false;
    }

    createEl() {
      return videojs.dom.createEl('video', {
        className: 'vjs-tech',
        tabIndex: -1,
      }, {
        autoplay: true,
        playsinline: '',
        muted: true,
        disablePictureInPicture: true,
      });
    }

    async play() {
      if (this._reader || this._initialising) return;
      this._initialising = true;
      try {
        this._token = await this._fetchToken();
        this._initReader();
        this._tokenInterval = setInterval(async () => {
          try {
            this._token = await this._fetchToken();
            if (this._reader) this._reader.conf.token = this._token;
          } catch (e) {
            console.error('Token refresh failed:', e);
          }
        }, 45000);
      } catch (err) {
        console.error('Player init failed:', err);
        this.trigger('error');
      } finally {
        this._initialising = false;
      }
    }

    pause() {}

    paused() { return false; }

    duration() { return Infinity; }

    currentTime() { return 0; }

    setCurrentTime() {}

    muted() { return this.el_.muted; }

    setMuted(muted) { this.el_.muted = muted; }

    volume() { return this.el_.volume; }

    setVolume(vol) { this.el_.volume = vol; }

    supportsFullScreen() { return false; }

    dispose() {
      clearInterval(this._tokenInterval);
      if (this._reader) {
        this._reader.close();
        this._reader = null;
      }
      super.dispose();
    }

    async _fetchToken() {
      const res = await fetch('https://platform.jrta.online/api/read', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        credentials: 'include',
        body: JSON.stringify({ path: this._stream }),
      });
      if (!res.ok) throw new Error(`Token fetch failed: ${res.status}`);
      const data = await res.json();
      if (!data.token) throw new Error('JWT not received');
      return data.token;
    }

    _initReader() {
      this._reader = new MediaMTXWebRTCReader({
        url: this._whepUrl,
        token: this._token,
        onError: (err) => {
          console.error('WebRTC error:', err);
        },
        onTrack: (evt) => {
          this.el_.srcObject = evt.streams[0];
          this.trigger('loadstart');
          this.trigger('canplay');
          this.trigger('playing');
        },
      });
    }
  }

  MediamtxWhepTech.isSupported = () => typeof RTCPeerConnection !== 'undefined';
  MediamtxWhepTech.canPlayType = () => 'maybe';
  MediamtxWhepTech.canPlaySource = (source) => source.type === 'video/whep' ? 'maybe' : '';

  videojs.registerTech('MediamtxWhep', MediamtxWhepTech);
}());
