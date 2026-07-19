import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  AUDIO_BITS_PER_SECOND,
  MIME_PREFERENCE,
  RECORDER_TIMESLICE_MS,
  RESOLUTION_PRESETS,
  detectBrowserExportSupport,
  getResolutionConfig,
  selectSupportedMimeType,
} from '../../resources/themes/anchor/assets/js/karoks/video-export/browser-support.js';
import {
  createCanvasRenderer,
  exportFrameMetadata,
  getCanvasLayout,
  themeColorsForExport,
  wrapText,
} from '../../resources/themes/anchor/assets/js/karoks/video-export/canvas-renderer.js';
import {
  EXPORT_STATES,
  calculateExportProgress,
  createVideoExporterFactory,
} from '../../resources/themes/anchor/assets/js/karoks/video-export/exporter.js';
import {
  buildExportFilename,
  formatFileSize,
} from '../../resources/themes/anchor/assets/js/karoks/video-export/filename.js';

const sampleLines = [
  {
    id: 'line-1',
    start: 0.5,
    end: 4,
    words: [
      { id: 'word-1', text: 'Hello', start: 0.5, end: 1.2 },
      { id: 'word-2', text: 'world', start: 1.2, end: 4 },
    ],
  },
  {
    id: 'line-2',
    start: 5,
    end: 8,
    words: [
      { id: 'word-3', text: 'Next', start: 5, end: 8 },
    ],
  },
];

function createMockCanvasContext() {
  const state = { font: '16px sans-serif', fillStyle: '#000', textAlign: 'left' };

  return {
    state,
    createLinearGradient: () => ({ addColorStop: () => {} }),
    createRadialGradient: () => ({ addColorStop: () => {} }),
    fillRect: () => {},
    fillText: () => {},
    measureText(text) {
      return { width: String(text).length * 8 };
    },
    save: () => {},
    restore: () => {},
    beginPath: () => {},
    rect: () => {},
    clip: () => {},
    get font() {
      return state.font;
    },
    set font(value) {
      state.font = value;
    },
    get fillStyle() {
      return state.fillStyle;
    },
    set fillStyle(value) {
      state.fillStyle = value;
    },
    get textAlign() {
      return state.textAlign;
    },
    set textAlign(value) {
      state.textAlign = value;
    },
    get textBaseline() {
      return 'top';
    },
    set textBaseline(_value) {},
  };
}

function createMockCanvas() {
  const ctx = createMockCanvasContext();
  return {
    width: 1280,
    height: 720,
    getContext(type) {
      return type === '2d' ? ctx : null;
    },
    captureStream() {
      return createMockMediaStream('video');
    },
  };
}

function createMockMediaStream(kind = 'video') {
  const tracks = [{ kind, stop: () => {}, enabled: true }];
  return {
    getVideoTracks: () => (kind === 'video' ? tracks : []),
    getAudioTracks: () => (kind === 'audio' ? tracks : []),
    getTracks: () => tracks,
  };
}

function createMockAudioElement() {
  const listeners = new Map();
  return {
    preload: '',
    crossOrigin: '',
    src: '',
    currentTime: 0,
    duration: 2,
    paused: true,
    ended: false,
    addEventListener(event, handler) {
      if (!listeners.has(event)) {
        listeners.set(event, []);
      }
      listeners.get(event).push(handler);
    },
    removeEventListener(event, handler) {
      const handlers = listeners.get(event) ?? [];
      listeners.set(event, handlers.filter((item) => item !== handler));
    },
    dispatch(event) {
      for (const handler of listeners.get(event) ?? []) {
        handler();
      }
    },
    load() {
      queueMicrotask(() => this.dispatch('loadedmetadata'));
    },
    async play() {
      this.paused = false;
      queueMicrotask(() => this.dispatch('ended'));
    },
    pause() {},
    removeAttribute() {},
  };
}

