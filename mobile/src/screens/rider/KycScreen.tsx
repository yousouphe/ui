// Rider identity verification (KYC). Loads current status + saved details (GET /rider/kyc) and lets
// the rider submit/update biodata and document photos (POST /rider/kyc, multipart). Documents are
// picked from the gallery/camera and uploaded as image files — the backend validates type/size and
// stores them privately, then sets the profile back to "pending" for admin review. Only fields the
// rider fills are sent. Approval is decided by admins on the web; going online stays gated on it.
import React, { useCallback, useEffect, useState } from 'react';
import { ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import * as ImagePicker from 'expo-image-picker';
import { Button, Card, EmptyState, LoadingState } from '@/components';
import { riderApi } from '@/api/services';
import { ApiClientError } from '@/api/client';
import { colors, radius, spacing, typography } from '@/theme/theme';
import type { KycStatus, RiderKyc } from '@shared/contracts/api';

type Doc = { uri: string; name: string; type: string };
type DocKey = 'idDocument' | 'proofOfAddress' | 'vehicleDocument' | 'drivingLicense';

const BIODATA: { key: keyof RiderKyc['biodata']; label: string; numeric?: boolean }[] = [
  { key: 'age', label: 'Age', numeric: true },
  { key: 'stateOfOrigin', label: 'State of origin' },
  { key: 'lgaOfOrigin', label: 'LGA of origin' },
  { key: 'hometown', label: 'Hometown' },
  { key: 'nationalIdNumber', label: 'National ID (NIN)' },
  { key: 'address', label: 'Residential address' },
  { key: 'vehiclePlate', label: 'Vehicle plate number' },
  { key: 'vehicleColor', label: 'Vehicle colour' },
  { key: 'guarantorName', label: 'Guarantor name' },
  { key: 'guarantorPhone', label: 'Guarantor phone' },
  { key: 'guarantorAddress', label: 'Guarantor address' },
  { key: 'guarantorRelationship', label: 'Guarantor relationship' },
];

const DOCS: { key: DocKey; label: string }[] = [
  { key: 'idDocument', label: 'ID document' },
  { key: 'proofOfAddress', label: 'Proof of address' },
  { key: 'vehicleDocument', label: 'Vehicle document' },
  { key: 'drivingLicense', label: 'Driving licence' },
];

const STATUS_COLOR: Record<KycStatus, string> = { pending: colors.warning, approved: colors.success, rejected: colors.danger };

export function KycScreen() {
  const [loading, setLoading] = useState(true);
  const [kyc, setKyc] = useState<RiderKyc | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});
  const [docs, setDocs] = useState<Partial<Record<DocKey, Doc>>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const load = useCallback(async () => {
    try {
      const res = await riderApi.kyc();
      setKyc(res);
    } catch {
      setError('Could not load your verification details.');
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => { load(); }, [load]);

  async function pick(key: DocKey) {
    const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!perm.granted) { setError('Photo permission is needed to attach a document.'); return; }
    const res = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ImagePicker.MediaTypeOptions.Images, quality: 0.7 });
    if (res.canceled || !res.assets?.[0]) return;
    const a = res.assets[0];
    const name = a.fileName ?? `${key}.jpg`;
    const type = a.mimeType ?? 'image/jpeg';
    setDocs((d) => ({ ...d, [key]: { uri: a.uri, name, type } }));
    setError(null);
  }

  async function submit() {
    setBusy(true); setError(null);
    const form = new FormData();
    let any = false;
    for (const { key } of BIODATA) {
      const v = (fields[key] ?? '').trim();
      if (v) { form.append(key, v); any = true; }
    }
    for (const { key } of DOCS) {
      const doc = docs[key];
      if (doc) { form.append(key, { uri: doc.uri, name: doc.name, type: doc.type } as unknown as Blob); any = true; }
    }
    if (!any) { setError('Add at least one detail or document.'); setBusy(false); return; }
    try {
      await riderApi.submitKyc(form);
      setDone(true);
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : 'Could not submit your verification.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <LoadingState />;
  if (done) {
    return (
      <View style={styles.screen}>
        <EmptyState title="Submitted for review" subtitle="Our team will review your details. You'll be able to go online once approved." />
      </View>
    );
  }

  const status = kyc?.kycStatus ?? 'pending';

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
      <Text style={styles.title}>Verification</Text>
      <Card>
        <View style={styles.row}>
          <Text style={styles.soft}>Status</Text>
          <View style={[styles.badge, { backgroundColor: STATUS_COLOR[status] }]}><Text style={styles.badgeText}>{status}</Text></View>
        </View>
        {status === 'approved' ? <Text style={styles.soft}>You're verified. Submitting again will send your details back for review.</Text> : null}
        {status === 'rejected' && kyc?.note ? <Text style={styles.reject}>Reason: {kyc.note}</Text> : null}
      </Card>

      <Card>
        <Text style={styles.h2}>Your details</Text>
        {BIODATA.map((f) => (
          <View key={f.key} style={{ gap: spacing.xs }}>
            <Text style={styles.label}>{f.label}</Text>
            <TextInput
              style={styles.input}
              value={fields[f.key] ?? ''}
              onChangeText={(t) => setFields((s) => ({ ...s, [f.key]: t }))}
              keyboardType={f.numeric ? 'number-pad' : 'default'}
              placeholder={kyc?.biodata[f.key] != null ? String(kyc.biodata[f.key]) : ''}
              placeholderTextColor={colors.textSoft}
            />
          </View>
        ))}
      </Card>

      <Card>
        <Text style={styles.h2}>Documents</Text>
        {DOCS.map((d) => {
          const onFile = kyc?.documents[d.key];
          const picked = docs[d.key];
          return (
            <TouchableOpacity key={d.key} onPress={() => pick(d.key)} style={styles.docRow} accessibilityRole="button">
              <Text style={styles.docLabel}>{d.label}</Text>
              <Text style={[styles.docState, (picked || onFile) && styles.docStateOk]}>
                {picked ? '✓ selected' : onFile ? '✓ on file — replace' : 'Attach'}
              </Text>
            </TouchableOpacity>
          );
        })}
        <Text style={styles.soft}>JPG, PNG or WEBP, up to 5MB each.</Text>
      </Card>

      {error ? <Text style={styles.error}>{error}</Text> : null}
      <Button title="Submit for review" onPress={submit} loading={busy} />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: spacing.lg, gap: spacing.md },
  title: { ...typography.h1, color: colors.text },
  h2: { ...typography.h2, color: colors.text },
  label: { ...typography.small, color: colors.textSoft },
  soft: { ...typography.small, color: colors.textSoft },
  reject: { ...typography.small, color: colors.danger },
  input: { backgroundColor: colors.bg, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: spacing.md, minHeight: 48, fontSize: 16, color: colors.text },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  badge: { borderRadius: radius.pill, paddingHorizontal: spacing.md, paddingVertical: 4 },
  badgeText: { color: '#fff', fontSize: 12, fontWeight: '700', textTransform: 'capitalize' },
  docRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingVertical: spacing.sm, borderBottomWidth: 1, borderBottomColor: colors.border },
  docLabel: { ...typography.body, color: colors.text },
  docState: { ...typography.small, color: colors.primary, fontWeight: '700' },
  docStateOk: { color: colors.success },
  error: { color: colors.danger },
});
