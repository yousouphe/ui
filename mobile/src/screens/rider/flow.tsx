// Rider job flow: review offers -> accept/reject -> work the delivery (arrive → pickup → deliver)
// -> navigate → confirm payment received. Trusted transitions/eligibility are the backend's; the
// screens call the endpoints and render results. Verified via backend endpoint tests + TS parse.
import React, { useCallback, useEffect, useState } from 'react';
import { Linking, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { useNavigation } from '@react-navigation/native';
import { Button, Card, EmptyState, ErrorState, LoadingState, MoneyText, StatusBadge } from '@/components';
import { CallButton } from '@/components/CallButton';
import { riderApi } from '@/api/services';
import { ApiClientError } from '@/api/client';
import { VEHICLE_LABEL, type VehicleType } from '@shared/constants/vehicles';
import type { Booking, RiderJobFilter, RiderOffer } from '@shared/contracts/api';
import { colors, radius, spacing, typography } from '@/theme/theme';

type Nav = { navigate: (s: string, p?: object) => void };

export function RiderOffersScreen({ navigation }: { navigation: Nav }) {
  const [state, setState] = useState<{ loading: boolean; error: string | null; offers: RiderOffer[] }>({ loading: true, error: null, offers: [] });
  const [acting, setActing] = useState<number | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await riderApi.offers();
      setState({ loading: false, error: null, offers: res.offers });
    } catch {
      setState((s) => ({ ...s, loading: false, error: 'Could not load offers.' }));
    }
  }, []);

  useEffect(() => {
    load();
    const t = setInterval(load, 15000); // poll for new offers (push wakes the app too)
    return () => clearInterval(t);
  }, [load]);

  async function respond(o: RiderOffer, accept: boolean) {
    setActing(o.requestId);
    try {
      if (accept) {
        await riderApi.acceptOffer(o.requestId);
        navigation.navigate('ActiveJobs');
      } else {
        await riderApi.rejectOffer(o.requestId);
      }
      await load(filter);
    } catch (e) {
      setState((s) => ({ ...s, error: e instanceof ApiClientError ? e.message : 'Could not update the offer.' }));
    } finally {
      setActing(null);
    }
  }

  if (state.loading) return <LoadingState />;
  if (state.error) return <ErrorState message={state.error} onRetry={load} />;
  if (state.offers.length === 0) return <EmptyState title="No new offers" subtitle="Stay online — new delivery requests will appear here." />;

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content}>
      <Text style={styles.title}>New offers</Text>
      {state.offers.map((o) => (
        <Card key={o.requestId}>
          <View style={styles.row}>
            <Text style={styles.h2}>{o.itemName}</Text>
            <MoneyText amount={o.proposedCost} />
          </View>
          <Text style={styles.soft}>Pickup: {o.pickupAddress}</Text>
          <Text style={styles.soft}>Drop-off: {o.dropoffAddress}</Text>
          <Text style={styles.soft}>{VEHICLE_LABEL[(o.vehicleType as VehicleType) ?? 'bike']} · {o.bookingCode}</Text>
          <View style={styles.actions}>
            <Button title="Reject" variant="secondary" onPress={() => respond(o, false)} loading={acting === o.requestId} style={styles.flex} />
            <Button title="Accept" onPress={() => respond(o, true)} loading={acting === o.requestId} style={styles.flex} />
          </View>
        </Card>
      ))}
    </ScrollView>
  );
}

// Next allowed transition for the rider, mirroring the backend map.
function nextAction(status: string): { to: string; label: string } | null {
  switch (status) {
    case 'matched':
    case 'accepted': return { to: 'arrived_at_pickup', label: 'I have arrived at pickup' };
    case 'arrived_at_pickup': return { to: 'package_received', label: 'Package received' };
    case 'package_received':
    case 'in_transit': return { to: 'delivered', label: 'Mark delivered' };
    default: return null;
  }
}

const JOB_FILTERS: readonly RiderJobFilter[] = ['active', 'pending', 'completed', 'cancelled'];
const JOB_FILTER_LABEL: Record<RiderJobFilter, string> = { active: 'Active', pending: 'Pending', completed: 'Completed', cancelled: 'Cancelled' };

