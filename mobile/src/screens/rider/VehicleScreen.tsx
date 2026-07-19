// Change the rider's vehicle type (PATCH /rider/profile). Pricing/ETA per vehicle stay server-side;
// this only records which vehicle the rider uses, which the matching + fare engine then reads.
import React, { useEffect, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import { Button, Card, LoadingState } from '@/components';
import { riderApi } from '@/api/services';
import { ApiClientError } from '@/api/client';
import { VEHICLE_TYPES, VEHICLE_LABEL, type VehicleType } from '@shared/constants/vehicles';
import { colors, spacing, typography } from '@/theme/theme';

export function VehicleScreen() {
  const [loading, setLoading] = useState(true);
  const [vehicle, setVehicle] = useState<VehicleType>('bike');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    riderApi.profile().then((p) => {
      const v = (p as { vehicleType?: string }).vehicleType;
      if (v === 'bike' || v === 'car' || v === 'van') setVehicle(v);
    }).catch(() => undefined).finally(() => setLoading(false));
  }, []);

  async function save() {
    setSaving(true); setError(null); setSaved(false);
    try {
      await riderApi.updateVehicle(vehicle);
      setSaved(true);
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : 'Could not update your vehicle.');
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <LoadingState />;

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content}>
      <Text style={styles.title}>Your vehicle</Text>
      <Card>
        <Text style={styles.soft}>Deliveries are matched to your vehicle type.</Text>
        <View style={styles.row}>
          {VEHICLE_TYPES.map((v) => (
            <Button key={v} title={VEHICLE_LABEL[v]} variant={vehicle === v ? 'primary' : 'secondary'} onPress={() => { setVehicle(v); setSaved(false); }} style={styles.flex} />
          ))}
        </View>
        {saved ? <Text style={styles.ok}>✓ Saved</Text> : null}
        {error ? <Text style={styles.error}>{error}</Text> : null}
        <Button title="Save vehicle" onPress={save} loading={saving} />
      </Card>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: spacing.lg, gap: spacing.md },
  title: { ...typography.h1, color: colors.text },
  soft: { ...typography.small, color: colors.textSoft },
  ok: { color: colors.success, fontWeight: '700' },
  error: { color: colors.danger },
  row: { flexDirection: 'row', gap: spacing.sm },
  flex: { flex: 1, paddingHorizontal: spacing.sm },
});
