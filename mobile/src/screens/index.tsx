// Tab screens for the sender/rider shells. These are wired to the real API services; the booking
// wizard, rider job workflow, tracking, payments and auth flows live in their own files. All
// trusted decisions stay on the backend — these screens collect input and render server responses.
import React, { useCallback, useEffect, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Switch, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { useNavigation } from '@react-navigation/native';
import { Button, Card, EmptyState, ErrorState, LoadingState, MoneyText, StatusBadge } from '@/components';
import { useAuth } from '@/auth/AuthContext';
import { authApi, riderApi, senderApi } from '@/api/services';
import { ApiClientError } from '@/api/client';
import { startRiderLocation, stopRiderLocation } from '@/services/location';
import { colors, radius, spacing, typography } from '@/theme/theme';
import type { Booking, BookingListFilter, WalletLedgerEntry } from '@shared/contracts/api';

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

// Segmented filter control shared by the orders/jobs buckets.
function FilterTabs<T extends string>({ options, value, onChange, labels }: { options: readonly T[]; value: T; onChange: (v: T) => void; labels: Record<T, string> }) {
  return (
    <View style={styles.tabs}>
      {options.map((o) => (
        <TouchableOpacity key={o} onPress={() => onChange(o)} style={[styles.tab, value === o && styles.tabActive]} accessibilityRole="button" accessibilityState={{ selected: value === o }}>
          <Text style={[styles.tabText, value === o && styles.tabTextActive]}>{labels[o]}</Text>
        </TouchableOpacity>
      ))}
    </View>
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

const SENDER_FILTERS: readonly BookingListFilter[] = ['active', 'unpaid', 'history'];

export function SenderOrdersScreen() {
  const navigation = useNavigation<{ navigate: (s: string, p?: object) => void }>();
  const [filter, setFilter] = useState<BookingListFilter>('active');
  const [state, setState] = useState<{ loading: boolean; error: string | null; bookings: Booking[] }>({ loading: true, error: null, bookings: [] });

  const load = useCallback(async (f: BookingListFilter) => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const res = await senderApi.listBookings(f);
      setState({ loading: false, error: null, bookings: res.bookings });
    } catch {
      setState({ loading: false, error: 'Could not load your orders.', bookings: [] });
    }
  }, []);

  useEffect(() => { load(filter); }, [filter, load]);

  return (
    <Screen title="My orders" onRefresh={() => load(filter)} refreshing={state.loading}>
      <FilterTabs options={SENDER_FILTERS} value={filter} onChange={setFilter} labels={{ active: 'Active', unpaid: 'Unpaid', history: 'History' }} />
      {state.loading ? (
        <LoadingState />
      ) : state.error ? (
        <ErrorState message={state.error} onRetry={() => load(filter)} />
      ) : state.bookings.length === 0 ? (
        <EmptyState title="Nothing here yet" subtitle="Your deliveries in this list will show up here." />
      ) : (
        state.bookings.map((b) => (
          <TouchableOpacity key={b.id} onPress={() => navigation.navigate('Track', { bookingId: b.id })} accessibilityRole="button">
            <Card>
              <View style={styles.row}>
                <Text style={styles.code} numberOfLines={1}>{b.pickup.address} → {b.dropoff.address}</Text>
                <StatusBadge status={b.status} />
              </View>
              <View style={styles.row}>
                <MoneyText amount={b.agreedCost} />
                {filter === 'unpaid' ? <Text style={styles.payHint}>Tap to pay →</Text> : null}
              </View>
            </Card>
          </TouchableOpacity>
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
  const navigation = useNavigation<{ navigate: (s: string) => void }>();
  const [state, setState] = useState<{ loading: boolean; error: string | null; balance: number; available: number; ledger: WalletLedgerEntry[] }>({ loading: true, error: null, balance: 0, available: 0, ledger: [] });
  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const w = await riderApi.wallet();
      setState({ loading: false, error: null, balance: w.balance, available: w.availableBalance, ledger: w.ledger ?? [] });
    } catch {
      setState({ loading: false, error: 'Could not load your wallet.', balance: 0, available: 0, ledger: [] });
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
        <Button title="Withdraw" onPress={() => navigation.navigate('Withdraw')} style={{ marginTop: spacing.sm }} />
        <View style={styles.row}>
          <Button title="Payout bank" variant="secondary" onPress={() => navigation.navigate('BankAccount')} style={styles.flex} />
          <Button title="History" variant="secondary" onPress={() => navigation.navigate('Withdrawals')} style={styles.flex} />
        </View>
      </Card>
      <Card>
        <Text style={styles.soft}>Total balance</Text>
        <MoneyText amount={state.balance} />
      </Card>
      <Text style={styles.sectionLabel}>Transactions</Text>
      {state.ledger.length === 0 ? (
        <EmptyState title="No transactions yet" subtitle="Earnings and withdrawals will appear here." />
      ) : (
        state.ledger.map((e, i) => (
          <Card key={`${e.createdAt}-${i}`}>
            <View style={styles.row}>
              <Text style={styles.ledgerDesc} numberOfLines={1}>{e.description || e.type}</Text>
              <MoneyText amount={e.amount} style={e.type === 'withdrawal' ? { color: colors.danger } : undefined} />
            </View>
            <Text style={styles.soft}>{e.type} · {new Date(e.createdAt).toLocaleDateString()}</Text>
          </Card>
        ))
      )}
    </Screen>
  );
}

export function ProfileScreen() {
  const { user, signOut, refreshUser } = useAuth();
  const navigation = useNavigation<{ navigate: (s: string) => void }>();
  const [editing, setEditing] = useState(false);
  const [fullName, setFullName] = useState(user?.fullName ?? '');
  const [phone, setPhone] = useState(user?.phone ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function save() {
    setBusy(true); setError(null);
    try {
      await authApi.updateProfile({ fullName: fullName.trim(), phone: phone.trim() });
      await refreshUser();
      setEditing(false);
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : 'Could not save your profile.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen title="Profile">
      <Card>
        {!editing ? (
          <>
            <Text style={typography.h2 as object}>{user?.fullName}</Text>
            <Text style={styles.soft}>{user?.email}</Text>
            <Text style={styles.soft}>{user?.phone ?? 'No phone on file'}</Text>
            <Text style={styles.soft}>Role: {user?.role}</Text>
            <Button title="Edit profile" variant="secondary" onPress={() => { setFullName(user?.fullName ?? ''); setPhone(user?.phone ?? ''); setError(null); setEditing(true); }} style={{ marginTop: spacing.sm }} />
          </>
        ) : (
          <>
            <Text style={styles.soft}>Full name</Text>
            <TextInput style={styles.input} value={fullName} onChangeText={setFullName} autoCapitalize="words" />
            <Text style={styles.soft}>Phone</Text>
            <TextInput style={styles.input} value={phone} onChangeText={setPhone} keyboardType="phone-pad" />
            <Text style={styles.soft}>Email cannot be changed here.</Text>
            {error ? <Text style={{ color: colors.danger }}>{error}</Text> : null}
            <View style={styles.row}>
              <Button title="Cancel" variant="secondary" onPress={() => setEditing(false)} style={styles.flex} />
              <Button title="Save" onPress={save} loading={busy} disabled={!fullName.trim() || !phone.trim()} style={styles.flex} />
            </View>
          </>
        )}
      </Card>
      {user?.role === 'sender' ? (
        <Button title="Payment receipts" variant="secondary" onPress={() => navigation.navigate('Receipts')} />
      ) : null}
      {user?.role === 'rider' ? (
        <>
          <Button title="Verification" variant="secondary" onPress={() => navigation.navigate('Kyc')} />
          <Button title="Vehicle" variant="secondary" onPress={() => navigation.navigate('Vehicle')} />
          <Button title="Rider guidelines" variant="secondary" onPress={() => navigation.navigate('Guidelines')} />
        </>
      ) : null}
      <Button title="Sign out" variant="danger" onPress={() => { void signOut(); }} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: spacing.lg, gap: spacing.md },
  title: { ...typography.h1, color: colors.text, marginBottom: spacing.sm },
  soft: { ...typography.small, color: colors.textSoft },
  sectionLabel: { ...typography.h2, color: colors.text, marginTop: spacing.sm },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: spacing.md },
  code: { ...typography.body, color: colors.text, flexShrink: 1 },
  payHint: { ...typography.small, color: colors.primary, fontWeight: '700' },
  ledgerDesc: { ...typography.body, color: colors.text, flexShrink: 1 },
  input: { backgroundColor: colors.bg, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: spacing.md, minHeight: 48, fontSize: 16, color: colors.text },
  flex: { flex: 1 },
  tabs: { flexDirection: 'row', backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, padding: 3, gap: 3 },
  tab: { flex: 1, paddingVertical: spacing.sm, borderRadius: radius.sm, alignItems: 'center' },
  tabActive: { backgroundColor: colors.primary },
  tabText: { ...typography.small, color: colors.textSoft, fontWeight: '700' },
  tabTextActive: { color: '#fff' },
});
