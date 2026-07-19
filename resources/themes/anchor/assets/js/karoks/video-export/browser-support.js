export const MIME_PREFERENCE = [
  'video/webm;codecs=vp9,opus',
  'video/webm;codecs=vp8,opus',
  'video/webm',
];

export const RESOLUTION_PRESETS = {
  '720p': {
    id: '720p',
    label: '720p',
    width: 1280,
    height: 720,
    fps: 30,
    videoBitsPerSecond: 4_000_000,
  },
  '1080p': {
    id: '1080p',
    label: '1080p',
    width: 1920,
    height: 1080,
    fps: 30,
    videoBitsPerSecond: 7_500_000,
  },
};

export const AUDIO_BITS_PER_SECOND = 192_000;
export const RECORDER_TIMESLICE_MS = 1000;

/**
 * @param {typeof globalThis} target
 */
export function detectBrowserExportSupport(target = globalThis) {
  const canvasProto = target.HTMLCanvasElement?.prototype;
  const hasCaptureStream = typeof canvasProto?.captureStream === 'function';
  const hasMediaRecorder = typeof target.MediaRecorder === 'function';
  const AudioContextClass = target.AudioContext || target.webkitAudioContext;
  const hasAudioContext = typeof AudioContextClass === 'function';

  let canCreateMediaStreamDestination = false;
  if (hasAudioContext) {
    try {
      const ctx = new AudioContextClass();
      canCreateMediaStreamDestination = typeof ctx.createMediaStreamDestination === 'function';
      ctx.close?.();
    } catch {
      canCreateMediaStreamDestination = false;
    }
  }

  const mimeType = selectSupportedMimeType(target);
  const supported = hasCaptureStream
    && hasMediaRecorder
    && hasAudioContext
    && canCreateMediaStreamDestination
    && mimeType !== null;

  return {
    supported,
    hasCaptureStream,
    hasMediaRecorder,
    hasAudioContext,
    canCreateMediaStreamDestination,
    mimeType,
    unsupportedMessage: supported
      ? null
      : 'WebM video export requires a current desktop Chrome or Edge browser with MediaRecorder and canvas capture support.',
  };
}

/**
 * @param {typeof globalThis} target
 * @returns {string|null}
 */
export function selectSupportedMimeType(target = globalThis) {
  if (typeof target.MediaRecorder?.isTypeSupported !== 'function') {
    return null;
  }

  for (const mimeType of MIME_PREFERENCE) {
    if (target.MediaRecorder.isTypeSupported(mimeType)) {
      return mimeType;
    }
  }

  return null;
}

/**
 * @param {string} resolutionId
 */
export function getResolutionConfig(resolutionId) {
  return RESOLUTION_PRESETS[resolutionId] ?? RESOLUTION_PRESETS['720p'];
}
