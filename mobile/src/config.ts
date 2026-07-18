// Runtime configuration, read from Expo `extra`/env at build time. NON-SECRET only.
import Constants from 'expo-constants';

type Extra = {
  AIKE_API_BASE_URL?: string;
  AIKE_MAPBOX_PUBLIC_TOKEN?: string;
  AIKE_EAS_PROJECT_ID?: string;
};

const extra = (Constants.expoConfig?.extra ?? {}) as Extra;

function required(name: keyof Extra, fallback?: string): string {
  const value = (process.env[name as string] as string | undefined) ?? extra[name] ?? fallback;
  if (!value) {
    // Fail loud in dev; in production the build injects these. Never silently use a wrong host.
    if (__DEV__) console.warn(`[config] Missing ${name} — set it in mobile/.env (see .env.example)`);
    return fallback ?? '';
  }
  return value;
}

export const config = {
  apiBaseUrl: required('AIKE_API_BASE_URL', 'http://127.0.0.1:8099/api/v1'),
  mapboxPublicToken: required('AIKE_MAPBOX_PUBLIC_TOKEN', ''),
  easProjectId: required('AIKE_EAS_PROJECT_ID', ''),
  requestTimeoutMs: 15000,
} as const;
