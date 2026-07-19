// Sender delivery flow: create booking -> discover & request rider -> track -> pay -> rate.
// All trusted decisions (price, rider eligibility, transitions, payment) are the backend's; these
// screens only collect input and render server responses. Verified by TypeScript parse + the
// backend endpoint tests; on-device verification is Phase 9.
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Linking, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { Button, Card, EmptyState, ErrorState, LoadingState, MoneyText, StatusBadge } from '@/components';
import { AddressSearch } from '@/components/AddressSearch';
import { CallButton } from '@/components/CallButton';
import { MapPreview, type MapPoint } from '@/components/MapPreview';
import { senderApi, newIdempotencyKey } from '@/api/services';
import { ApiClientError } from '@/api/client';
import type { Place } from '@/api/geo';
import { VEHICLE_TYPES, VEHICLE_LABEL, type VehicleType } from '@shared/constants/vehicles';
import type { PriceBreakdown, RiderCandidate } from '@shared/contracts/api';
import { colors, radius, spacing, typography } from '@/theme/theme';

type Nav = { navigate: (screen: string, params?: object) => void; goBack: () => void };
type Route<T> = { params: T };

function Field({ label, ...props }: { label: string } & React.ComponentProps<typeof TextInput>) {
  return (
    <View style={{ gap: spacing.xs }}>
      <Text style={styles.label}>{label}</Text>
      <TextInput style={styles.input} placeholderTextColor={colors.textSoft} {...props} />
    </View>
  );
}