function createSuccessfulExportMocks(overrides = {}) {
  const blobUrls = [];
  let animationFrameId = 0;
  const audioContextInstances = [];
  const stoppedTracks = [];
  const revokedUrls = [];
  const recorderInstances = [];

  const videoTrack = { kind: 'video', stop: () => stoppedTracks.push('video') };
  const audioTrack = { kind: 'audio', stop: () => stoppedTracks.push('audio') };
  const canvasStream = {
    getVideoTracks: () => [videoTrack],
    getAudioTracks: () => [],
    getTracks: () => [videoTrack],
  };

  class MockMediaRecorder {
    constructor(stream, options) {
      this.stream = stream;
      this.options = options;
      this.state = 'inactive';
      this.listeners = new Map();
      recorderInstances.push(this);
    }

    addEventListener(event, handler) {
      if (!this.listeners.has(event)) {
        this.listeners.set(event, []);
      }
      this.listeners.get(event).push(handler);
    }

    start() {
      this.state = 'recording';
      queueMicrotask(() => {
        for (const handler of this.listeners.get('dataavailable') ?? []) {
          handler({ data: { size: 128, type: 'video/webm' } });
        }
      });
    }

    stop() {
      this.state = 'inactive';
      queueMicrotask(() => {
        for (const handler of this.listeners.get('stop') ?? []) {
          handler();
        }
      });
    }

    emitError() {
      for (const handler of this.listeners.get('error') ?? []) {
        handler(new Event('error'));
      }
    }
  }

  class MockAudioContext {
    constructor() {
      this.state = 'running';
      audioContextInstances.push(this);
    }

    createMediaElementSource() {
      return { connect: () => {}, disconnect: () => {} };
    }

    createMediaStreamDestination() {
      return {
        stream: { getAudioTracks: () => [audioTrack], getTracks: () => [audioTrack] },
        disconnect: () => {},
      };
    }

    async close() {
      this.state = 'closed';
    }

    async resume() {}
  }

  const mockWindow = {
    MediaStream: class {
      constructor(tracks) {
        this._tracks = tracks;
      }

      getVideoTracks() {
        return this._tracks.filter((track) => track.kind === 'video');
      }

      getAudioTracks() {
        return this._tracks.filter((track) => track.kind === 'audio');
      }

      getTracks() {
        return this._tracks;
      }
    },
    MediaRecorder: MockMediaRecorder,
    AudioContext: MockAudioContext,
    webkitAudioContext: MockAudioContext,
    HTMLCanvasElement: { prototype: { captureStream: () => canvasStream } },
    URL: {
      createObjectURL: () => {
        const url = `blob:mock-${blobUrls.length + 1}`;
        blobUrls.push(url);
        return url;
      },
      revokeObjectURL: (url) => revokedUrls.push(url),
    },
    Blob: class {
      constructor(chunks, options) {
        this.chunks = chunks;
        this.type = options?.type ?? '';
        this.size = chunks.reduce((sum, chunk) => sum + (chunk.size ?? 0), 0);
      }
    },
    cancelAnimationFrame: (id) => {
      animationFrameId = id === animationFrameId ? null : animationFrameId;
    },
    requestAnimationFrame: () => {
      animationFrameId += 1;
      return animationFrameId;
    },
    setTimeout: (fn) => {
      fn();
      return 1;
    },
    addEventListener: () => {},
    removeEventListener: () => {},
    fetch: async () => ({
      ok: true,
      blob: async () => ({ size: 512, type: 'audio/wav' }),
    }),
    ...overrides.window,
  };

  mockWindow.MediaRecorder.isTypeSupported = () => true;

  const mockDocument = {
    createElement(tag) {
      if (tag === 'canvas') {
        const canvas = createMockCanvas();
        canvas.captureStream = () => canvasStream;
        return canvas;
      }

      if (tag === 'audio') {
        return createMockAudioElement();
      }

      return {};
    },
    ...overrides.document,
  };

  const Exporter = createVideoExporterFactory(mockWindow);

  return {
    Exporter,
    mockWindow,
    mockDocument,
    get audioContexts() {
      return audioContextInstances;
    },
    get recorders() {
      return recorderInstances;
    },
    get stoppedTracks() {
      return stoppedTracks;
    },
    get revokedUrls() {
      return revokedUrls;
    },
    get blobUrls() {
      return blobUrls;
    },
    get animationFrameId() {
      return animationFrameId;
    },
  };
}

describe('browser-support resolution presets', () => {
  it('defines 720p dimensions and bitrate', () => {
    assert.equal(RESOLUTION_PRESETS['720p'].width, 1280);
    assert.equal(RESOLUTION_PRESETS['720p'].height, 720);
    assert.equal(RESOLUTION_PRESETS['720p'].fps, 30);
    assert.equal(RESOLUTION_PRESETS['720p'].videoBitsPerSecond, 4_000_000);
  });

  it('defines 1080p dimensions and bitrate', () => {
    assert.equal(RESOLUTION_PRESETS['1080p'].width, 1920);
    assert.equal(RESOLUTION_PRESETS['1080p'].height, 1080);
    assert.equal(RESOLUTION_PRESETS['1080p'].videoBitsPerSecond, 7_500_000);
  });

  it('falls back to 720p for unknown resolution ids', () => {
    assert.deepEqual(getResolutionConfig('4k'), RESOLUTION_PRESETS['720p']);
  });
});

