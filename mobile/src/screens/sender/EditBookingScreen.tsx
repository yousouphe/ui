// Edit a booking before a rider has accepted it. The delivery address can be changed (prefilled
// from the current drop-off) and the backend reprices when a rider is already selected and the new
// destination is farther — the price shown always comes from the server, never computed here.
// Detail fields (recipient/item/notes) are optional overrides: left blank they're untouched.
import React, { useEffect, useState } from 'react';
import { ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { Button, Card, EmptyState, LoadingState } from '@/components';
import { AddressSearch } from '@/components/AddressSearch';
import { senderApi } from '@/api/services';
import { ApiClientError } from '@/api/client';
import type { Place } from '@/api/geo';
import type { UpdateBookingRequest } from '@shared/contracts/api';
import { colors, radius, spacing, typography } from '@/theme/theme';

type Nav = { goBack: () => void };
type Route = { params: { bookingId: number } };

export function EditBookingScreen({ navigation, route }: { navigation: Nav; route: Route }) {
  const { bookingId } = route.params;
  const [loading, setLoading] = useState(true);
  const [editable, setEditable] = useState(true);
  const [dropoff, setDropoff] = useState<Place | null>(null);
  const [recipientName, setRecipientName] = useState('');
  const [recipientPhone, setRecipientPhone] = useState('');
  const [itemName, setItemName] = useState('');
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  useEffect(() => {
    senderApi.getBooking(bookingId).then(({ booking }) => {
      // Only pre-handover states can be edited (server enforces this too).
      setEditable(['draft', 'submitted', 'matched'].includes(booking.status));
      if (booking.dropoff.address && booking.dropoff.lat != null && booking.dropoff.lng != null) {
        setDropoff({ name: booking.dropoff.address, address: booking.dropoff.address, lat: booking.dropoff.lat, lng: booking.dropoff.lng });
      }
    }).catch(() => setError('Could not load this booking.')).finally(() => setLoading(false));
  }, [bookingId]);

  async function save() {
    setBusy(true); setError(null);
    const patch: UpdateBookingRequest = {};
    if (dropoff) patch.dropoff = { address: dropoff.address, lat: dropoff.lat, lng: dropoff.lng };
    if (recipientName.trim()) patch.recipientName = recipientName.trim();
    if (recipientPhone.trim()) patch.recipientPhone = recipientPhone.trim();
    if (itemName.trim()) patch.itemName = itemName.trim();
    if (notes.trim()) patch.notes = notes.trim();
    if (Object.keys(patch).length === 0) {
      setError('Change the delivery address or a detail to save.');
      setBusy(false);
      return;
    }
    try {
      const res = await senderApi.updateBooking(bookingId, patch);
      setDone(res.priceChanged ? 'Saved — the price was recalculated for the new distance.' : 'Booking updated.');
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : 'Could not save your changes.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <LoadingState />;
  if (!editable) {
    return (
      <View style={styles.screen}>
        <EmptyState title="This booking can't be edited" subtitle="Details can only change before a rider accepts the delivery." />
        <View style={styles.pad}><Button title="Back" onPress={() => navigation.goBack()} /></View>
      </View>
    );
  }
  if (done) {
    return (
      <View style={styles.screen}>
        <EmptyState title="Changes saved" subtitle={done} />
        <View style={styles.pad}><Button title="Done" onPress={() => navigation.goBack()} /></View>
      </View>
    );
  }

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
      <Text style={styles.title}>Edit booking</Text>
      <Card>
        <AddressSearch label="Delivery address" value={dropoff} onSelect={setDropoff} />
        <Text style={styles.soft}>If a rider is already assigned, moving the drop-off farther will recalculate the fare.</Text>
      </Card>
      <Card>
        <Text style={styles.soft}>Update details (optional)</Text>
        <TextInput style={styles.input} value={itemName} onChangeText={setItemName} placeholder="What are you sending?" placeholderTextColor={colors.textSoft} />
        <TextInput style={styles.input} value={recipientName} onChangeText={setRecipientName} placeholder="Recipient name" placeholderTextColor={colors.textSoft} />
        <TextInput style={styles.input} value={recipientPhone} onChangeText={setRecipientPhone} placeholder="Recipient phone" placeholderTextColor={colors.textSoft} keyboardType="phone-pad" />
        <TextInput style={[styles.input, { minHeight: 80 }]} value={notes} onChangeText={setNotes} placeholder="Delivery notes" placeholderTextColor={colors.textSoft} multiline />
      </Card>
      {error ? <Text style={styles.error}>{error}</Text> : null}
      <Button title="Save changes" onPress={save} loading={busy} />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: spacing.lg, gap: spacing.md },
  pad: { padding: spacing.lg },
  title: { ...typography.h1, color: colors.text },
  soft: { ...typography.small, color: colors.textSoft },
  input: { backgroundColor: colors.bg, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: spacing.md, minHeight: 48, fontSize: 16, color: colors.text },
  error: { color: colors.danger },
});
