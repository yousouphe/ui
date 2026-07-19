// Runtime configuration, read from Expo `extra`/env at build time. NON-SECRET only.
import Constants from 'expo-constants';

type Extra = {
  AIKE_API_BASE_URL?: string;
  AIKE_MAPBOX_PUBLIC_TOKEN?: string;
  AIKE_EAS_PROJECT_ID?: string;
  AIKE_GOOGLE_IOS_CLIENT_ID?: string;
  AIKE_GOOGLE_ANDROID_CLIENT_ID?: string;
  AIKE_GOOGLE_WEB_CLIENT_ID?: string;
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
  // Google OAuth client IDs (non-secret; the client secret stays server-side). Blank → the
  // "Continue with Google" button hides itself, so the app still works without them configured.
  googleIosClientId: required('AIKE_GOOGLE_IOS_CLIENT_ID', ''),
  googleAndroidClientId: required('AIKE_GOOGLE_ANDROID_CLIENT_ID', ''),
  googleWebClientId: required('AIKE_GOOGLE_WEB_CLIENT_ID', ''),
  requestTimeoutMs: 15000,
} as const;