export function CreateBookingScreen({ navigation }: { navigation: Nav }) {
  const [pickup, setPickup] = useState<Place | null>(null);
  const [dropoff, setDropoff] = useState<Place | null>(null);
  const [recipientName, setRecipientName] = useState('');
  const [recipientPhone, setRecipientPhone] = useState('');
  const [itemName, setItemName] = useState('');
  const [vehicleType, setVehicleType] = useState<VehicleType>('bike');
  const [estimate, setEstimate] = useState<PriceBreakdown | null>(null);
  const [estimating, setEstimating] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Re-price whenever both ends and the vehicle are set. Backend computes the price.
  useEffect(() => {
    if (!pickup || !dropoff) { setEstimate(null); return; }
    let cancelled = false;
    setEstimating(true);
    setError(null);
    senderApi
      .estimate({ pickup: { lat: pickup.lat, lng: pickup.lng }, dropoff: { lat: dropoff.lat, lng: dropoff.lng }, vehicleType })
      .then((e) => { if (!cancelled) setEstimate(e); })
      .catch((e) => { if (!cancelled) { setEstimate(null); setError(e instanceof ApiClientError ? e.message : 'Could not price this route.'); } })
      .finally(() => { if (!cancelled) setEstimating(false); });
    return () => { cancelled = true; };
  }, [pickup, dropoff, vehicleType]);

  const canSubmit = pickup && dropoff && recipientName.trim() && recipientPhone.trim() && itemName.trim() && !submitting;

  async function submit() {
    if (!pickup || !dropoff) return;
    setSubmitting(true);
    setError(null);
    try {
      const res = await senderApi.createBooking(
        {
          pickup: { lat: pickup.lat, lng: pickup.lng, address: pickup.address },
          dropoff: { lat: dropoff.lat, lng: dropoff.lng, address: dropoff.address },
          vehicleType,
          recipientName,
          recipientPhone,
          itemName,
          notes: '',
        },
        newIdempotencyKey(),
      );
      navigation.navigate('Riders', { bookingId: res.booking.id });
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : 'Could not create the booking.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
      <Text style={styles.title}>Request a delivery</Text>
      <Card>
        <AddressSearch label="Pickup" value={pickup} onSelect={setPickup} />
        <AddressSearch label="Drop-off" value={dropoff} onSelect={setDropoff} />
      </Card>
      <Card>
        <Field label="Recipient name" value={recipientName} onChangeText={setRecipientName} />
        <Field label="Recipient phone" value={recipientPhone} onChangeText={setRecipientPhone} keyboardType="phone-pad" />
        <Field label="What are you sending?" value={itemName} onChangeText={setItemName} />
      </Card>
      <Card>
        <Text style={styles.label}>Vehicle</Text>
        <View style={styles.vehicleRow}>
          {VEHICLE_TYPES.map((v) => (
            <Button
              key={v}
              title={VEHICLE_LABEL[v]}
              variant={vehicleType === v ? 'primary' : 'secondary'}
              onPress={() => setVehicleType(v)}
              style={styles.vehicleBtn}
            />
          ))}
        </View>
        {estimating ? <Text style={styles.soft}>Calculating price…</Text> : null}
        {estimate ? (
          <View style={styles.priceRow}>
            <View>
              <Text style={styles.soft}>{estimate.distanceKm} km · ~{estimate.durationMinutes} min</Text>
              <Text style={styles.soft}>Estimated fee</Text>
            </View>
            <MoneyText amount={estimate.total} />
          </View>
        ) : null}
      </Card>
      {error ? <Text style={styles.error}>{error}</Text> : null}
      <Button title="Find riders" onPress={submit} loading={submitting} disabled={!canSubmit} />
    </ScrollView>
  );
}

export function RidersScreen({ navigation, route }: { navigation: Nav; route: Route<{ bookingId: number }> }) {
  const { bookingId } = route.params;
  const [state, setState] = useState<{ loading: boolean; error: string | null; pending: boolean; riders: RiderCandidate[] }>(
    { loading: true, error: null, pending: false, riders: [] },
  );
  const [sending, setSending] = useState<number | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await senderApi.riders(bookingId);
      setState({ loading: false, error: null, pending: res.pricingPending, riders: res.riders });
    } catch {
      setState((s) => ({ ...s, loading: false, error: 'Could not load riders.' }));
    }
  }, [bookingId]);

  useEffect(() => {
    load();
    const t = setInterval(load, 15000); // poll; backend caches the route so this is cheap
    return () => clearInterval(t);
  }, [load]);

  async function choose(r: RiderCandidate) {
    if (r.suggestedFee == null) return;
    setSending(r.userId);
    try {
      await senderApi.requestRider(bookingId, r.userId, r.suggestedFee);
      navigation.navigate('Track', { bookingId });
    } catch (e) {
      setState((s) => ({ ...s, error: e instanceof ApiClientError ? e.message : 'Could not send the request.' }));
    } finally {
      setSending(null);
    }
  }

  if (state.loading) return <LoadingState />;
  if (state.error) return <ErrorState message={state.error} onRetry={load} />;
  if (state.pending) return <EmptyState title="Finalising your price…" subtitle="Hang on a moment while we work out the fare." />;
  if (state.riders.length === 0) return <EmptyState title="No riders nearby yet" subtitle="We'll keep looking — pull to refresh." />;

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content}>
      <Text style={styles.title}>Choose a rider</Text>
      {state.riders.map((r) => {
        const stale = r.lastSeenSecondsAgo != null && r.lastSeenSecondsAgo > 900;
        return (
          <Card key={r.userId}>
            <View style={styles.priceRow}>
              <Text style={styles.riderName}>{r.fullName}</Text>
              <MoneyText amount={r.suggestedFee} />
            </View>
            <Text style={styles.soft}>
              {VEHICLE_LABEL[(r.vehicleType as VehicleType) ?? 'bike']}
              {r.distanceKm != null ? ` · ${r.distanceKm} km away` : ''}
              {r.etaMinutes != null ? ` · ~${r.etaMinutes} min` : ''}
              {r.rating != null ? ` · ★ ${r.rating}` : ''}
            </Text>
            {stale ? <Text style={styles.stale}>Last seen a while ago</Text> : null}
            <Button
              title={r.pricingAvailable ? 'Send request' : 'Price unavailable'}
              onPress={() => choose(r)}
              loading={sending === r.userId}
              disabled={!r.pricingAvailable || sending !== null}
            />
          </Card>
        );
      })}
    </ScrollView>
  );
}

type TrackRider = { fullName?: string; lat?: number | null; lng?: number | null; lastSeenSecondsAgo?: number | null };

