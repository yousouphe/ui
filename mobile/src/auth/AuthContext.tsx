// Auth state for the app. On boot it tries to restore a session from the securely-stored token
// (calls /profile); the role on the returned user selects the sender or rider navigation tree.
// The user's role/identity is always taken from the SERVER response, never assumed on the client.
import React, { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { authApi } from '@/api/services';
import { getAccessToken } from '@/storage/secureTokens';
import type { UserProfile } from '@shared/contracts/api';
import type { LoginRequest } from '@shared/contracts/api';

type AuthState = {
  user: UserProfile | null;
  booting: boolean;
  signIn: (creds: LoginRequest) => Promise<void>;
  register: (body: Record<string, unknown>) => Promise<void>;
  signOut: () => Promise<void>;
  refreshUser: () => Promise<void>;
};

const AuthCtx = createContext<AuthState | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<UserProfile | null>(null);
  const [booting, setBooting] = useState(true);

  useEffect(() => {
    (async () => {
      try {
        const token = await getAccessToken();
        if (token) {
          setUser(await authApi.me());
        }
      } catch {
        // A missing/expired session just means "logged out" — not an error state.
        setUser(null);
      } finally {
        setBooting(false);
      }
    })();
  }, []);

  const value = useMemo<AuthState>(() => ({
    user,
    booting,
    signIn: async (creds) => setUser(await authApi.login(creds)),
    register: async (body) => setUser(await authApi.register(body)),
    signOut: async () => {
      await authApi.logout();
      setUser(null);
    },
    refreshUser: async () => setUser(await authApi.me()),
  }), [user, booting]);

  return <AuthCtx.Provider value={value}>{children}</AuthCtx.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthCtx);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
