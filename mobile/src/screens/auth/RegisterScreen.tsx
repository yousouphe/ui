// Full sign-up (sender or rider). Wired to POST /auth/register via authApi.register; on success
// the root navigator swaps to the role-based tree automatically (the server-returned role decides).
// Riders pick a vehicle and start pending KYC approval — the backend enforces that gate, not us.
import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components';
import { GoogleSignInButton } from '@/components/GoogleSignInButton';
import { useAuth } from '@/auth/AuthContext';
import { ApiClientError } from '@/api/client';
import { VEHICLE_TYPES, VEHICLE_LABEL, type VehicleType } from '@shared/constants/vehicles';
import { colors, radius, spacing, typography } from '@/theme/theme';

type Role = 'sender' | 'rider';
type Nav = { navigate: (s: string) => void; goBack: () => void };

export function RegisterScreen({ navigation }: { navigation?: Nav }) {
  const { t } = useTranslation();
  const { register } = useAuth();
  const [role, setRole] = useState<Role>('sender');
  const [fullName, setFullName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [vehicleType, setVehicleType] = useState<VehicleType>('bike');
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);

  const canSubmit = fullName.trim() && email.trim() && phone.trim() && password.length >= 8 && !loading;

  async function submit() {
    setError(null);
    setFieldErrors({});
    setLoading(true);
    try {
      await register({
        fullName: fullName.trim(),
        email: email.trim().toLowerCase(),
        phone: phone.trim(),
        password,
        role,
        ...(role === 'rider' ? { vehicleType } : {}),
      });
      // Success: AuthContext sets the user and the navigator swaps trees.
    } catch (e) {
      if (e instanceof ApiClientError) {
        setError(e.message);
        setFieldErrors(e.fields ?? {});
      } else {
        setError(t('error.generic'));
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
        <Text style={styles.heading}>{t('auth.signUp')}</Text>
        {error ? <View style={styles.errorBox}><Text style={styles.errorText}>{error}</Text></View> : null}

        <Text style={styles.label}>{t('auth.iWantTo')}</Text>
        <View style={styles.roleRow}>
          <Button title={t('auth.roleSender')} variant={role === 'sender' ? 'primary' : 'secondary'} onPress={() => setRole('sender')} style={styles.flexBtn} />
          <Button title={t('auth.roleRider')} variant={role === 'rider' ? 'primary' : 'secondary'} onPress={() => setRole('rider')} style={styles.flexBtn} />
        </View>

        <Text style={styles.label}>{t('auth.fullName')}</Text>
        <TextInput style={styles.input} value={fullName} onChangeText={setFullName} autoCapitalize="words" accessibilityLabel={t('auth.fullName')} />
        {fieldErrors.fullName ? <Text style={styles.fieldError}>{fieldErrors.fullName}</Text> : null}

        <Text style={styles.label}>{t('auth.email')}</Text>
        <TextInput style={styles.input} value={email} onChangeText={setEmail} autoCapitalize="none" keyboardType="email-address" autoComplete="email" accessibilityLabel={t('auth.email')} />
        {fieldErrors.email ? <Text style={styles.fieldError}>{fieldErrors.email}</Text> : null}

        <Text style={styles.label}>{t('auth.phone')}</Text>
        <TextInput style={styles.input} value={phone} onChangeText={setPhone} keyboardType="phone-pad" autoComplete="tel" accessibilityLabel={t('auth.phone')} />
        {fieldErrors.phone ? <Text style={styles.fieldError}>{fieldErrors.phone}</Text> : null}

        <Text style={styles.label}>{t('auth.password')}</Text>
        <TextInput style={styles.input} value={password} onChangeText={setPassword} secureTextEntry accessibilityLabel={t('auth.password')} />
        <Text style={styles.hint}>{t('auth.passwordHint')}</Text>
        {fieldErrors.password ? <Text style={styles.fieldError}>{fieldErrors.password}</Text> : null}

        {role === 'rider' ? (
          <>
            <Text style={styles.label}>{t('auth.vehicle')}</Text>
            <View style={styles.roleRow}>
              {VEHICLE_TYPES.map((v) => (
                <Button key={v} title={VEHICLE_LABEL[v]} variant={vehicleType === v ? 'primary' : 'secondary'} onPress={() => setVehicleType(v)} style={styles.flexBtn} />
              ))}
            </View>
            <Text style={styles.hint}>{t('auth.riderKycNote')}</Text>
          </>
        ) : null}

        <Button title={t('auth.signUp')} onPress={submit} loading={loading} disabled={!canSubmit} style={{ marginTop: spacing.lg }} />
        <GoogleSignInButton onError={(m) => setError(m || null)} />
        <Text style={styles.link} onPress={() => navigation?.navigate('Login')} accessibilityRole="button">
          {t('auth.haveAccount')} {t('auth.signIn')}
        </Text>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, backgroundColor: colors.bg },
  container: { padding: spacing.xl, gap: spacing.xs },
  heading: { ...typography.h1, color: colors.text, marginBottom: spacing.md },
  label: { ...typography.small, color: colors.textSoft, marginTop: spacing.md },
  input: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: spacing.md, minHeight: 48, fontSize: 16, color: colors.text },
  hint: { ...typography.small, color: colors.textSoft },
  roleRow: { flexDirection: 'row', gap: spacing.sm },
  flexBtn: { flex: 1, paddingHorizontal: spacing.sm },
  errorBox: { backgroundColor: 'rgba(214,69,69,0.1)', borderRadius: radius.md, padding: spacing.md },
  errorText: { color: colors.danger },
  fieldError: { color: colors.danger, ...typography.small },
  link: { ...typography.body, color: colors.primary, fontWeight: '700', textAlign: 'center', marginTop: spacing.lg },
});
