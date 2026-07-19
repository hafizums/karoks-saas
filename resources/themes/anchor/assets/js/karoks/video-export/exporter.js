import { formatTime, transcriptDuration } from '../timing.js';
import {
  AUDIO_BITS_PER_SECOND,
  RECORDER_TIMESLICE_MS,
  detectBrowserExportSupport,
  getResolutionConfig,
} from './browser-support.js';
import { createCanvasRenderer } from './canvas-renderer.js';
import { buildExportFilename } from './filename.js';

export const EXPORT_STATES = {
  IDLE: 'idle',
  PREPARING: 'preparing',
  RECORDING: 'recording',
  FINALIZING: 'finalizing',
  COMPLETED: 'completed',
  CANCELLED: 'cancelled',
  FAILED: 'failed',
};

/**
 * @param {number} currentTime
 * @param {number} duration
 */
export function calculateExportProgress(currentTime, duration) {
  if (!Number.isFinite(duration) || duration <= 0) {
    return 0;
  }

  return Math.min(100, Math.max(0, (currentTime / duration) * 100));
}

/**
 * @param {typeof globalThis} target
 */
export function createVideoExporterFactory(target = globalThis) {
  return class KaroksVideoExporter {
    /**
     * @param {object} options
     */
    constructor(options) {
      this.lines = options.lines ?? [];
      this.theme = options.theme ?? {};
      this.title = options.title ?? '';
      this.artist = options.artist ?? '';
      this.audioUrl = options.audioUrl ?? '';
      this.resolutionId = options.resolutionId ?? '720p';
      this.onStateChange = options.onStateChange ?? (() => {});
      this.onProgress = options.onProgress ?? (() => {});
      this.document = options.document ?? target.document;
      this.window = options.window ?? target;
      this.fetchImpl = options.fetchImpl ?? target.fetch?.bind(target);
      this.AudioContextClass = options.AudioContextClass ?? target.AudioContext ?? target.webkitAudioContext;
      this.MediaRecorderClass = options.MediaRecorderClass ?? target.MediaRecorder;

      this.state = EXPORT_STATES.IDLE;
      this.errorMessage = null;
      this.filename = null;
      this.fileSize = 0;
      this.previewUrl = null;
      this.downloadUrl = null;
      this.resultBlob = null;
      this.estimatedDuration = transcriptDuration(this.lines);
      this.elapsedTime = 0;
      this.progress = 0;
      this.selectedMimeType = detectBrowserExportSupport(target).mimeType;

      this._running = false;
      this._cancelRequested = false;
      this._destroyed = false;
      this._blobUrls = new Set();
      this._recordedChunks = [];
      this._renderIntervalId = null;
      this._frameIntervalMs = 1000 / 30;
      this._canvasVideoTrack = null;
      this._beforeUnloadHandler = null;
      this._recorder = null;
      this._combinedStream = null;
      this._canvasStream = null;
      this._audioElement = null;
      this._audioContext = null;
      this._audioSource = null;
      this._audioDestination = null;
      this._canvas = null;
      this._renderer = null;
      this._instrumentalBlob = null;
      this._recorderStopPromise = null;
      this._recorderStopResolve = null;
    }

    get isBusy() {
      return [EXPORT_STATES.PREPARING, EXPORT_STATES.RECORDING, EXPORT_STATES.FINALIZING].includes(this.state);
    }

    setState(nextState, errorMessage = null) {
      this.state = nextState;
      this.errorMessage = errorMessage;
      this.onStateChange({
        state: this.state,
        errorMessage: this.errorMessage,
        filename: this.filename,
        fileSize: this.fileSize,
        previewUrl: this.previewUrl,
        downloadUrl: this.downloadUrl,
        estimatedDuration: this.estimatedDuration,
        elapsedTime: this.elapsedTime,
        progress: this.progress,
        resolutionId: this.resolutionId,
        mimeType: this.selectedMimeType,
      });
    }

    updateProgress(currentTime, duration) {
      this.elapsedTime = currentTime;
      this.progress = calculateExportProgress(currentTime, duration);
      this.onProgress({
        elapsedTime: this.elapsedTime,
        totalDuration: duration,
        progress: this.progress,
        elapsedLabel: formatTime(this.elapsedTime),
        totalLabel: formatTime(duration),
      });
    }

    trackBlobUrl(url) {
      if (typeof url === 'string' && url.startsWith('blob:')) {
        this._blobUrls.add(url);
      }

      return url;
    }

    revokeBlobUrls() {
      for (const url of this._blobUrls) {
        try {
          this.window.URL.revokeObjectURL(url);
        } catch {
          // Ignore revoke failures during teardown.
        }
      }

      this._blobUrls.clear();
      this.previewUrl = null;
      this.downloadUrl = null;
    }

    stopStreamTracks(stream) {
      if (!stream) {
        return;
      }

      for (const track of stream.getTracks()) {
        try {
          track.stop();
        } catch {
          // Ignore track stop failures.
        }
      }
    }

    cancelAnimation() {
      if (this._renderIntervalId !== null) {
        this.window.clearInterval(this._renderIntervalId);
        this._renderIntervalId = null;
      }
    }

    requestCanvasFrame() {
      if (this._canvasVideoTrack && typeof this._canvasVideoTrack.requestFrame === 'function') {
        this._canvasVideoTrack.requestFrame();
      }
    }

    drawCurrentFrame() {
      if (!this._audioElement || !this._renderer) {
        return;
      }

      const duration = this._audioElement.duration || this.estimatedDuration;
      const currentTime = this._audioElement.currentTime;
      this._renderer.drawFrame(currentTime);
      this.requestCanvasFrame();
      this.updateProgress(currentTime, duration);
    }

    startRenderLoop(fps) {
      this.cancelAnimation();
      this._frameIntervalMs = 1000 / fps;
      this.drawCurrentFrame();
      this._renderIntervalId = this.window.setInterval(() => {
        if (this._destroyed || this._cancelRequested || !this._audioElement || !this._renderer) {
          this.cancelAnimation();
          return;
        }

        if (this._audioElement.ended) {
          this.cancelAnimation();
          return;
        }

        this.drawCurrentFrame();
      }, this._frameIntervalMs);
    }

    detachBeforeUnload() {
      if (this._beforeUnloadHandler) {
        this.window.removeEventListener('beforeunload', this._beforeUnloadHandler);
        this._beforeUnloadHandler = null;
      }
    }

    attachBeforeUnload() {
      this.detachBeforeUnload();
      this._beforeUnloadHandler = (event) => {
        event.preventDefault();
        event.returnValue = '';
      };
      this.window.addEventListener('beforeunload', this._beforeUnloadHandler);
    }

    async cleanup({ preserveResult = false } = {}) {
      this.cancelAnimation();
      this.detachBeforeUnload();

      if (this._recorder && this._recorder.state !== 'inactive') {
        try {
          this._recorder.stop();
        } catch {
          // Ignore recorder stop failures during cleanup.
        }
      }

      if (this._audioElement) {
        try {
          this._audioElement.pause();
          this._audioElement.removeAttribute('src');
          this._audioElement.load();
        } catch {
          // Ignore audio teardown failures.
        }
      }

      if (this._audioSource) {
        try {
          this._audioSource.disconnect();
        } catch {
          // Ignore node disconnect failures.
        }
      }

      if (this._audioDestination) {
        try {
          this._audioDestination.disconnect();
        } catch {
          // Ignore node disconnect failures.
        }
      }

      if (this._audioContext && this._audioContext.state !== 'closed') {
        try {
          await this._audioContext.close();
        } catch {
          // Ignore AudioContext close failures.
        }
      }

      this.stopStreamTracks(this._combinedStream);
      this.stopStreamTracks(this._canvasStream);

      this._recorder = null;
      this._combinedStream = null;
      this._canvasStream = null;
      this._audioElement = null;
      this._audioContext = null;
      this._audioSource = null;
      this._audioDestination = null;
      this._canvas = null;
      this._renderer = null;
      this._canvasVideoTrack = null;
      this._instrumentalBlob = null;
      this._recordedChunks = [];
      this._running = false;

      if (!preserveResult) {
        this.resultBlob = null;
        this.filename = null;
        this.fileSize = 0;
        this.revokeBlobUrls();
      }
    }

    fail(message) {
      const readable = message || 'Video export failed. Please try again.';
      this.setState(EXPORT_STATES.FAILED, readable);
      return this.cleanup();
    }

    async fetchInstrumental() {
      const response = await this.fetchImpl(this.audioUrl, {
        credentials: 'same-origin',
        headers: {
          Accept: 'audio/*',
        },
      });

      if (!response.ok) {
        throw new Error('Unable to load the instrumental audio for export.');
      }

      const blob = await response.blob();
      if (!blob || blob.size === 0) {
        throw new Error('The instrumental audio file is empty or unavailable.');
      }

      return blob;
    }

    createRecorder(stream, mimeType, resolutionConfig) {
      try {
        return new this.MediaRecorderClass(stream, {
          mimeType,
          videoBitsPerSecond: resolutionConfig.videoBitsPerSecond,
          audioBitsPerSecond: AUDIO_BITS_PER_SECOND,
        });
      } catch {
        throw new Error('This browser cannot start WebM recording for karaoke export.');
      }
    }

    renderLoop() {
      this.startRenderLoop(getResolutionConfig(this.resolutionId).fps);
    }

    waitForRecorderStop() {
      if (!this._recorderStopPromise) {
        this._recorderStopPromise = new Promise((resolve) => {
          this._recorderStopResolve = resolve;
        });
      }

      return this._recorderStopPromise;
    }

    async start() {
      if (this._destroyed) {
        return;
      }

      if (this._running) {
        throw new Error('An export is already running.');
      }

      const support = detectBrowserExportSupport(this.window);
      if (!support.supported || !support.mimeType) {
        this.setState(EXPORT_STATES.FAILED, support.unsupportedMessage);
        return;
      }

      this._running = true;
      this._cancelRequested = false;
      this.selectedMimeType = support.mimeType;
      this.setState(EXPORT_STATES.PREPARING);

      try {
        await this.cleanup();

        if (this._cancelRequested || this._destroyed) {
          this.setState(EXPORT_STATES.CANCELLED);
          return;
        }

        this._instrumentalBlob = await this.fetchInstrumental();

        if (this._cancelRequested || this._destroyed) {
          this.setState(EXPORT_STATES.CANCELLED);
          await this.cleanup();
          return;
        }

        const resolutionConfig = getResolutionConfig(this.resolutionId);
        this._canvas = this.document.createElement('canvas');
        this._canvas.width = resolutionConfig.width;
        this._canvas.height = resolutionConfig.height;
        this._renderer = createCanvasRenderer({
          canvas: this._canvas,
          lines: this.lines,
          theme: this.theme,
          title: this.title,
          artist: this.artist,
          resolutionId: this.resolutionId,
        });

        this._canvasStream = this._canvas.captureStream(resolutionConfig.fps);
        if (!this._canvasStream) {
          throw new Error('Canvas capture is unavailable in this browser.');
        }

        this._canvasVideoTrack = this._canvasStream.getVideoTracks()[0] ?? null;

        this._audioElement = this.document.createElement('audio');
        this._audioElement.preload = 'auto';
        this._audioElement.crossOrigin = 'anonymous';
        const instrumentalUrl = this.trackBlobUrl(this.window.URL.createObjectURL(this._instrumentalBlob));
        this._audioElement.src = instrumentalUrl;

        await new Promise((resolve, reject) => {
          const onLoaded = () => {
            cleanupListeners();
            resolve();
          };
          const onError = () => {
            cleanupListeners();
            reject(new Error('Unable to decode the instrumental audio for export.'));
          };
          const cleanupListeners = () => {
            this._audioElement.removeEventListener('loadedmetadata', onLoaded);
            this._audioElement.removeEventListener('error', onError);
          };
          this._audioElement.addEventListener('loadedmetadata', onLoaded);
          this._audioElement.addEventListener('error', onError);
          this._audioElement.load();
        });

        const duration = this._audioElement.duration;
        if (!Number.isFinite(duration) || duration <= 0) {
          throw new Error('The instrumental audio duration is invalid.');
        }

        this.estimatedDuration = duration;

        if (this._cancelRequested || this._destroyed) {
          this.setState(EXPORT_STATES.CANCELLED);
          await this.cleanup();
          return;
        }

        this._audioContext = new this.AudioContextClass();
        this._audioSource = this._audioContext.createMediaElementSource(this._audioElement);
        this._audioDestination = this._audioContext.createMediaStreamDestination();
        this._audioSource.connect(this._audioDestination);

        const audioTracks = this._audioDestination.stream.getAudioTracks();
        if (audioTracks.length === 0) {
          throw new Error('Unable to capture audio for export.');
        }

        this._combinedStream = new this.window.MediaStream([
          ...this._canvasStream.getVideoTracks(),
          ...audioTracks,
        ]);

        this._recorder = this.createRecorder(this._combinedStream, this.selectedMimeType, resolutionConfig);
        this._recorderStopPromise = null;
        this._recordedChunks = [];

        this._recorder.addEventListener('dataavailable', (event) => {
          if (event.data && event.data.size > 0) {
            this._recordedChunks.push(event.data);
          }
        });

        this._recorder.addEventListener('stop', () => {
          this._recorderStopResolve?.();
        });

        this._recorder.addEventListener('error', () => {
          this.fail('Recording stopped because of a browser encoder error.');
        });

        const endedPromise = new Promise((resolve) => {
          this._audioElement.addEventListener('ended', resolve, { once: true });
        });

        this.setState(EXPORT_STATES.RECORDING);
        this.attachBeforeUnload();

        this._renderer.drawFrame(0);
        this.requestCanvasFrame();
        this._recorder.start(RECORDER_TIMESLICE_MS);

        if (this._audioContext.state === 'suspended') {
          await this._audioContext.resume();
        }

        try {
          await this._audioElement.play();
        } catch {
          throw new Error('Playback was blocked. Start export again from the button.');
        }

        this.renderLoop();
        await endedPromise;

        if (this._cancelRequested || this._destroyed) {
          this.setState(EXPORT_STATES.CANCELLED);
          await this.cleanup();
          return;
        }

        this.setState(EXPORT_STATES.FINALIZING);
        this.detachBeforeUnload();
        this.cancelAnimation();

        if (this._recorder.state !== 'inactive') {
          this._recorder.stop();
        }

        await this.waitForRecorderStop();
        await new Promise((resolve) => {
          this.window.setTimeout(resolve, 50);
        });

        const blob = new this.window.Blob(this._recordedChunks, { type: 'video/webm' });
        if (!blob || blob.size === 0) {
          throw new Error('The exported video was empty. Try again in Chrome or Edge.');
        }

        this.resultBlob = blob;
        this.fileSize = blob.size;
        this.filename = buildExportFilename(this.title, this.artist, this.resolutionId);
        this.revokeBlobUrls();
        this.downloadUrl = this.trackBlobUrl(this.window.URL.createObjectURL(blob));
        this.previewUrl = this.downloadUrl;
        this.progress = 100;
        this.elapsedTime = duration;
        this.setState(EXPORT_STATES.COMPLETED);
        await this.cleanup({ preserveResult: true });
      } catch (error) {
        if (this._cancelRequested) {
          this.setState(EXPORT_STATES.CANCELLED);
          await this.cleanup();
          return;
        }

        const message = error instanceof Error ? error.message : 'Video export failed. Please try again.';
        await this.fail(message);
      }
    }

    async cancel() {
      if (!this.isBusy) {
        return;
      }

      this._cancelRequested = true;
      this.setState(EXPORT_STATES.CANCELLED);
      await this.cleanup();
    }

    destroy() {
      this._destroyed = true;
      this._cancelRequested = true;
      this.cleanup();
      this.detachBeforeUnload();
    }
  };
}

export const KaroksVideoExporter = createVideoExporterFactory(globalThis);
