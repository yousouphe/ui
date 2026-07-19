// Aike mobile entry. Composition root: providers (React Query, Auth, i18n, SafeArea) wrapped
// around a connectivity/boot gate that shows the branded splash/offline experience (the native
// counterpart of the web PWA) until the app is online and the session is resolved, then hands
// off to the role-based navigation shell.
import 'react-native-gesture-handler';
import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { I18nextProvider } from 'react-i18next';
import i18n from '@/i18n';
import { AuthProvider, useAuth } from '@/auth/AuthContext';
import { RootNavigator } from '@/navigation/RootNavigator';
import { useConnectivity } from '@/hooks/useConnectivity';
import { colors, radius, spacing, typography } from '@/theme/theme';

const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: 2, staleTime: 15000, refetchOnWindowFocus: false } },
});

const MESSAGES = [
  'Request a rider in just a few taps.',
  'Compare riders by distance, vehicle and price.',
  'Track your delivery live, every step of the way.',
  'Riders: get delivery requests when you are online.',
  'Riders: watch your earnings grow with every trip.',
  'Stay connected, even on a shaky network.',
];

function Splash({ offline, onRetry }: { offline: boolean; onRetry?: () => void }) {
  const [i, setI] = useState(0);
  useEffect(() => {
    const t = setInterval(() => setI((n) => (n + 1) % MESSAGES.length), 2600);
    return () => clearInterval(t);
  }, []);
  return (
    <View style={styles.splash} accessibilityLabel="Aike">
      <Text style={styles.wordmark}>AIKE</Text>
      {!offline && <ActivityIndicator size="large" color={colors.primary} />}
      <Text style={styles.loading}>{offline ? 'You are offline' : 'Loading…'}</Text>
      <Text style={styles.message}>{MESSAGES[i]}</Text>
      {offline && (
        <View style={styles.offlineBox}>
          <Text style={styles.offlineTitle}>You’re currently offline.</Text>
          <Text style={styles.offlineSub}>Some features may be unavailable until your connection is restored.</Text>
          {onRetry ? (
            <Pressable style={styles.retry} onPress={onRetry} accessibilityRole="button" accessibilityLabel="Try again">
              <Text style={styles.retryText}>Try again</Text>
            </Pressable>
          ) : null}
        </View>
      )}
    </View>
  );
}

function Gate() {
  const { online } = useConnectivity();
  const { booting } = useAuth();
  const [retryKey, setRetryKey] = useState(0);
  if (!online) return <Splash offline onRetry={() => setRetryKey((k) => k + 1)} key={retryKey} />;
  if (booting) return <Splash offline={false} />;
  return <RootNavigator />;
}

export default function App() {
  return (
    <SafeAreaProvider>
      <QueryClientProvider client={queryClient}>
        <I18nextProvider i18n={i18n}>
          <AuthProvider>
            <Gate />
          </AuthProvider>
        </I18nextProvider>
      </QueryClientProvider>
    </SafeAreaProvider>
  );
}

const styles = StyleSheet.create({
  splash: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: spacing.lg, padding: spacing.xl, backgroundColor: colors.bg },
  wordmark: { ...typography.wordmark, fontSize: 44, color: colors.primary },
  loading: { ...typography.small, color: colors.textSoft, letterSpacing: 2, textTransform: 'uppercase' },
  message: { ...typography.body, color: colors.text, textAlign: 'center', maxWidth: 320 },
  offlineBox: { marginTop: spacing.md, padding: spacing.lg, borderRadius: radius.lg, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, alignItems: 'center', gap: spacing.sm, maxWidth: 360 },
  offlineTitle: { fontWeight: '700', color: colors.warning },
  offlineSub: { ...typography.small, color: colors.textSoft, textAlign: 'center' },
  retry: { marginTop: spacing.sm, backgroundColor: colors.primary, paddingVertical: spacing.md, paddingHorizontal: spacing.xl, borderRadius: radius.md, minHeight: 44, justifyContent: 'center' },
  retryText: { color: '#fff', fontWeight: '600' },
});
