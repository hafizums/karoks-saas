const UNSAFE_FILENAME_CHARS = /[<>:"/\\|?*\x00-\x1f]/g;

/**
 * @param {string} title
 * @param {string|null|undefined} artist
 * @param {string} resolutionId
 */
export function buildExportFilename(title, artist, resolutionId) {
  const parts = [title, artist, resolutionId]
    .filter((part) => typeof part === 'string' && part.trim() !== '')
    .map((part) => part.trim().replace(UNSAFE_FILENAME_CHARS, '').replace(/\s+/g, '-'));

  const base = parts.length > 0 ? parts.join('-') : 'karoks-export';
  const limited = base.slice(0, 120);

  return `${limited}.webm`;
}

/**
 * @param {number} bytes
 */
export function formatFileSize(bytes) {
  if (!Number.isFinite(bytes) || bytes < 0) {
    return '0 B';
  }

  if (bytes < 1024) {
    return `${bytes} B`;
  }

  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`;
  }

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
