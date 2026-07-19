// Shown after a Google sign-up, before the main app: a Google account has no phone number, and
// mobile parity lets a new user also choose to ride. Posts to /profile/complete; on success the
// user object gains profileCompleted=true (and role=rider if chosen), and the gate in the root
// navigator falls away to the role-based tree.
import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Switch, Text, TextInput, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components';
import { useAuth } from '@/auth/AuthContext';
import { ApiClientError } from '@/api/client';
import { VEHICLE_TYPES, VEHICLE_LABEL, type VehicleType } from '@shared/constants/vehicles';
import { colors, radius, spacing, typography } from '@/theme/theme';

export function CompleteProfileScreen() {
  const { t } = useTranslation();
  const { user, completeProfile, signOut } = useAuth();
  const [phone, setPhone] = useState('');
  const [asRider, setAsRider] = useState(false);
  const [vehicleType, setVehicleType] = useState<VehicleType>('bike');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const canBecomeRider = user?.role === 'sender'; // only a fresh (sender-default) account can opt in

  async function submit() {
    setBusy(true); setError(null);
    try {
      await completeProfile({ phone: phone.trim(), ...(canBecomeRider && asRider ? { role: 'rider', vehicleType } : {}) });
      // Success: AuthContext updates the user; the gate disappears.
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : t('error.generic'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
        <Text style={styles.heading}>{t('auth.completeTitle')}</Text>
        <Text style={styles.body}>{t('auth.completeBody')}</Text>
        {error ? <View style={styles.errorBox}><Text style={styles.errorText}>{error}</Text></View> : null}

        <Text style={styles.label}>{t('auth.phone')}</Text>
        <TextInput style={styles.input} value={phone} onChangeText={setPhone} keyboardType="phone-pad" autoComplete="tel" accessibilityLabel={t('auth.phone')} />

        {canBecomeRider ? (
          <View style={styles.riderCard}>
            <View style={styles.row}>
              <Text style={styles.riderTitle}>{t('auth.becomeRider')}</Text>
              <Switch value={asRider} onValueChange={setAsRider} />
            </View>
            {asRider ? (
              <>
                <Text style={styles.label}>{t('auth.vehicle')}</Text>
                <View style={styles.vehicleRow}>
                  {VEHICLE_TYPES.map((v) => (
                    <Button key={v} title={VEHICLE_LABEL[v]} variant={vehicleType === v ? 'primary' : 'secondary'} onPress={() => setVehicleType(v)} style={styles.flexBtn} />
                  ))}
                </View>
                <Text style={styles.hint}>{t('auth.riderKycNote')}</Text>
              </>
            ) : null}
          </View>
        ) : null}

        <Button title={t('auth.completeSubmit')} onPress={submit} loading={busy} disabled={!phone.trim()} style={{ marginTop: spacing.lg }} />
        <Text style={styles.link} onPress={() => { void signOut(); }} accessibilityRole="button">{t('auth.signOut')}</Text>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, backgroundColor: colors.bg },
  container: { padding: spacing.xl, gap: spacing.xs },
  heading: { ...typography.h1, color: colors.text },
  body: { ...typography.body, color: colors.textSoft, marginBottom: spacing.md },
  label: { ...typography.small, color: colors.textSoft, marginTop: spacing.md },
  hint: { ...typography.small, color: colors.textSoft },
  input: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: spacing.md, minHeight: 48, fontSize: 16, color: colors.text },
  riderCard: { marginTop: spacing.lg, padding: spacing.md, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface, gap: spacing.sm },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  riderTitle: { ...typography.h2, color: colors.text },
  vehicleRow: { flexDirection: 'row', gap: spacing.sm },
  flexBtn: { flex: 1, paddingHorizontal: spacing.sm },
  errorBox: { backgroundColor: 'rgba(214,69,69,0.1)', borderRadius: radius.md, padding: spacing.md },
  errorText: { color: colors.danger },
  link: { ...typography.body, color: colors.primary, fontWeight: '700', textAlign: 'center', marginTop: spacing.lg },
});
