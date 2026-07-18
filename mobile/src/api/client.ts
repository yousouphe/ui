// Aike API client for the backend `/api/v1` JSON layer.
//
// Responsibilities:
//  - Attach the bearer access token.
//  - Enforce a request timeout (AbortController) so a slow Nigerian network can't hang the UI.
//  - Parse the standard { ok, data, error } envelope into data or a typed ApiError.
//  - Transparently refresh the access token once on 401, then retry — with a single-flight lock
//    so concurrent 401s don't fire multiple refreshes.
//  - Support Idempotency-Key on unsafe writes so an interrupted/retried request never double-acts.
//
// It holds NO business logic and NO secrets. All trusted decisions are the backend's.
import { config } from '@/config';
import { getAccessToken, getRefreshToken, saveTokens, clearTokens } from '@/storage/secureTokens';
import type { ApiEnvelope, ApiError, AuthTokens } from '@shared/contracts/api';

export class ApiClientError extends Error {
  code: string;
  status: number;
  fields?: Record<string, string>;
  constructor(status: number, error: ApiError) {
    super(error.message);
    this.name = 'ApiClientError';
    this.code = error.code;
    this.status = status;
    this.fields = error.fields;
  }
}

type RequestOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
  body?: unknown;
  idempotencyKey?: string;
  auth?: boolean; // default true
  signal?: AbortSignal;
  _isRetry?: boolean;
};

let refreshInFlight: Promise<boolean> | null = null;

async function refreshAccessToken(): Promise<boolean> {
  if (refreshInFlight) return refreshInFlight;
  refreshInFlight = (async () => {
    const refreshToken = await getRefreshToken();
    if (!refreshToken) return false;
    try {
      const res = await fetch(`${config.apiBaseUrl}/auth/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refreshToken }),
      });
      const json = (await res.json()) as ApiEnvelope<AuthTokens>;
      if (res.ok && json.ok && json.data) {
        await saveTokens(json.data);
        return true;
      }
      await clearTokens(); // refresh rejected/revoked -> force re-login
      return false;
    } catch {
      return false;
    } finally {
      refreshInFlight = null;
    }
  })();
  return refreshInFlight;
}

export async function apiRequest<T>(path: string, opts: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, idempotencyKey, auth = true, signal } = opts;

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), config.requestTimeoutMs);
  if (signal) signal.addEventListener('abort', () => controller.abort());

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;
  if (auth) {
    const token = await getAccessToken();
    if (token) headers['Authorization'] = `Bearer ${token}`;
  }

  let res: Response;
  try {
    res = await fetch(`${config.apiBaseUrl}${path}`, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      signal: controller.signal,
    });
  } catch (e) {
    clearTimeout(timeout);
    throw new ApiClientError(0, {
      code: (e as Error).name === 'AbortError' ? 'TIMEOUT' : 'NETWORK',
      message: 'Network error. Please check your connection and try again.',
    });
  }
  clearTimeout(timeout);

  // One transparent refresh+retry on 401 (unless this call was already the retry).
  if (res.status === 401 && auth && !opts._isRetry) {
    const refreshed = await refreshAccessToken();
    if (refreshed) return apiRequest<T>(path, { ...opts, _isRetry: true });
  }

  let json: ApiEnvelope<T>;
  try {
    json = (await res.json()) as ApiEnvelope<T>;
  } catch {
    throw new ApiClientError(res.status, { code: 'BAD_RESPONSE', message: 'Unexpected server response.' });
  }

  if (!res.ok || !json.ok) {
    throw new ApiClientError(res.status, json.error ?? { code: 'UNKNOWN', message: 'Something went wrong.' });
  }
  return json.data as T;
}
