import {
  derivePlayerView,
  formatTime,
  transcriptDuration,
  wordHighlightStyle,
  isWordPast,
  isWordActive,
} from './timing.js';

function clamp(value, min, max) {
  return Math.min(Math.max(value, min), max);
}

export function registerKaroksPlayer(Alpine) {
  Alpine.data('karoksPlayer', (config) => ({
    lines: config.lines ?? [],
    audioUrl: config.audioUrl ?? '',
    compact: Boolean(config.compact),

    currentTime: 0,
    duration: transcriptDuration(config.lines ?? []),
    isPlaying: false,
    volume: 0.85,
    isMuted: false,
    isFullscreen: false,
    audioReady: false,
    audioMissing: false,
    audioError: false,

    lyricWindow: { previous: null, current: null, next: null },
    activeWord: { word: null, progress: 0 },

    _audio: null,
    _rafId: null,
    _volumeBeforeMute: 0.85,
    _listenersBound: false,
    _boundHandlers: null,
    _fullscreenHandler: null,
    _initialized: false,
    _destroyed: false,

    get formattedCurrentTime() {
      return formatTime(this.currentTime);
    },

    get formattedDuration() {
      return formatTime(this.duration);
    },

    get seekMax() {
      return this.duration > 0 ? this.duration : 0;
    },

    init() {
      if (this._initialized) {
        return;
      }

      this._initialized = true;
      this._audio = this.$refs.audio;
      this._applyView(0, this.duration);
      this._bindAudioEvents();
      this._bindFullscreenEvents();

      this._livewireNavigateHandler = () => this.destroy();
      window.addEventListener('livewire:navigating', this._livewireNavigateHandler);
    },

    destroy() {
      if (this._destroyed) return;
      this._destroyed = true;
      this._stopRaf();
      this._unbindAudioEvents();
      this._unbindFullscreenEvents();
      if (this._livewireNavigateHandler) {
        window.removeEventListener('livewire:navigating', this._livewireNavigateHandler);
        this._livewireNavigateHandler = null;
      }
    },

    _applyView(time, nextDuration) {
      const view = derivePlayerView(this.lines, time);
      this.currentTime = time;
      if (typeof nextDuration === 'number' && Number.isFinite(nextDuration)) {
        this.duration = nextDuration;
      }
      this.lyricWindow = view.lyricWindow;
      this.activeWord = view.activeWord;
    },

    _fallbackDuration() {
      return transcriptDuration(this.lines);
    },

    _stopRaf() {
      if (this._rafId !== null) {
        cancelAnimationFrame(this._rafId);
        this._rafId = null;
      }
    },

    _startRaf() {
      this._stopRaf();
      const tick = () => {
        const audio = this._audio;
        if (!audio) return;
        const mediaDuration = Number.isFinite(audio.duration)
          ? audio.duration
          : this._fallbackDuration();
        this._applyView(audio.currentTime || 0, mediaDuration);
        this._rafId = requestAnimationFrame(tick);
      };
      this._rafId = requestAnimationFrame(tick);
    },

    _bindAudioEvents() {
      const audio = this._audio;
      if (!audio || this._listenersBound) return;

      const onLoaded = () => {
        this.audioReady = true;
        this.audioMissing = false;
        this.audioError = false;
        const mediaDuration = Number.isFinite(audio.duration)
          ? audio.duration
          : this._fallbackDuration();
        this._applyView(audio.currentTime || 0, mediaDuration);
      };

      const onError = () => {
        this.audioReady = false;
        this.audioMissing = true;
        this.audioError = true;
        this.isPlaying = false;
        this._stopRaf();
        this.duration = this._fallbackDuration();
      };

      const onPlay = () => {
        this.isPlaying = true;
        this._startRaf();
      };

      const onPause = () => {
        this.isPlaying = false;
        this._stopRaf();
        this._applyView(
          audio.currentTime || 0,
          Number.isFinite(audio.duration) ? audio.duration : this._fallbackDuration(),
        );
      };

      const onEnded = () => {
        this.isPlaying = false;
        this._stopRaf();
        this._applyView(
          audio.currentTime || 0,
          Number.isFinite(audio.duration) ? audio.duration : this._fallbackDuration(),
        );
      };

      const onTimeUpdate = () => {
        if (!this.isPlaying) {
          this._applyView(
            audio.currentTime || 0,
            Number.isFinite(audio.duration) ? audio.duration : this._fallbackDuration(),
          );
        }
      };

      const onSeeking = () => {
        this._applyView(
          audio.currentTime || 0,
          Number.isFinite(audio.duration) ? audio.duration : this._fallbackDuration(),
        );
      };

      const onSeeked = () => {
        this._applyView(
          audio.currentTime || 0,
          Number.isFinite(audio.duration) ? audio.duration : this._fallbackDuration(),
        );
      };

      const onVolumeChange = () => {
        this.volume = audio.volume;
        this.isMuted = audio.muted || audio.volume === 0;
      };

      const onDurationChange = () => {
        if (Number.isFinite(audio.duration)) {
          this.duration = audio.duration;
        }
      };

      this._boundHandlers = {
        loadedmetadata: onLoaded,
        canplay: onLoaded,
        error: onError,
        play: onPlay,
        pause: onPause,
        ended: onEnded,
        timeupdate: onTimeUpdate,
        seeking: onSeeking,
        seeked: onSeeked,
        volumechange: onVolumeChange,
        durationchange: onDurationChange,
      };

      Object.entries(this._boundHandlers).forEach(([event, handler]) => {
        audio.addEventListener(event, handler);
      });

      audio.volume = this.isMuted ? 0 : this.volume;
      this._listenersBound = true;

      if (audio.readyState >= 1) {
        onLoaded();
      }
    },

    _unbindAudioEvents() {
      const audio = this._audio;
      if (!audio || !this._boundHandlers) return;

      Object.entries(this._boundHandlers).forEach(([event, handler]) => {
        audio.removeEventListener(event, handler);
      });

      this._boundHandlers = null;
      this._listenersBound = false;
    },

    _bindFullscreenEvents() {
      if (this._fullscreenHandler) {
        return;
      }

      this._fullscreenHandler = () => {
        const stage = this.$refs.stage;
        this.isFullscreen = Boolean(stage && document.fullscreenElement === stage);
      };
      document.addEventListener('fullscreenchange', this._fullscreenHandler);
    },

    _unbindFullscreenEvents() {
      if (this._fullscreenHandler) {
        document.removeEventListener('fullscreenchange', this._fullscreenHandler);
        this._fullscreenHandler = null;
      }
    },

    togglePlay() {
      if (this.isPlaying) {
        this.pause();
      } else {
        this.play();
      }
    },

    play() {
      const audio = this._audio;
      if (!audio || this.audioMissing) return;
      audio.play().catch(() => {
        this.isPlaying = false;
        this._stopRaf();
      });
    },

    pause() {
      this._audio?.pause();
    },

    seek(time) {
      const max = this.duration > 0 ? this.duration : this._fallbackDuration();
      const next = clamp(time, 0, max);
      const audio = this._audio;

      if (audio && !this.audioMissing && audio.readyState >= 1) {
        audio.currentTime = next;
      }

      this._applyView(next, max);
    },

    onSeekInput(event) {
      this.seek(Number(event.target.value));
    },

    skip(delta) {
      this.seek(this.currentTime + delta);
    },

    setVolume(value) {
      const clamped = clamp(value, 0, 1);
      this.volume = clamped;

      if (clamped > 0) {
        this._volumeBeforeMute = clamped;
        this.isMuted = false;
        if (this._audio) {
          this._audio.volume = clamped;
          this._audio.muted = false;
        }
      } else {
        this.isMuted = true;
        if (this._audio) {
          this._audio.volume = 0;
        }
      }
    },

    onVolumeInput(event) {
      this.setVolume(Number(event.target.value));
    },

    toggleMute() {
      if (this.isMuted || this.volume === 0) {
        const restored = this._volumeBeforeMute || 0.85;
        this.isMuted = false;
        this.volume = restored;
        if (this._audio) {
          this._audio.volume = restored;
          this._audio.muted = false;
        }
      } else {
        this._volumeBeforeMute = this.volume;
        this.isMuted = true;
        if (this._audio) {
          this._audio.muted = true;
        }
      }
    },

    async toggleFullscreen() {
      const stage = this.$refs.stage;
      if (!stage) return;

      try {
        if (document.fullscreenElement === stage) {
          await document.exitFullscreen();
        } else {
          await stage.requestFullscreen();
        }
      } catch {
        // Fullscreen may be blocked by the browser.
      }
    },

    wordIsActive(word) {
      return isWordActive(word, this.activeWord.word?.id);
    },

    wordClass(word) {
      if (isWordActive(word, this.activeWord.word?.id)) {
        return 'karaoke-word is-active';
      }
      if (isWordPast(word, this.currentTime, this.activeWord.word?.id)) {
        return 'karaoke-word is-past';
      }
      return 'karaoke-word';
    },

    wordStyle(word) {
      if (!isWordActive(word, this.activeWord.word?.id)) {
        return null;
      }
      return wordHighlightStyle(this.activeWord.progress);
    },

    lineText(line) {
      if (!line?.words) return '';
      return line.words.map((word) => word.text).join(' ');
    },
  }));
}
