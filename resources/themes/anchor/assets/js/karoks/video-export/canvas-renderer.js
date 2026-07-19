import { parseTheme } from '../theme.js';
import {
  derivePlayerView,
  getWordProgress,
} from '../timing.js';

export const BACKGROUND_PRESET_COLORS = {
  'noir-gold': {
    stageGlow: '#221c10',
    stageTop: '#0e0e12',
    bgDeep: '#0a0a0c',
  },
  'midnight-blue': {
    stageGlow: '#13243a',
    stageTop: '#0a1220',
    bgDeep: '#070b14',
  },
  'neon-berry': {
    stageGlow: '#2a1230',
    stageTop: '#140a18',
    bgDeep: '#0c070f',
  },
};

const FONT_STACK = 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';

const LYRIC_SIZE_SCALE = {
  small: 0.85,
  medium: 1,
  large: 1.15,
};

/**
 * @param {string} resolutionId
 */
export function getCanvasLayout(resolutionId) {
  const scale = resolutionId === '1080p' ? 1.5 : 1;

  return {
    scale,
    paddingX: Math.round(64 * scale),
    paddingY: Math.round(48 * scale),
    titleSize: Math.round(40 * scale),
    artistSize: Math.round(24 * scale),
    currentLyricSize: Math.round(52 * scale),
    neighborLyricSize: Math.round(30 * scale),
    brandSize: Math.round(28 * scale),
    lineGap: Math.round(12 * scale),
    maxLyricLines: 3,
  };
}

function hexToRgb(hex) {
  const normalized = hex.replace('#', '');
  const value = Number.parseInt(normalized, 16);
  return {
    r: (value >> 16) & 255,
    g: (value >> 8) & 255,
    b: value & 255,
  };
}

