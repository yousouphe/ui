// Request a password-reset email. Wired to POST /auth/forgot, which always returns the same generic
// result (no email enumeration) — so the screen shows a generic confirmation regardless. The user
// then opens the emailed link; ResetPasswordScreen completes it with the token.
import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components';
import { authApi } from '@/api/services';
import { ApiClientError } from '@/api/client';
import { colors, radius, spacing, typography } from '@/theme/theme';

type Nav = { navigate: (s: string) => void };

export function ForgotPasswordScreen({ navigation }: { navigation?: Nav }) {
  const { t } = useTranslation();
  const [email, setEmail] = useState('');
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function submit() {
    setError(null);
    setLoading(true);
    try {
      const res = await authApi.forgot(email.trim().toLowerCase());
      setMessage(res.message);
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : t('error.generic'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
        <Text style={styles.heading}>{t('auth.forgotTitle')}</Text>
        <Text style={styles.body}>{t('auth.forgotBody')}</Text>
        {message ? <View style={styles.okBox}><Text style={styles.okText}>{message}</Text></View> : null}
        {error ? <View style={styles.errorBox}><Text style={styles.errorText}>{error}</Text></View> : null}
        <Text style={styles.label}>{t('auth.email')}</Text>
        <TextInput style={styles.input} value={email} onChangeText={setEmail} autoCapitalize="none" keyboardType="email-address" autoComplete="email" accessibilityLabel={t('auth.email')} />
        <Button title={t('auth.sendResetLink')} onPress={submit} loading={loading} disabled={!email.trim() || loading} style={{ marginTop: spacing.lg }} />
        <Text style={styles.link} onPress={() => navigation?.navigate('ResetPassword')} accessibilityRole="button">{t('auth.haveResetCode')}</Text>
        <Text style={styles.link} onPress={() => navigation?.navigate('Login')} accessibilityRole="button">{t('auth.backToSignIn')}</Text>
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
  input: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: spacing.md, minHeight: 48, fontSize: 16, color: colors.text },
  okBox: { backgroundColor: 'rgba(34,153,84,0.12)', borderRadius: radius.md, padding: spacing.md },
  okText: { color: colors.success },
  errorBox: { backgroundColor: 'rgba(214,69,69,0.1)', borderRadius: radius.md, padding: spacing.md },
  errorText: { color: colors.danger },
  link: { ...typography.body, color: colors.primary, fontWeight: '700', textAlign: 'center', marginTop: spacing.lg },
});