describe('browser-support mime preference', () => {
  it('prefers vp9 when supported', () => {
    const target = {
      MediaRecorder: {
        isTypeSupported: (mime) => mime === MIME_PREFERENCE[0],
      },
    };

    assert.equal(selectSupportedMimeType(target), MIME_PREFERENCE[0]);
  });

  it('falls back through the mime preference list', () => {
    const target = {
      MediaRecorder: {
        isTypeSupported: (mime) => mime === MIME_PREFERENCE[2],
      },
    };

    assert.equal(selectSupportedMimeType(target), MIME_PREFERENCE[2]);
  });

  it('returns null when MediaRecorder is unavailable', () => {
    assert.equal(selectSupportedMimeType({}), null);
  });
});

describe('browser-support capability detection', () => {
  it('reports supported browsers with required APIs', () => {
    const support = detectBrowserExportSupport({
      HTMLCanvasElement: { prototype: { captureStream: () => ({}) } },
      MediaRecorder: Object.assign(function MediaRecorder() {}, {
        isTypeSupported: () => true,
      }),
      AudioContext: class {
        constructor() {
          this.state = 'running';
        }

        createMediaStreamDestination() {
          return { stream: {} };
        }

        close() {}
      },
    });

    assert.equal(support.supported, true);
    assert.equal(support.mimeType, MIME_PREFERENCE[0]);
    assert.equal(support.unsupportedMessage, null);
  });

  it('reports unsupported browsers with guidance text', () => {
    const support = detectBrowserExportSupport({});

    assert.equal(support.supported, false);
    assert.match(support.unsupportedMessage, /Chrome or Edge/i);
  });
});

describe('canvas-renderer layout and wrapping', () => {
  it('scales layout for 1080p exports', () => {
    const layout720 = getCanvasLayout('720p');
    const layout1080 = getCanvasLayout('1080p');

    assert.equal(layout1080.scale, 1.5);
    assert.ok(layout1080.titleSize > layout720.titleSize);
  });

  it('wraps long title text to multiple lines', () => {
    const ctx = createMockCanvasContext();
    const lines = wrapText(ctx, 'One two three four five six seven eight nine ten', 80);

    assert.ok(lines.length > 1);
  });

  it('returns a blank line for empty text', () => {
    const ctx = createMockCanvasContext();
    assert.deepEqual(wrapText(ctx, '', 200), ['']);
  });
});

describe('canvas-renderer timing and theme', () => {
  it('parses theme colors for export rendering', () => {
    const theme = themeColorsForExport({
      backgroundPreset: 'midnight-blue',
      lyricSize: 'large',
      baseColor: '#ffffff',
      highlightColor: '#00ffaa',
    });

    assert.equal(theme.backgroundPreset, 'midnight-blue');
    assert.equal(theme.highlightColor, '#00ffaa');
  });

  it('derives lyric window metadata for export frames', () => {
    const metadata = exportFrameMetadata(sampleLines, 6);

    assert.equal(metadata.lyricWindow.current?.id, 'line-2');
    assert.equal(metadata.activeWord?.word?.id, 'word-3');
  });

  it('draws title and artist text without throwing', () => {
    const canvas = createMockCanvas();
    const renderer = createCanvasRenderer({
      canvas,
      lines: sampleLines,
      theme: {},
      title: 'Export Title',
      artist: 'Export Artist',
      resolutionId: '720p',
    });

    assert.doesNotThrow(() => renderer.drawFrame(1));
    assert.equal(renderer.theme.backgroundPreset, 'noir-gold');
  });
});

describe('filename helpers', () => {
  it('builds a safe export filename from title artist and resolution', () => {
    assert.equal(buildExportFilename('My Song', 'Artist Name', '1080p'), 'My-Song-Artist-Name-1080p.webm');
  });

  it('strips unsafe filename characters', () => {
    assert.equal(buildExportFilename('Bad:Name?', 'Artist/One', '720p'), 'BadName-ArtistOne-720p.webm');
  });

  it('removes html-like content from title segments', () => {
    assert.equal(
      buildExportFilename('<script>alert(1)</script>', null, '720p'),
      'scriptalert(1)script-720p.webm',
    );
  });

  it('formats file sizes for display', () => {
    assert.equal(formatFileSize(512), '512 B');
    assert.equal(formatFileSize(2048), '2.0 KB');
    assert.equal(formatFileSize(2 * 1024 * 1024), '2.0 MB');
  });
});