export function RiderActiveJobsScreen() {
  const navigation = useNavigation<{ navigate: (s: string, p?: object) => void }>();
  const [filter, setFilter] = useState<RiderJobFilter>('active');
  const [state, setState] = useState<{ loading: boolean; error: string | null; jobs: Booking[] }>({ loading: true, error: null, jobs: [] });
  const [acting, setActing] = useState<number | null>(null);

  const load = useCallback(async (f: RiderJobFilter) => {
    try {
      const res = await riderApi.jobs(f);
      setState({ loading: false, error: null, jobs: res.bookings });
    } catch {
      setState((s) => ({ ...s, loading: false, error: 'Could not load your jobs.' }));
    }
  }, []);

  useEffect(() => {
    setState((s) => ({ ...s, loading: true }));
    load(filter);
    // Only the live buckets need polling; finished ones are static.
    if (filter === 'active' || filter === 'pending') {
      const t = setInterval(() => load(filter), 12000);
      return () => clearInterval(t);
    }
    return undefined;
  }, [filter, load]);

  async function advance(job: Booking, to: string) {
    setActing(job.id);
    try {
      await riderApi.transition(job.id, to);
      await load(filter);
    } catch (e) {
      setState((s) => ({ ...s, error: e instanceof ApiClientError ? e.message : 'Could not update the delivery.' }));
    } finally {
      setActing(null);
    }
  }

  async function confirmPay(job: Booking) {
    setActing(job.id);
    try {
      await riderApi.confirmPayment(job.id);
      await load(filter);
    } catch (e) {
      setState((s) => ({ ...s, error: e instanceof ApiClientError ? e.message : 'Could not confirm payment.' }));
    } finally {
      setActing(null);
    }
  }

  function navigateTo(job: Booking) {
    const target = job.status === 'package_received' || job.status === 'in_transit' ? job.dropoff : job.pickup;
    if (target.lat != null && target.lng != null) {
      Linking.openURL(`https://www.google.com/maps/dir/?api=1&destination=${target.lat},${target.lng}`);
    }
  }

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content}>
      <Text style={styles.title}>My jobs</Text>
      <View style={styles.tabs}>
        {JOB_FILTERS.map((f) => (
          <TouchableOpacity key={f} onPress={() => setFilter(f)} style={[styles.tab, filter === f && styles.tabActive]} accessibilityRole="button" accessibilityState={{ selected: filter === f }}>
            <Text style={[styles.tabText, filter === f && styles.tabTextActive]}>{JOB_FILTER_LABEL[f]}</Text>
          </TouchableOpacity>
        ))}
      </View>
      {state.loading ? (
        <LoadingState />
      ) : state.error ? (
        <ErrorState message={state.error} onRetry={() => load(filter)} />
      ) : state.jobs.length === 0 ? (
        <EmptyState title={`No ${JOB_FILTER_LABEL[filter].toLowerCase()} jobs`} subtitle="Jobs in this list will appear here." />
      ) : (
      state.jobs.map((job) => {
        const step = nextAction(job.status);
        const delivered = job.status === 'delivered';
        return (
          <Card key={job.id}>
            <View style={styles.row}>
              <Text style={styles.h2}>{job.pickup.address} → {job.dropoff.address}</Text>
              <StatusBadge status={job.status} />
            </View>
            <MoneyText amount={job.agreedCost} />
            <Button title="Navigate" variant="secondary" onPress={() => navigateTo(job)} />
            <CallButton bookingId={job.id} label="Call sender" />
            <Button title="Message sender" variant="secondary" onPress={() => navigation.navigate('Chat', { bookingId: job.id })} />
            {step ? <Button title={step.label} onPress={() => advance(job, step.to)} loading={acting === job.id} /> : null}
            {delivered && job.paymentStatus === 'paid' ? (
              <Button title="Confirm payment received" onPress={() => confirmPay(job)} loading={acting === job.id} />
            ) : null}
            {delivered && job.paymentStatus !== 'paid' ? (
              <Text style={styles.soft}>Waiting for the sender's payment…</Text>
            ) : null}
          </Card>
        );
      })
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: spacing.lg, gap: spacing.md },
  title: { ...typography.h1, color: colors.text },
  h2: { ...typography.h2, color: colors.text, flexShrink: 1 },
  soft: { ...typography.small, color: colors.textSoft },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: spacing.md },
  actions: { flexDirection: 'row', gap: spacing.sm },
  flex: { flex: 1 },
  tabs: { flexDirection: 'row', backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, padding: 3, gap: 3 },
  tab: { flex: 1, paddingVertical: spacing.sm, borderRadius: radius.sm, alignItems: 'center' },
  tabActive: { backgroundColor: colors.primary },
  tabText: { ...typography.small, color: colors.textSoft, fontWeight: '700' },
  tabTextActive: { color: '#fff' },
});
