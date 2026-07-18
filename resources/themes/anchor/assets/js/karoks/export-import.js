export const EXPORT_SCHEMA = 'karoks-project';
export const EXPORT_VERSION = 1;

export function validateImportPayload(payload) {
  if (!payload || typeof payload !== 'object') {
    return 'Import file must contain a JSON object.';
  }

  if (payload.schema !== EXPORT_SCHEMA) {
    return 'Unsupported import schema.';
  }

  if (payload.version !== EXPORT_VERSION) {
    return 'Unsupported import version.';
  }

  if (!payload.project || typeof payload.project !== 'object') {
    return 'Import project payload is missing.';
  }

  if (typeof payload.project.title !== 'string') {
    return 'Imported title is invalid.';
  }

  if (!payload.project.transcript || typeof payload.project.transcript !== 'object') {
    return 'Imported transcript is invalid.';
  }

  return null;
}

export function buildClientExportPayload(state) {
  return {
    schema: EXPORT_SCHEMA,
    version: EXPORT_VERSION,
    exportedAt: new Date().toISOString(),
    project: {
      title: state.title,
      artist: state.artist ?? '',
      transcript: {
        version: 1,
        lines: state.lines,
      },
      theme: state.theme,
    },
  };
}

export function timingSkeletonSignature(lines) {
  return lines.map((line) => ({
    id: line.id,
    start: line.start,
    end: line.end,
    words: line.words.map((word) => ({
      id: word.id,
      start: word.start,
      end: word.end,
    })),
  }));
}

export function timingSkeletonsMatch(currentLines, importedLines) {
  return JSON.stringify(timingSkeletonSignature(currentLines))
    === JSON.stringify(timingSkeletonSignature(importedLines));
}