export function TrackScreen({ navigation, route }: { navigation: Nav; route: Route<{ bookingId: number }> }) {
  const { bookingId } = route.params;
  const [status, setStatus] = useState<string>('');
  const [payment, setPayment] = useState<string>('');
  const [rider, setRider] = useState<TrackRider | null>(null);
  const [ends, setEnds] = useState<{ pickup?: MapPoint; dropoff?: MapPoint }>({});
  const [error, setError] = useState<string | null>(null);
  const [showCancel, setShowCancel] = useState(false);
  const [cancelReason, setCancelReason] = useState('');
  const [cancelling, setCancelling] = useState(false);
  const [rebooking, setRebooking] = useState(false);

  // Booking endpoints (pickup/drop-off) are fixed — fetch once for the map.
  useEffect(() => {
    senderApi.getBooking(bookingId).then(({ booking }) => {
      setEnds({
        pickup: booking.pickup.lat != null && booking.pickup.lng != null
          ? { lat: booking.pickup.lat, lng: booking.pickup.lng, label: 'Pickup', kind: 'pickup' } : undefined,
        dropoff: booking.dropoff.lat != null && booking.dropoff.lng != null
          ? { lat: booking.dropoff.lat, lng: booking.dropoff.lng, label: 'Drop-off', kind: 'dropoff' } : undefined,
      });
    }).catch(() => undefined);
  }, [bookingId]);

  const load = useCallback(async () => {
    try {
      const t = await senderApi.track(bookingId);
      setStatus(t.status);
      setPayment(t.paymentStatus);
      setRider((t.rider as TrackRider) ?? null);
    } catch {
      setError('Could not load tracking.');
    }
  }, [bookingId]);

  useEffect(() => {
    load();
    const t = setInterval(load, 10000);
    return () => clearInterval(t);
  }, [load]);

  // Cancellable pre-handover only; the backend enforces the exact rule and rejects otherwise.
  const canCancel = ['submitted', 'matched', 'accepted', 'arrived_at_pickup'].includes(status) && payment !== 'paid';
  // Editable only before a rider has accepted (server enforces this too).
  const canEdit = ['draft', 'submitted', 'matched'].includes(status);

  async function doRebook() {
    setRebooking(true);
    setError(null);
    try {
      await senderApi.rebook(bookingId);
      navigation.navigate('Riders', { bookingId }); // reopened → back to rider matching
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : 'Could not rebook this delivery.');
    } finally {
      setRebooking(false);
    }
  }

  async function doCancel() {
    if (!cancelReason.trim()) return;
    setCancelling(true);
    setError(null);
    try {
      await senderApi.cancel(bookingId, cancelReason.trim());
      setShowCancel(false);
      setCancelReason('');
      await load();
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : 'Could not cancel this booking.');
    } finally {
      setCancelling(false);
    }
  }

  if (error) return <ErrorState message={error} onRetry={load} />;

  const points: MapPoint[] = [];
  if (ends.pickup) points.push(ends.pickup);
  if (ends.dropoff) points.push(ends.dropoff);
  const riderStale = rider?.lastSeenSecondsAgo != null && rider.lastSeenSecondsAgo > 120;
  if (rider?.lat != null && rider?.lng != null && !riderStale) {
    points.push({ lat: rider.lat, lng: rider.lng, label: rider.fullName ?? 'Rider', kind: 'rider' });
  }

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content}>
      <Text style={styles.title}>Your delivery</Text>
      <MapPreview points={points} />
      <Card>
        <View style={styles.priceRow}>
          <Text style={styles.soft}>Status</Text>
          {status ? <StatusBadge status={status} /> : null}
        </View>
        {rider?.fullName ? (
          <Text style={styles.soft}>
            Rider: {rider.fullName}
            {rider.lastSeenSecondsAgo != null ? ` · seen ${rider.lastSeenSecondsAgo}s ago` : ''}
          </Text>
        ) : (
          <Text style={styles.soft}>Waiting for a rider to accept…</Text>
        )}
      </Card>
      {canEdit ? (
        <Button title="Edit booking" variant="secondary" onPress={() => navigation.navigate('EditBooking', { bookingId })} />
      ) : null}
      {status === 'cancelled' ? (
        <Button title="Rebook this delivery" onPress={doRebook} loading={rebooking} />
      ) : null}
      {rider?.fullName ? <CallButton bookingId={bookingId} label="Call rider" /> : null}
      {rider?.fullName ? (
        <Button title="Message rider" variant="secondary" onPress={() => navigation.navigate('Chat', { bookingId })} />
      ) : null}
      {status === 'delivered' && payment !== 'paid' ? (
        <Button title="Pay now" onPress={() => navigation.navigate('Pay', { bookingId })} />
      ) : null}
      {status === 'delivered' && payment === 'paid' ? (
        <Button title="Rate your rider" onPress={() => navigation.navigate('Rate', { bookingId })} />
      ) : null}
      {status === 'delivered' ? (
        <Button title="Report a problem" variant="secondary" onPress={() => navigation.navigate('Complaint', { bookingId })} />
      ) : null}
      {canCancel && !showCancel ? (
        <Button title="Cancel booking" variant="secondary" onPress={() => setShowCancel(true)} />
      ) : null}
      {showCancel ? (
        <Card>
          <Text style={styles.label}>Reason for cancelling</Text>
          <TextInput
            style={[styles.input, { minHeight: 72 }]}
            value={cancelReason}
            onChangeText={setCancelReason}
            placeholder="Let us know why…"
            placeholderTextColor={colors.textSoft}
            multiline
          />
          <View style={styles.priceRow}>
            <Button title="Keep booking" variant="secondary" onPress={() => setShowCancel(false)} style={{ flex: 1 }} />
            <Button title="Confirm cancel" variant="danger" onPress={doCancel} loading={cancelling} disabled={!cancelReason.trim()} style={{ flex: 1 }} />
          </View>
        </Card>
      ) : null}
    </ScrollView>
  );
}

