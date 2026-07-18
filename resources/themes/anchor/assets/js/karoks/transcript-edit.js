/** Apply a single word text change while preserving IDs and timing. */
export function applyWordText(lines, wordId, text) {
  let changed = false;

  const nextLines = lines.map((line) => {
    let lineChanged = false;
    const words = line.words.map((word) => {
      if (word.id !== wordId) {
        return word;
      }

      if (word.text === text) {
        return word;
      }

      lineChanged = true;
      changed = true;
      return { ...word, text };
    });

    return lineChanged ? { ...line, words } : line;
  });

  return changed ? nextLines : lines;
}

/** Collect only changed words compared to saved lines. */
export function collectWordChanges(currentLines, savedLines) {
  const savedIndex = buildWordTextIndex(savedLines);
  const changes = {};

  for (const line of currentLines) {
    for (const word of line.words) {
      if (savedIndex[word.id] !== word.text) {
        changes[word.id] = word.text;
      }
    }
  }

  return changes;
}

export function buildWordTextIndex(lines) {
  const index = {};

  for (const line of lines) {
    for (const word of line.words) {
      index[word.id] = word.text;
    }
  }

  return index;
}

export function cloneLines(lines) {
  try {
    return structuredClone(lines);
  } catch {
    return JSON.parse(JSON.stringify(lines));
  }
}