describe('exporter progress and constants', () => {
  it('calculates export progress as a percentage', () => {
    assert.equal(calculateExportProgress(15, 30), 50);
    assert.equal(calculateExportProgress(45, 30), 100);
  });

  it('returns zero progress for invalid durations', () => {
    assert.equal(calculateExportProgress(10, 0), 0);
    assert.equal(calculateExportProgress(10, Number.NaN), 0);
  });

  it('exposes export lifecycle states', () => {
    assert.equal(EXPORT_STATES.IDLE, 'idle');
    assert.equal(EXPORT_STATES.COMPLETED, 'completed');
    assert.equal(EXPORT_STATES.FAILED, 'failed');
  });

  it('uses shared recorder timing constants', () => {
    assert.equal(AUDIO_BITS_PER_SECOND, 192_000);
    assert.equal(RECORDER_TIMESLICE_MS, 1000);
  });
});

describe('exporter lifecycle', () => {
  it('completes a successful export and exposes download metadata', async () => {
    const { Exporter, mockDocument } = createSuccessfulExportMocks();
    const states = [];
    const exporter = new Exporter({
      document: mockDocument,
      lines: sampleLines,
      theme: {},
      title: 'Ready Track',
      artist: 'Ready Artist',
      audioUrl: '/karaoke/audio',
      onStateChange: (payload) => states.push(payload.state),
    });

    await exporter.start();

    assert.equal(exporter.state, EXPORT_STATES.COMPLETED);
    assert.ok(exporter.resultBlob.size > 0);
    assert.equal(exporter.filename, 'Ready-Track-Ready-Artist-720p.webm');
    assert.ok(states.includes(EXPORT_STATES.PREPARING));
    assert.ok(states.includes(EXPORT_STATES.RECORDING));
    assert.ok(states.includes(EXPORT_STATES.FINALIZING));
    assert.ok(states.includes(EXPORT_STATES.COMPLETED));
  });

  it('rejects concurrent export attempts', async () => {
    const { Exporter, mockDocument } = createSuccessfulExportMocks();
    const exporter = new Exporter({
      document: mockDocument,
      lines: sampleLines,
      audioUrl: '/karaoke/audio',
    });

    exporter._running = true;

    await assert.rejects(() => exporter.start(), /already running/i);
  });

  it('cancels an in-progress export', async () => {
    const { Exporter, mockDocument } = createSuccessfulExportMocks();
    const exporter = new Exporter({
      document: mockDocument,
      lines: sampleLines,
      audioUrl: '/karaoke/audio',
    });

    exporter.setState(EXPORT_STATES.RECORDING);
    exporter._running = true;

    await exporter.cancel();

    assert.equal(exporter.state, EXPORT_STATES.CANCELLED);
    assert.equal(exporter.isBusy, false);
  });
});

