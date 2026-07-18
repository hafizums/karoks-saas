export const BACKGROUND_PRESETS = ['noir-gold', 'midnight-blue', 'neon-berry'];

export const LYRIC_SIZES = ['small', 'medium', 'large'];

export const DEFAULT_THEME = {
  backgroundPreset: 'noir-gold',
  lyricSize: 'medium',
  baseColor: '#f4f0e6',
  highlightColor: '#f0c14b',
};

const HEX_COLOR = /^#[0-9a-fA-F]{6}$/;

export function sanitizeHexColor(value) {
  if (typeof value !== 'string') return null;
  const trimmed = value.trim();
  if (!HEX_COLOR.test(trimmed)) return null;
  return trimmed.toLowerCase();
}

export function parseTheme(input) {
  const defaults = { ...DEFAULT_THEME };
  if (!input || typeof input !== 'object') {
    return defaults;
  }

  return {
    backgroundPreset: BACKGROUND_PRESETS.includes(input.backgroundPreset)
      ? input.backgroundPreset
      : defaults.backgroundPreset,
    lyricSize: LYRIC_SIZES.includes(input.lyricSize) ? input.lyricSize : defaults.lyricSize,
    baseColor: sanitizeHexColor(input.baseColor) ?? defaults.baseColor,
    highlightColor: sanitizeHexColor(input.highlightColor) ?? defaults.highlightColor,
  };
}

export function themeToCssVars(theme) {
  return {
    '--lyric-base': theme.baseColor,
    '--lyric-highlight': theme.highlightColor,
    '--lyric-highlight-glow': `${theme.highlightColor}47`,
  };
}
