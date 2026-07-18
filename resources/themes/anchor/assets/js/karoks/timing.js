/** Clamp a progress ratio into the inclusive [0, 1] range. */
export function clampProgress(progress) {
  if (Number.isNaN(progress) || !Number.isFinite(progress)) {
    return 0;
  }
  if (progress < 0) return 0;
  if (progress > 1) return 1;
  return progress;
}

/**
 * Select the active lyric line for a playback time.
 * Prefers the line whose [start, end) contains currentTime.
 * At exact line boundaries, the later line wins once currentTime >= its start.
 */
export function getActiveLine(lines, currentTime) {
  if (!lines || lines.length === 0) return null;

  for (let i = 0; i < lines.length; i += 1) {
    const line = lines[i];
    if (currentTime >= line.start && currentTime < line.end) {
      return line;
    }
  }

  const last = lines[lines.length - 1];
  if (currentTime >= last.start && currentTime <= last.end) {
    return last;
  }

  return null;
}

/** Select the active word within a line for a playback time. */
export function getActiveWord(words, currentTime) {
  if (!words || words.length === 0) return null;

  for (let i = 0; i < words.length; i += 1) {
    const word = words[i];
    if (currentTime >= word.start && currentTime < word.end) {
      return word;
    }
  }

  const last = words[words.length - 1];
  if (currentTime >= last.start && currentTime <= last.end) {
    return last;
  }

  return null;
}

/** Word highlight progress clamped to [0, 1]. Zero-duration words never divide by zero. */
export function getWordProgress(word, currentTime) {
  const duration = word.end - word.start;
  if (duration <= 0) {
    return currentTime >= word.end ? 1 : 0;
  }
  return clampProgress((currentTime - word.start) / duration);
}

export function getActiveWordState(lines, currentTime) {
  const line = getActiveLine(lines, currentTime);
  if (!line) {
    return { word: null, progress: 0 };
  }

  const word = getActiveWord(line.words, currentTime);
  if (!word) {
    return { word: null, progress: 0 };
  }

  return {
    word,
    progress: getWordProgress(word, currentTime),
  };
}

export function getLyricWindow(lines, currentTime) {
  if (!lines || lines.length === 0) {
    return { previous: null, current: null, next: null };
  }

  const active = getActiveLine(lines, currentTime);
  if (active) {
    const index = lines.findIndex((line) => line.id === active.id);
    return {
      previous: index > 0 ? lines[index - 1] : null,
      current: active,
      next: index >= 0 && index < lines.length - 1 ? lines[index + 1] : null,
    };
  }

  const nextIndex = lines.findIndex((line) => currentTime < line.start);
  if (nextIndex === -1) {
    return {
      previous: lines[lines.length - 1] ?? null,
      current: null,
      next: null,
    };
  }

  if (nextIndex === 0) {
    return {
      previous: null,
      current: null,
      next: lines[0] ?? null,
    };
  }

  return {
    previous: lines[nextIndex - 1] ?? null,
    current: null,
    next: lines[nextIndex] ?? null,
  };
}

/** Format seconds as m:ss (e.g. 65 -> "1:05"). */
export function formatTime(seconds) {
  if (!Number.isFinite(seconds) || seconds < 0) {
    return '0:00';
  }
  const total = Math.floor(seconds);
  const mins = Math.floor(total / 60);
  const secs = total % 60;
  return `${mins}:${secs.toString().padStart(2, '0')}`;
}

export function transcriptDuration(lines) {
  if (!lines || lines.length === 0) return 0;
  return lines[lines.length - 1].end;
}

export function derivePlayerView(lines, currentTime) {
  return {
    lyricWindow: getLyricWindow(lines, currentTime),
    activeWord: getActiveWordState(lines, currentTime),
  };
}

export function wordHighlightStyle(progress) {
  const pct = Math.round(clampProgress(progress) * 100);
  return `--word-progress: ${pct}%`;
}

export function isWordPast(word, currentTime, activeWordId) {
  return word.id !== activeWordId && currentTime >= word.end;
}

export function isWordActive(word, activeWordId) {
  return word.id === activeWordId;
}