export function PayScreen({ navigation, route }: { navigation: Nav; route: Route<{ bookingId: number }> }) {
  const { bookingId } = route.params;
  const [reference, setReference] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function start() {
    setBusy(true); setError(null);
    try {
      const res = await senderApi.payInit(bookingId, newIdempotencyKey());
      setReference(res.reference);
      if (res.authorizationUrl) await Linking.openURL(res.authorizationUrl); // Paystack checkout
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : 'Could not start the payment.');
    } finally {
      setBusy(false);
    }
  }

  async function verify() {
    if (!reference) return;
    setBusy(true); setError(null);
    try {
      const res = await senderApi.payVerify(reference);
      setStatus(res.paymentStatus);
      if (res.paymentStatus === 'paid') navigation.navigate('Rate', { bookingId });
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : 'Could not verify the payment.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={[styles.screen, styles.centerPad]}>
      <Text style={styles.title}>Payment</Text>
      <Card>
        <Text style={styles.soft}>Pay securely with Paystack. After paying, come back and tap “I’ve paid”.</Text>
        {!reference ? (
          <Button title="Pay with Paystack" onPress={start} loading={busy} />
        ) : (
          <Button title="I’ve paid — verify" onPress={verify} loading={busy} />
        )}
        {status ? <Text style={styles.soft}>Payment status: {status}</Text> : null}
        {error ? <Text style={styles.error}>{error}</Text> : null}
      </Card>
    </View>
  );
}

export function RateScreen({ navigation, route }: { navigation: Nav; route: Route<{ bookingId: number }> }) {
  const { bookingId } = route.params;
  const [rating, setRating] = useState(5);
  const [review, setReview] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const stars = useMemo(() => [1, 2, 3, 4, 5], []);

  async function submit() {
    setBusy(true); setError(null);
    try {
      await senderApi.rate(bookingId, rating, review.trim() || undefined);
      setDone(true);
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : 'Could not save your rating.');
    } finally {
      setBusy(false);
    }
  }

  if (done) return <EmptyState title="Thanks for your feedback!" subtitle="Your rating helps other senders." />;

  return (
    <View style={[styles.screen, styles.centerPad]}>
      <Text style={styles.title}>Rate your rider</Text>
      <Card>
        <View style={styles.stars}>
          {stars.map((s) => (
            <Text key={s} onPress={() => setRating(s)} style={[styles.star, { color: s <= rating ? colors.primary : colors.border }]}>
              ★
            </Text>
          ))}
        </View>
        <TextInput
          style={[styles.input, { minHeight: 90 }]}
          value={review}
          onChangeText={setReview}
          placeholder="Add a comment (optional)"
          placeholderTextColor={colors.textSoft}
          multiline
        />
        {error ? <Text style={styles.error}>{error}</Text> : null}
        <Button title="Submit rating" onPress={submit} loading={busy} />
      </Card>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: spacing.lg, gap: spacing.md },
  centerPad: { padding: spacing.lg, gap: spacing.md },
  title: { ...typography.h1, color: colors.text },
  label: { ...typography.small, color: colors.textSoft },
  soft: { ...typography.small, color: colors.textSoft },
  input: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: spacing.md, minHeight: 48, fontSize: 16, color: colors.text },
  vehicleRow: { flexDirection: 'row', gap: spacing.sm },
  vehicleBtn: { flex: 1, paddingHorizontal: spacing.sm },
  priceRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  riderName: { ...typography.h2, color: colors.text },
  stale: { ...typography.small, color: colors.warning },
  error: { color: colors.danger },
  stars: { flexDirection: 'row', justifyContent: 'center', gap: spacing.sm },
  star: { fontSize: 40 },
});