describe('exporter failure and cleanup paths', () => {
  it('fails when instrumental fetch is rejected', async () => {
    const { Exporter, mockDocument, mockWindow } = createSuccessfulExportMocks({
      window: {
        fetch: async () => ({ ok: false, blob: async () => ({ size: 0 }) }),
      },
    });

    const exporter = new Exporter({
      document: mockDocument,
      window: mockWindow,
      fetchImpl: mockWindow.fetch,
      lines: sampleLines,
      audioUrl: '/karaoke/audio',
    });

    await exporter.start();

    assert.equal(exporter.state, EXPORT_STATES.FAILED);
    assert.match(exporter.errorMessage, /instrumental audio/i);
  });

  it('fails when MediaRecorder throws during setup', async () => {
    class BrokenMediaRecorder {
      constructor() {
        throw new Error('recorder unavailable');
      }
    }
    BrokenMediaRecorder.isTypeSupported = () => true;

    const { Exporter, mockDocument, mockWindow } = createSuccessfulExportMocks({
      window: {
        MediaRecorder: BrokenMediaRecorder,
      },
    });

    const exporter = new Exporter({
      document: mockDocument,
      window: mockWindow,
      MediaRecorderClass: BrokenMediaRecorder,
      lines: sampleLines,
      audioUrl: '/karaoke/audio',
    });

    await exporter.start();

    assert.equal(exporter.state, EXPORT_STATES.FAILED);
    assert.match(exporter.errorMessage, /cannot start WebM recording/i);
  });

  it('fails when the exported blob is empty', async () => {
    class EmptyChunkRecorder {
      constructor() {
        this.state = 'inactive';
        this.listeners = new Map();
      }

      addEventListener(event, handler) {
        if (!this.listeners.has(event)) {
          this.listeners.set(event, []);
        }
        this.listeners.get(event).push(handler);
      }

      start() {
        this.state = 'recording';
        queueMicrotask(() => {
          for (const handler of this.listeners.get('dataavailable') ?? []) {
            handler({ data: { size: 0 } });
          }
        });
      }

      stop() {
        this.state = 'inactive';
        queueMicrotask(() => {
          for (const handler of this.listeners.get('stop') ?? []) {
            handler();
          }
        });
      }
    }
    EmptyChunkRecorder.isTypeSupported = () => true;

    const { Exporter, mockDocument, mockWindow } = createSuccessfulExportMocks({
      window: {
        MediaRecorder: EmptyChunkRecorder,
      },
    });

    const exporter = new Exporter({
      document: mockDocument,
      window: mockWindow,
      MediaRecorderClass: EmptyChunkRecorder,
      lines: sampleLines,
      audioUrl: '/karaoke/audio',
    });

    await exporter.start();

    assert.equal(exporter.state, EXPORT_STATES.FAILED);
    assert.match(exporter.errorMessage, /empty/i);
  });

  it('fails when MediaRecorder emits an encoder error', async () => {
    const { Exporter, mockDocument } = createSuccessfulExportMocks();
    const exporter = new Exporter({
      document: mockDocument,
      lines: sampleLines,
      audioUrl: '/karaoke/audio',
    });

    exporter.setState(EXPORT_STATES.RECORDING);
    exporter._running = true;
    exporter._recorder = {
      state: 'recording',
      stop: () => {},
    };

    await exporter.fail('Recording stopped because of a browser encoder error.');

    assert.equal(exporter.state, EXPORT_STATES.FAILED);
    assert.match(exporter.errorMessage, /encoder error/i);
  });

  it('stops stream tracks during cleanup', async () => {
    const { Exporter, mockDocument, stoppedTracks } = createSuccessfulExportMocks();
    const exporter = new Exporter({
      document: mockDocument,
      lines: sampleLines,
      audioUrl: '/karaoke/audio',
    });

    await exporter.start();
    await exporter.cleanup();

    assert.ok(stoppedTracks.includes('video'));
    assert.ok(stoppedTracks.includes('audio'));
  });

  it('cancels animation frames during cleanup', async () => {
    const { Exporter, mockDocument } = createSuccessfulExportMocks();
    const exporter = new Exporter({
      document: mockDocument,
      lines: sampleLines,
      audioUrl: '/karaoke/audio',
    });

    exporter._animationFrameId = 42;
    await exporter.cleanup();

    assert.equal(exporter._animationFrameId, null);
  });

  it('closes AudioContext instances during cleanup', async () => {
    const { Exporter, mockDocument, audioContexts } = createSuccessfulExportMocks();
    const exporter = new Exporter({
      document: mockDocument,
      lines: sampleLines,
      audioUrl: '/karaoke/audio',
    });

    await exporter.start();

    assert.ok(audioContexts.length > 0);
    assert.ok(audioContexts.every((context) => context.state === 'closed'));
  });

  it('revokes blob urls during cleanup when result is not preserved', async () => {
    const { Exporter, mockDocument, revokedUrls } = createSuccessfulExportMocks();
    const exporter = new Exporter({
      document: mockDocument,
      lines: sampleLines,
      audioUrl: '/karaoke/audio',
    });

    exporter.trackBlobUrl('blob:temp-1');
    await exporter.cleanup();

    assert.deepEqual(revokedUrls, ['blob:temp-1']);
  });

  it('destroy is idempotent and clears navigation handlers', () => {
    const { Exporter, mockDocument, mockWindow } = createSuccessfulExportMocks();
    let removed = 0;
    mockWindow.removeEventListener = () => {
      removed += 1;
    };

    const exporter = new Exporter({
      document: mockDocument,
      window: mockWindow,
      lines: sampleLines,
      audioUrl: '/karaoke/audio',
    });

    exporter._beforeUnloadHandler = () => {};
    exporter.destroy();
    exporter.destroy();

    assert.equal(exporter._destroyed, true);
    assert.ok(removed >= 1);
  });

  it('fails early in unsupported browsers', async () => {
    const target = {
      HTMLCanvasElement: { prototype: {} },
      document: { createElement: () => ({}) },
      addEventListener: () => {},
      removeEventListener: () => {},
    };

    const Exporter = createVideoExporterFactory(target);
    const exporter = new Exporter({
      window: target,
      lines: sampleLines,
      audioUrl: '/karaoke/audio',
    });

    await exporter.start();

    assert.equal(exporter.state, EXPORT_STATES.FAILED);
    assert.match(exporter.errorMessage, /Chrome or Edge/i);
  });
});
