import { formatTime, transcriptDuration } from '../timing.js';
import { detectBrowserExportSupport, RESOLUTION_PRESETS } from './browser-support.js';
import { createCanvasRenderer } from './canvas-renderer.js';
import { KaroksVideoExporter, EXPORT_STATES } from './exporter.js';
import { formatFileSize } from './filename.js';

/**
 * @param {import('alpinejs').Alpine} Alpine
 */
export function registerKaroksVideoExport(Alpine) {
  Alpine.data('karoksVideoExport', (config) => ({
    lines: config.lines ?? [],
    theme: config.theme ?? {},
    title: config.title ?? '',
    artist: config.artist ?? '',
    audioUrl: config.audioUrl ?? '',
    resolutionId: '720p',
    state: EXPORT_STATES.IDLE,
    errorMessage: null,
    browserSupported: true,
    browserMessage: null,
    selectedMimeType: null,
    estimatedDuration: transcriptDuration(config.lines ?? []),
    elapsedTime: 0,
    progress: 0,
    elapsedLabel: '0:00',
    totalLabel: formatTime(transcriptDuration(config.lines ?? [])),
    filename: null,
    fileSizeLabel: null,
    downloadUrl: null,
    previewUrl: null,
    previewCanvas: null,
    exporter: null,
    _livewireCancelHandler: null,
    _initialized: false,

    init() {
      if (this._initialized) {
        return;
      }

      this._initialized = true;
      const support = detectBrowserExportSupport(window);
      this.browserSupported = support.supported;
      this.browserMessage = support.unsupportedMessage;
      this.selectedMimeType = support.mimeType;
      this.previewCanvas = this.$refs.previewCanvas;
      this.renderPreview();

      this._livewireCancelHandler = () => {
        if (this.isBusy) {
          this.cancelExport();
        } else {
          this.destroyExporter();
        }
      };

      document.addEventListener('livewire:navigating', this._livewireCancelHandler);
      window.addEventListener('pagehide', this._livewireCancelHandler);
    },

    get resolutions() {
      return Object.values(RESOLUTION_PRESETS);
    },

    get isBusy() {
      return [EXPORT_STATES.PREPARING, EXPORT_STATES.RECORDING, EXPORT_STATES.FINALIZING].includes(this.state);
    },

    get canStart() {
      return this.browserSupported && !this.isBusy && this.state !== EXPORT_STATES.COMPLETED;
    },

    get statusLabel() {
      switch (this.state) {
        case EXPORT_STATES.PREPARING:
          return 'Preparing export…';
        case EXPORT_STATES.RECORDING:
          return 'Recording video in real time…';
        case EXPORT_STATES.FINALIZING:
          return 'Finalizing WebM file…';
        case EXPORT_STATES.COMPLETED:
          return 'Export complete';
        case EXPORT_STATES.CANCELLED:
          return 'Export cancelled';
        case EXPORT_STATES.FAILED:
          return 'Export failed';
        default:
          return 'Ready to export';
      }
    },

    renderPreview() {
      if (!this.previewCanvas) {
        return;
      }

      this.previewCanvas.width = 640;
      this.previewCanvas.height = 360;
      const renderer = createCanvasRenderer({
        canvas: this.previewCanvas,
        lines: this.lines,
        theme: this.theme,
        title: this.title,
        artist: this.artist,
        resolutionId: this.resolutionId,
      });
      renderer.drawFrame(0);
    },

    onResolutionChange() {
      this.renderPreview();
    },

    destroyExporter() {
      if (this.exporter) {
        this.exporter.destroy();
        this.exporter = null;
      }

      this.downloadUrl = null;
      this.previewUrl = null;
      this.filename = null;
      this.fileSizeLabel = null;
    },

    async startExport() {
      if (!this.canStart) {
        return;
      }

      this.destroyExporter();
      this.errorMessage = null;
      this.progress = 0;
      this.elapsedTime = 0;
      this.elapsedLabel = '0:00';

      this.exporter = new KaroksVideoExporter({
        lines: this.lines,
        theme: this.theme,
        title: this.title,
        artist: this.artist,
        audioUrl: this.audioUrl,
        resolutionId: this.resolutionId,
        onStateChange: (payload) => {
          this.state = payload.state;
          this.errorMessage = payload.errorMessage;
          this.filename = payload.filename;
          this.downloadUrl = payload.downloadUrl;
          this.previewUrl = payload.previewUrl;
          this.selectedMimeType = payload.mimeType;
          this.estimatedDuration = payload.estimatedDuration;
          this.fileSizeLabel = payload.fileSize ? formatFileSize(payload.fileSize) : null;
          if (payload.state === EXPORT_STATES.COMPLETED) {
            this.progress = 100;
            this.elapsedLabel = formatTime(payload.elapsedTime);
          }
        },
        onProgress: (payload) => {
          this.progress = payload.progress;
          this.elapsedTime = payload.elapsedTime;
          this.elapsedLabel = payload.elapsedLabel;
          this.totalLabel = payload.totalLabel;
        },
      });

      await this.exporter.start();
    },

    async cancelExport() {
      if (this.exporter) {
        await this.exporter.cancel();
      }
    },

    retryExport() {
      this.state = EXPORT_STATES.IDLE;
      this.errorMessage = null;
      this.progress = 0;
      this.destroyExporter();
    },

    newExport() {
      this.retryExport();
      this.renderPreview();
    },

    downloadResult() {
      if (!this.downloadUrl || !this.filename) {
        return;
      }

      const anchor = document.createElement('a');
      anchor.href = this.downloadUrl;
      anchor.download = this.filename;
      anchor.rel = 'noopener';
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
    },

    destroy() {
      this.destroyExporter();
      if (this._livewireCancelHandler) {
        document.removeEventListener('livewire:navigating', this._livewireCancelHandler);
        window.removeEventListener('pagehide', this._livewireCancelHandler);
        this._livewireCancelHandler = null;
      }
    },
  }));
}
