// "Continue with Google" — native Google Sign-In via expo-auth-session. It obtains a Google ID
// token on the device and hands it to AuthContext.googleSignIn, which posts it to /auth/google;
// the backend verifies the token and links/creates the account (no client secret on the device).
// If no Google client IDs are configured for this build, the button hides itself so the rest of
// the auth screens still work.
import React, { useEffect, useState } from 'react';
import * as WebBrowser from 'expo-web-browser';
import * as Google from 'expo-auth-session/providers/google';
import { Button } from './Button';
import { useAuth } from '@/auth/AuthContext';
import { ApiClientError } from '@/api/client';
import { config } from '@/config';

WebBrowser.maybeCompleteAuthSession();

export function GoogleSignInButton({ onError }: { onError?: (msg: string) => void }) {
  const { googleSignIn } = useAuth();
  const [busy, setBusy] = useState(false);
  const configured = !!(config.googleIosClientId || config.googleAndroidClientId || config.googleWebClientId);

  const [request, response, promptAsync] = Google.useAuthRequest({
    iosClientId: config.googleIosClientId || undefined,
    androidClientId: config.googleAndroidClientId || undefined,
    webClientId: config.googleWebClientId || undefined,
  });

  useEffect(() => {
    if (response?.type !== 'success') {
      if (response && response.type !== 'dismiss' && response.type !== 'cancel') setBusy(false);
      return;
    }
    const idToken = response.params?.id_token ?? response.authentication?.idToken;
    if (!idToken) { setBusy(false); onError?.('Google sign-in did not return a token.'); return; }
    (async () => {
      try {
        await googleSignIn(idToken); // AuthContext sets the user → navigator swaps trees
      } catch (e) {
        onError?.(e instanceof ApiClientError ? e.message : 'Google sign-in failed. Please try again.');
      } finally {
        setBusy(false);
      }
    })();
  }, [response]);

  if (!configured) return null;

  return (
    <Button
      title="Continue with Google"
      variant="secondary"
      disabled={!request || busy}
      loading={busy}
      onPress={() => { setBusy(true); onError?.(''); void promptAsync(); }}
    />
  );
}
