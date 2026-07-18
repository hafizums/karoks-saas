import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  clampProgress,
  derivePlayerView,
  formatTime,
  getActiveLine,
  getActiveWord,
  getActiveWordState,
  getLyricWindow,
  getWordProgress,
  wordHighlightStyle,
} from '../../resources/themes/anchor/assets/js/karoks/timing.js';

const lines = [
  {
    id: 'line-1',
    start: 0.5,
    end: 4.0,
    words: [
      { id: 'word-1', text: 'Hello', start: 0.5, end: 1.2 },
      { id: 'word-2', text: 'world', start: 1.2, end: 2.5 },
      { id: 'word-3', text: 'again', start: 2.8, end: 4.0 },
    ],
  },
  {
    id: 'line-2',
    start: 5.0,
    end: 8.0,
    words: [
      { id: 'word-4', text: 'Next', start: 5.0, end: 5.8 },
      { id: 'word-5', text: 'line', start: 5.8, end: 8.0 },
    ],
  },
  {
    id: 'line-3',
    start: 9.0,
    end: 12.0,
    words: [
      { id: 'word-6', text: 'Final', start: 9.0, end: 10.0 },
      { id: 'word-7', text: 'words', start: 10.0, end: 12.0 },
    ],
  },
];

describe('clampProgress', () => {
  it('clamps below 0 and above 1', () => {
    assert.equal(clampProgress(-0.5), 0);
    assert.equal(clampProgress(0.4), 0.4);
    assert.equal(clampProgress(1.7), 1);
  });

  it('treats non-finite values as 0', () => {
    assert.equal(clampProgress(Number.NaN), 0);
    assert.equal(clampProgress(Number.POSITIVE_INFINITY), 0);
  });
});

describe('getActiveLine', () => {
  it('returns null before the first line and during gaps', () => {
    assert.equal(getActiveLine(lines, 0), null);
    assert.equal(getActiveLine(lines, 4.5), null);
  });

  it('selects the containing line', () => {
    assert.equal(getActiveLine(lines, 1.0)?.id, 'line-1');
    assert.equal(getActiveLine(lines, 5.5)?.id, 'line-2');
  });

  it('keeps the final line visible at its exact end boundary', () => {
    assert.equal(getActiveLine(lines, 12)?.id, 'line-3');
  });

  it('returns null after the song ends', () => {
    assert.equal(getActiveLine(lines, 12.01), null);
  });
});

describe('getActiveWord', () => {
  const words = lines[0].words;

  it('returns null in word gaps and before the first word', () => {
    assert.equal(getActiveWord(words, 0.2), null);
    assert.equal(getActiveWord(words, 2.6), null);
  });

  it('selects the containing word', () => {
    assert.equal(getActiveWord(words, 0.8)?.id, 'word-1');
    assert.equal(getActiveWord(words, 1.5)?.id, 'word-2');
  });

  it('includes the final word end boundary', () => {
    assert.equal(getActiveWord(words, 4)?.id, 'word-3');
  });
});

describe('getWordProgress', () => {
  const word = lines[0].words[0];

  it('computes and clamps progress', () => {
    assert.equal(getWordProgress(word, 0.2), 0);
    assert.equal(getWordProgress(word, 0.5), 0);
    assert.equal(getWordProgress(word, 1.2), 1);
  });

  it('handles zero-duration words without dividing by zero', () => {
    const zero = { id: 'z', text: 'x', start: 1, end: 1 };
    assert.equal(getWordProgress(zero, 0.9), 0);
    assert.equal(getWordProgress(zero, 1), 1);
  });

  it('never produces NaN for wordHighlightStyle', () => {
    const style = wordHighlightStyle(getWordProgress(word, 0.85));
    assert.match(style, /^--word-progress: \d+%$/);
    assert.doesNotMatch(style, /NaN/);
  });
});

describe('getActiveWordState', () => {
  it('returns empty state when no word is active', () => {
    assert.deepEqual(getActiveWordState(lines, 0), { word: null, progress: 0 });
    assert.deepEqual(getActiveWordState(lines, 2.6), { word: null, progress: 0 });
  });

  it('returns the active word with clamped progress', () => {
    const state = getActiveWordState(lines, 0.85);
    assert.equal(state.word?.id, 'word-1');
    assert.ok(Math.abs(state.progress - 0.5) < 0.00001);
  });
});

describe('getLyricWindow', () => {
  it('exposes previous, current, and next around the active line', () => {
    const window = getLyricWindow(lines, 6);
    assert.equal(window.previous?.id, 'line-1');
    assert.equal(window.current?.id, 'line-2');
    assert.equal(window.next?.id, 'line-3');
  });

  it('handles beginning, gaps, and end gracefully', () => {
    assert.deepEqual(getLyricWindow(lines, 0), {
      previous: null,
      current: null,
      next: lines[0],
    });

    const gap = getLyricWindow(lines, 4.5);
    assert.equal(gap.previous?.id, 'line-1');
    assert.equal(gap.current, null);
    assert.equal(gap.next?.id, 'line-2');

    const after = getLyricWindow(lines, 13);
    assert.equal(after.previous?.id, 'line-3');
    assert.equal(after.current, null);
    assert.equal(after.next, null);
  });
});

describe('derivePlayerView seeking', () => {
  it('updates state when seeking backward and forward', () => {
    const forward = derivePlayerView(lines, 6);
    assert.equal(forward.lyricWindow.current?.id, 'line-2');

    const backward = derivePlayerView(lines, 0.8);
    assert.equal(backward.lyricWindow.current?.id, 'line-1');
    assert.equal(backward.activeWord.word?.id, 'word-1');
  });
});

describe('formatTime', () => {
  it('formats as m:ss', () => {
    assert.equal(formatTime(0), '0:00');
    assert.equal(formatTime(9), '0:09');
    assert.equal(formatTime(65), '1:05');
    assert.equal(formatTime(-1), '0:00');
  });
});

describe('html lyric safety', () => {
  it('keeps markup characters in timing data as plain strings', () => {
    const malicious = [
      {
        id: 'line-x',
        start: 0,
        end: 2,
        words: [{ id: 'word-x', text: '<script>alert(1)</script>', start: 0, end: 2 }],
      },
    ];

    const state = getActiveWordState(malicious, 1);
    assert.equal(state.word?.text, '<script>alert(1)</script>');
    assert.equal(String(state.word?.text).includes('<script>'), true);
  });
});
