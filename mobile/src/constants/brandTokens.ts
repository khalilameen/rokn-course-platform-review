/**
 * Rokn's cross-surface colour source of truth.
 *
 * Components consume Palette/Colors rather than repeating these values. Native
 * integrations that cannot depend on the UI layer (for example notification
 * channels) may consume BrandColors directly.
 */
export const BrandColors = {
  canvas: '#070A10',
  canvasSoft: '#0B1018',
  surface: '#111620',
  surfaceRaised: '#171D29',
  surfacePressed: '#1D2533',
  line: '#252C38',
  lineSoft: 'rgba(255,255,255,0.07)',
  primary: '#2C69DB',
  primaryPressed: '#245CC7',
  primarySoft: 'rgba(52,120,246,0.14)',
  coin: '#D8A63C',
  coinSoft: 'rgba(216,166,60,0.13)',
  success: '#48B98A',
  danger: '#F06469',
  text: '#F7F9FC',
  textMuted: '#9BA6B8',
  textFaint: '#768297',
  overlay: 'rgba(3,5,9,0.76)',
} as const;

