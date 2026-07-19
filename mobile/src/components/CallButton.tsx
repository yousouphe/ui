// "Call" opens the DEVICE DIALLER with the counterpart's number (fetched from the backend, which
// enforces that only the two parties can read it). In-app calling is intentionally NOT offered on
// mobile — there is no WebRTC infra there — so we never claim it; this is the reliable path.
import React, { useState } from 'react';
import { Alert, Linking } from 'react-native';
import { Button } from './Button';
import { senderApi } from '@/api/services';
import { ApiClientError } from '@/api/client';

export function CallButton({ bookingId, label = 'Call' }: { bookingId: number; label?: string }) {
  const [busy, setBusy] = useState(false);
  async function call() {
    setBusy(true);
    try {
      const c = await senderApi.contact(bookingId); // shared endpoint; works for sender & rider
      const url = `tel:${c.phone.replace(/[^\d+]/g, '')}`;
      const ok = await Linking.canOpenURL(url);
      if (ok) await Linking.openURL(url);
      else Alert.alert('Call', `${c.fullName}: ${c.phone}`);
    } catch (e) {
      Alert.alert('Call', e instanceof ApiClientError ? e.message : 'No phone number is available yet.');
    } finally {
      setBusy(false);
    }
  }
  return <Button title={label} variant="secondary" onPress={call} loading={busy} />;
}