function withAlpha(hex, alpha) {
  const { r, g, b } = hexToRgb(hex);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function drawBackground(ctx, width, height, theme) {
  const preset = BACKGROUND_PRESET_COLORS[theme.backgroundPreset] ?? BACKGROUND_PRESET_COLORS['noir-gold'];

  const gradient = ctx.createLinearGradient(0, 0, 0, height);
  gradient.addColorStop(0, preset.stageTop);
  gradient.addColorStop(1, preset.bgDeep);
  ctx.fillStyle = gradient;
  ctx.fillRect(0, 0, width, height);

  const glow = ctx.createRadialGradient(width / 2, height * 0.18, 0, width / 2, height * 0.18, width * 0.45);
  glow.addColorStop(0, withAlpha(preset.stageGlow, 0.95));
  glow.addColorStop(0.55, withAlpha(preset.stageGlow, 0));
  ctx.fillStyle = glow;
  ctx.fillRect(0, 0, width, height);
}

/**
 * @param {CanvasRenderingContext2D} ctx
 * @param {string} text
 * @param {number} maxWidth
 */
export function wrapText(ctx, text, maxWidth) {
  if (!text) {
    return [''];
  }

  const words = String(text).split(/\s+/).filter(Boolean);
  if (words.length === 0) {
    return [''];
  }

  const lines = [];
  let current = words[0];

  for (let i = 1; i < words.length; i += 1) {
    const candidate = `${current} ${words[i]}`;
    if (ctx.measureText(candidate).width <= maxWidth) {
      current = candidate;
    } else {
      lines.push(current);
      current = words[i];
    }
  }

  lines.push(current);
  return lines;
}

/**
 * @param {CanvasRenderingContext2D} ctx
 * @param {Array<{text: string}>} words
 * @param {number} maxWidth
 */
function wrapWordLine(ctx, words, maxWidth) {
  const lines = [];
  let currentWords = [];

  for (const word of words) {
    const attempt = [...currentWords, word];
    const attemptText = attempt.map((item) => item.text).join(' ');
    if (currentWords.length === 0 || ctx.measureText(attemptText).width <= maxWidth) {
      currentWords = attempt;
    } else {
      lines.push(currentWords);
      currentWords = [word];
    }
  }

  if (currentWords.length > 0) {
    lines.push(currentWords);
  }

  return lines;
}

/**
 * @param {CanvasRenderingContext2D} ctx
 * @param {Array<{id: string, text: string}>} words
 * @param {number} x
 * @param {number} y
 * @param {object} activeWord
 * @param {number} currentTime
 * @param {string} baseColor
 * @param {string} highlightColor
 * @param {string} dimColor
 */
function drawWordLine(ctx, words, x, y, activeWord, currentTime, baseColor, highlightColor, dimColor) {
  let cursorX = x;

  for (const word of words) {
    const isActive = activeWord?.word?.id === word.id;
    const isPast = !isActive && currentTime >= word.end;
    const fillColor = isPast ? highlightColor : isActive ? baseColor : dimColor;

    ctx.fillStyle = fillColor;
    ctx.fillText(word.text, cursorX, y);

    if (isActive) {
      const progress = getWordProgress(activeWord.word, currentTime);
      const width = ctx.measureText(word.text).width;
      const fontSize = Number.parseFloat(String(ctx.font).match(/(\d+(?:\.\d+)?)px/)?.[1] ?? '48');
      ctx.save();
      ctx.beginPath();
      ctx.rect(cursorX, y - fontSize, width * progress, fontSize * 1.4);
      ctx.clip();
      ctx.fillStyle = highlightColor;
      ctx.fillText(word.text, cursorX, y);
      ctx.restore();
    }

    cursorX += ctx.measureText(`${word.text} `).width;
  }
}

/**
 * @param {object} options
 */
export function createCanvasRenderer(options) {
  const canvas = options.canvas;
  const ctx = canvas.getContext('2d');
  const theme = parseTheme(options.theme);
  const layout = getCanvasLayout(options.resolutionId);
  const lyricScale = LYRIC_SIZE_SCALE[theme.lyricSize] ?? 1;

  const title = typeof options.title === 'string' ? options.title : '';
  const artist = typeof options.artist === 'string' ? options.artist : '';
  const lines = Array.isArray(options.lines) ? options.lines : [];

  function drawFrame(currentTime) {
    const width = canvas.width;
    const height = canvas.height;
    const maxTextWidth = width - layout.paddingX * 2;

    drawBackground(ctx, width, height, theme);

    ctx.textAlign = 'center';
    ctx.textBaseline = 'top';
    ctx.font = `600 ${layout.brandSize}px ${FONT_STACK}`;
    ctx.fillStyle = theme.highlightColor;
    ctx.fillText('Karoks', width / 2, layout.paddingY);

    ctx.font = `700 ${layout.titleSize}px ${FONT_STACK}`;
    ctx.fillStyle = theme.baseColor;
    const titleLines = wrapText(ctx, title, maxTextWidth).slice(0, 2);
    let cursorY = layout.paddingY + layout.brandSize + layout.lineGap * 2;
    for (const line of titleLines) {
      ctx.fillText(line, width / 2, cursorY);
      cursorY += layout.titleSize + 4;
    }

    if (artist.trim() !== '') {
      ctx.font = `500 ${layout.artistSize}px ${FONT_STACK}`;
      ctx.fillStyle = withAlpha(theme.baseColor, 0.62);
      const artistLines = wrapText(ctx, artist, maxTextWidth).slice(0, 1);
      for (const line of artistLines) {
        ctx.fillText(line, width / 2, cursorY);
        cursorY += layout.artistSize + layout.lineGap;
      }
    }

    const lyricAreaTop = height * 0.38;
    const lyricAreaHeight = height * 0.42;
    const view = derivePlayerView(lines, currentTime);
    const activeWord = view.activeWord;
    const dimColor = withAlpha(theme.baseColor, 0.45);
    const neighborSize = Math.round(layout.neighborLyricSize * lyricScale);
    const currentSize = Math.round(layout.currentLyricSize * lyricScale);

    ctx.textAlign = 'center';

    if (view.lyricWindow.previous) {
      ctx.font = `500 ${neighborSize}px ${FONT_STACK}`;
      ctx.fillStyle = dimColor;
      const prevLines = wrapText(ctx, view.lyricWindow.previous.words.map((w) => w.text).join(' '), maxTextWidth).slice(0, 1);
      ctx.fillText(prevLines[0] ?? '', width / 2, lyricAreaTop);
    }

    const currentLineY = lyricAreaTop + lyricAreaHeight * 0.35;
    if (view.lyricWindow.current) {
      ctx.font = `700 ${currentSize}px ${FONT_STACK}`;
      const wrapped = wrapWordLine(ctx, view.lyricWindow.current.words, maxTextWidth).slice(0, layout.maxLyricLines);
      let lineY = currentLineY;
      for (const wordLine of wrapped) {
        const lineWidth = wordLine.reduce((sum, word, index) => {
          const spacer = index > 0 ? ctx.measureText(' ').width : 0;
          return sum + spacer + ctx.measureText(word.text).width;
        }, 0);
        const startX = (width - lineWidth) / 2;
        ctx.textAlign = 'left';
        drawWordLine(ctx, wordLine, startX, lineY, activeWord, currentTime, theme.baseColor, theme.highlightColor, dimColor);
        ctx.textAlign = 'center';
        lineY += currentSize + layout.lineGap;
      }
    } else {
      ctx.font = `500 ${neighborSize}px ${FONT_STACK}`;
      ctx.fillStyle = dimColor;
      ctx.fillText('\u00a0', width / 2, currentLineY);
    }

    if (view.lyricWindow.next) {
      ctx.font = `500 ${neighborSize}px ${FONT_STACK}`;
      ctx.fillStyle = dimColor;
      const nextLines = wrapText(ctx, view.lyricWindow.next.words.map((w) => w.text).join(' '), maxTextWidth).slice(0, 1);
      ctx.fillText(nextLines[0] ?? '', width / 2, lyricAreaTop + lyricAreaHeight * 0.78);
    }
  }

  return {
    drawFrame,
    theme,
    layout,
  };
}

/**
 * @param {object} themeInput
 */
export function themeColorsForExport(themeInput) {
  return parseTheme(themeInput);
}

/**
 * @param {Array<object>} lines
 * @param {number} currentTime
 */
export function exportFrameMetadata(lines, currentTime) {
  return derivePlayerView(lines, currentTime);
}
