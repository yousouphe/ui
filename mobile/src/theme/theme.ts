// Aike design tokens — mirrors the web/PWA look so the two clients feel like one product.
// Light theme first; dark tokens are defined but only shipped once audited across all screens.

export const colors = {
  primary: '#0b6ec9',
  primaryDark: '#095ca8',
  success: '#16a34a',
  warning: '#b45309',
  danger: '#dc2626',
  accent: '#7c3aed',
  bg: '#eef8ff',
  bgGradientTop: '#eaf5ff',
  bgGradientMid: '#dbeeff',
  surface: '#ffffff',
  text: '#0f2c44',
  textSoft: '#5c7a91',
  border: 'rgba(15,42,68,0.12)',
} as const;

export const spacing = { xs: 4, sm: 8, md: 12, lg: 16, xl: 24, xxl: 32 } as const;

export const radius = { sm: 8, md: 12, lg: 16, xl: 20, pill: 999 } as const;

export const typography = {
  wordmark: { fontWeight: '800' as const, letterSpacing: 3, textTransform: 'uppercase' as const },
  h1: { fontSize: 24, fontWeight: '800' as const },
  h2: { fontSize: 20, fontWeight: '700' as const },
  body: { fontSize: 16, fontWeight: '400' as const },
  small: { fontSize: 13, fontWeight: '400' as const },
} as const;

// Minimum touch target (accessibility).
export const MIN_TOUCH = 44;
