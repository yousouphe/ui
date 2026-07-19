// Scaffold screens for the navigation shell. The sender/rider home, orders, wallet and profile
// screens are wired to the real API services so the shell is a working skeleton; the full
// booking wizard, rider job workflow, tracking map, chat and payments UIs are built out in
// Phases 5-6 on top of these.
import React, { useCallback, useEffect, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Switch, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { useNavigation } from '@react-navigation/native';
import { Button, Card, EmptyState, ErrorState, LoadingState, MoneyText, StatusBadge } from '@/components';
import { useAuth } from '@/auth/AuthContext';
import { riderApi, senderApi } from '@/api/services';
import { startRiderLocation, stopRiderLocation } from '@/services/location';
import { colors, spacing, typography } from '@/theme/theme';
import type { Booking } from '@shared/contracts/api';

function Screen({ title, children, onRefresh, refreshing }: { title: string; children: React.ReactNode; onRefresh?: () => void; refreshing?: boolean }) {
  return (
    <ScrollView
      style={styles.screen}
      contentContainerStyle={styles.content}
      refreshControl={onRefresh ? <RefreshControl refreshing={!!refreshing} onRefresh={onRefresh} /> : undefined}
    >
      <Text style={styles.title}>{title}</Text>
      {children}
    </ScrollView>
  );
}

export function SenderHomeScreen() {
  const { t } = useTranslation();
  const navigation = useNavigation<{ navigate: (s: string) => void }>();
  return (
    <Screen title={t('common.brand')}>
      <Card>
        <Text style={typography.h2 as object}>{t('sender.newDelivery')}</Text>
        <Text style={styles.soft}>Search a pickup and drop-off, pick a vehicle, compare riders and track live.</Text>
        <Button title={t('sender.newDelivery')} onPress={() => navigation.navigate('CreateBooking')} />
      </Card>
    </Screen>
  );
}

export function SenderOrdersScreen() {
  const [state, setState] = useState<{ loading: boolean; error: string | null; bookings: Booking[] }>({ loading: true, error: null, bookings: [] });
  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const res = await senderApi.listBookings('active');
      setState({ loading: false, error: null, bookings: res.bookings });
    } catch {
      setState({ loading: false, error: 'Could not load your orders.', bookings: [] });
    }
  }, []);
  useEffect(() => { load(); }, [load]);

  if (state.loading) return <LoadingState />;
  if (state.error) return <ErrorState message={state.error} onRetry={load} />;
  return (
    <Screen title="Active orders" onRefresh={load} refreshing={state.loading}>
      {state.bookings.length === 0 ? (
        <EmptyState title="No active orders" subtitle="Your active deliveries will appear here." />
      ) : (
        state.bookings.map((b) => (
          <Card key={b.id}>
            <View style={styles.row}>
              <Text style={styles.code}>{b.pickup.address} → {b.dropoff.address}</Text>
              <StatusBadge status={b.status} />
            </View>
            <MoneyText amount={b.agreedCost} />
          </Card>
        ))
      )}
    </Screen>
  );
}

export function RiderHomeScreen() {
  const { t } = useTranslation();
  const navigation = useNavigation<{ navigate: (s: string) => void }>();
  const [online, setOnline] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  async function toggle(next: boolean) {
    setBusy(true); setError(null);
    try {
      await riderApi.setStatus(next ? 'available' : 'offline');
      setOnline(next);
      // Background location runs ONLY while online (and is requested only for riders).
      if (next) {
        const ok = await startRiderLocation();
        if (!ok) setError('Location permission is needed to receive nearby deliveries.');
      } else {
        await stopRiderLocation();
      }
    } catch (e) {
      setError('Could not update your status. Make sure your account is verified.');
    } finally {
      setBusy(false);
    }
  }
  return (
    <Screen title={t('common.brand')}>
      <Card>
        <View style={styles.row}>
          <Text style={typography.h2 as object}>{online ? t('rider.goOffline') : t('rider.goOnline')}</Text>
          <Switch value={online} onValueChange={toggle} disabled={busy} />
        </View>
        <Text style={styles.soft}>Go online to receive nearby delivery requests. Location is shared only while you're online or on a delivery.</Text>
        {error ? <Text style={{ color: colors.danger }}>{error}</Text> : null}
      </Card>
      <Button title="New offers" onPress={() => navigation.navigate('Offers')} />
      <Button title="Active jobs" variant="secondary" onPress={() => navigation.navigate('ActiveJobs')} />
    </Screen>
  );
}

export function RiderWalletScreen() {
  const [state, setState] = useState<{ loading: boolean; error: string | null; balance: number; available: number }>({ loading: true, error: null, balance: 0, available: 0 });
  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const w = await riderApi.wallet();
      setState({ loading: false, error: null, balance: w.balance, available: w.availableBalance });
    } catch {
      setState({ loading: false, error: 'Could not load your wallet.', balance: 0, available: 0 });
    }
  }, []);
  useEffect(() => { load(); }, [load]);
  if (state.loading) return <LoadingState />;
  if (state.error) return <ErrorState message={state.error} onRetry={load} />;
  return (
    <Screen title="Wallet" onRefresh={load} refreshing={state.loading}>
      <Card>
        <Text style={styles.soft}>Available to withdraw</Text>
        <MoneyText amount={state.available} />
      </Card>
      <Card>
        <Text style={styles.soft}>Total balance</Text>
        <MoneyText amount={state.balance} />
      </Card>
    </Screen>
  );
}

export function NotificationsScreen() {
  return <Screen title="Alerts"><EmptyState title="You're all caught up" subtitle="Delivery and payment updates will appear here." /></Screen>;
}

export function ProfileScreen() {
  const { user, signOut } = useAuth();
  return (
    <Screen title="Profile">
      <Card>
        <Text style={typography.h2 as object}>{user?.fullName}</Text>
        <Text style={styles.soft}>{user?.email}</Text>
        <Text style={styles.soft}>Role: {user?.role}</Text>
      </Card>
      <Button title="Sign out" variant="danger" onPress={() => { void signOut(); }} />
    </Screen>
  );
}

export function RegisterScreen() {
  return <Screen title="Create account"><EmptyState title="Registration" subtitle="The full sign-up flow (sender/rider + KYC) is built in Phase 5/6 on the /auth/register API." /></Screen>;
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: spacing.lg, gap: spacing.md },
  title: { ...typography.h1, color: colors.text, marginBottom: spacing.sm },
  soft: { ...typography.small, color: colors.textSoft },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: spacing.md },
  code: { ...typography.body, color: colors.text, flexShrink: 1 },
});
