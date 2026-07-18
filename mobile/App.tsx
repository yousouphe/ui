// Aike mobile entry (scaffold). Demonstrates the branded splash/offline experience natively —
// the mobile counterpart of the web PWA (config/pwa.php + offline.html): Aike wordmark, loading
// indicator, rotating sender/rider feature messages, offline state with a Retry button, and
// automatic recovery when connectivity is confirmed (no forced Retry press).
//
// The role-based navigation shell and screens are added in phases 4–6 (see docs/06). Kept
// dependency-light so the scaffold is reviewable before the full toolchain is installed.
import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { colors, radius, spacing, typography } from '@/theme/theme';
import { useConnectivity } from '@/hooks/useConnectivity';

const MESSAGES = [
  'Request a rider in just a few taps.',
  'Set your pickup and drop-off in seconds.',
  'Compare riders by distance, vehicle and price.',
  'Track your delivery live, every step of the way.',
  'Riders: get delivery requests when you are online.',
  'Riders: review the trip details before you accept.',
  'Riders: watch your earnings grow with every trip.',
  'Stay connected, even on a shaky network.',
];

function SplashOffline({ offline, onRetry }: { offline: boolean; onRetry: () => void }) {
  const [i, setI] = useState(0);
  const ref = useRef(i);
  ref.current = i;
  useEffect(() => {
    const t = setInterval(() => setI((n) => (n + 1) % MESSAGES.length), 2600);
    return () => clearInterval(t);
  }, []);
  return (
    <View style={styles.splash} accessibilityRole="summary" accessibilityLabel="Aike is loading">
      <Text style={styles.wordmark}>AIKE</Text>
      {!offline && <ActivityIndicator size="large" color={colors.primary} />}
      <Text style={styles.loading}>{offline ? 'You are offline' : 'Loading…'}</Text>
      <Text style={styles.message}>{MESSAGES[i]}</Text>
      {offline && (
        <View style={styles.offlineBox}>
          <Text style={styles.offlineTitle}>You’re currently offline.</Text>
          <Text style={styles.offlineSub}>
            Some features may be unavailable until your connection is restored.
          </Text>
          <Pressable
            style={styles.retry}
            onPress={onRetry}
            accessibilityRole="button"
            accessibilityLabel="Try again"
          >
            <Text style={styles.retryText}>Try again</Text>
          </Pressable>
        </View>
      )}
    </View>
  );
}

export default function App() {
  const { online } = useConnectivity();
  const [retryKey, setRetryKey] = useState(0);

  // When connectivity is confirmed, the app proceeds automatically (no Retry needed). Until the
  // navigation shell lands (phase 4), we show a "ready" placeholder in place of the tab tree.
  if (!online) {
    return <SplashOffline offline onRetry={() => setRetryKey((k) => k + 1)} key={retryKey} />;
  }
  return (
    <View style={styles.splash}>
      <Text style={styles.wordmark}>AIKE</Text>
      <Text style={styles.loading}>Connected — app shell loads here (phases 4–6).</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  splash: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.lg,
    padding: spacing.xl,
    backgroundColor: colors.bg,
  },
  wordmark: { ...typography.wordmark, fontSize: 44, color: colors.primary },
  loading: { ...typography.small, color: colors.textSoft, letterSpacing: 2, textTransform: 'uppercase' },
  message: { ...typography.body, color: colors.text, textAlign: 'center', maxWidth: 320 },
  offlineBox: {
    marginTop: spacing.md,
    padding: spacing.lg,
    borderRadius: radius.lg,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    gap: spacing.sm,
    maxWidth: 360,
  },
  offlineTitle: { fontWeight: '700', color: colors.warning },
  offlineSub: { ...typography.small, color: colors.textSoft, textAlign: 'center' },
  retry: {
    marginTop: spacing.sm,
    backgroundColor: colors.primary,
    paddingVertical: spacing.md,
    paddingHorizontal: spacing.xl,
    borderRadius: radius.md,
    minHeight: 44,
    justifyContent: 'center',
  },
  retryText: { color: '#fff', fontWeight: '600' },
});
