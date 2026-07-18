// Secure token storage. Access + refresh tokens are kept in the OS keystore/keychain via
// expo-secure-store — never in plain AsyncStorage, never logged. Nothing else sensitive is
// cached (no payment secrets, no identity documents), per the spec's caching rules.
import * as SecureStore from 'expo-secure-store';
import type { AuthTokens } from '@shared/contracts/api';

const ACCESS_KEY = 'aike.accessToken';
const REFRESH_KEY = 'aike.refreshToken';

export async function saveTokens(tokens: AuthTokens): Promise<void> {
  await SecureStore.setItemAsync(ACCESS_KEY, tokens.accessToken);
  await SecureStore.setItemAsync(REFRESH_KEY, tokens.refreshToken);
}

export async function getAccessToken(): Promise<string | null> {
  return SecureStore.getItemAsync(ACCESS_KEY);
}

export async function getRefreshToken(): Promise<string | null> {
  return SecureStore.getItemAsync(REFRESH_KEY);
}

export async function clearTokens(): Promise<void> {
  await SecureStore.deleteItemAsync(ACCESS_KEY);
  await SecureStore.deleteItemAsync(REFRESH_KEY);
}
