// Complete a password reset with the emailed token + a new password. Wired to POST /auth/reset,
// which validates the single-use, 30-min token server-side and revokes existing sessions. The user
// pastes the token from the email (deep-link auto-fill can be added later); on success we send them
// back to sign in.
import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button, EmptyState } from '@/components';
import { authApi } from '@/api/services';
import { ApiClientError } from '@/api/client';
import { colors, radius, spacing, typography } from '@/theme/theme';

type Nav = { navigate: (s: string) => void };
type Route = { params?: { token?: string } };

export function ResetPasswordScreen({ navigation, route }: { navigation?: Nav; route?: Route }) {
  const { t } = useTranslation();
  const [token, setToken] = useState(route?.params?.token ?? '');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [done, setDone] = useState(false);

  async function submit() {
    setError(null);
    setLoading(true);
    try {
      await authApi.reset(token.trim(), password);
      setDone(true);
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : t('error.generic'));
    } finally {
      setLoading(false);
    }
  }

  if (done) {
    return (
      <View style={styles.doneWrap}>
        <EmptyState title={t('auth.resetDoneTitle')} subtitle={t('auth.resetDoneBody')} />
        <View style={styles.donePad}>
          <Button title={t('auth.signIn')} onPress={() => navigation?.navigate('Login')} />
        </View>
      </View>
    );
  }

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
        <Text style={styles.heading}>{t('auth.resetTitle')}</Text>
        <Text style={styles.body}>{t('auth.resetBody')}</Text>
        {error ? <View style={styles.errorBox}><Text style={styles.errorText}>{error}</Text></View> : null}
        <Text style={styles.label}>{t('auth.resetToken')}</Text>
        <TextInput style={styles.input} value={token} onChangeText={setToken} autoCapitalize="none" autoCorrect={false} accessibilityLabel={t('auth.resetToken')} />
        <Text style={styles.label}>{t('auth.newPassword')}</Text>
        <TextInput style={styles.input} value={password} onChangeText={setPassword} secureTextEntry accessibilityLabel={t('auth.newPassword')} />
        <Text style={styles.hint}>{t('auth.passwordHint')}</Text>
        <Button title={t('auth.resetSubmit')} onPress={submit} loading={loading} disabled={!token.trim() || password.length < 8 || loading} style={{ marginTop: spacing.lg }} />
        <Text style={styles.link} onPress={() => navigation?.navigate('Login')} accessibilityRole="button">{t('auth.backToSignIn')}</Text>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, backgroundColor: colors.bg },
  container: { padding: spacing.xl, gap: spacing.xs },
  doneWrap: { flex: 1, backgroundColor: colors.bg },
  donePad: { padding: spacing.xl },
  heading: { ...typography.h1, color: colors.text },
  body: { ...typography.body, color: colors.textSoft, marginBottom: spacing.md },
  label: { ...typography.small, color: colors.textSoft, marginTop: spacing.md },
  input: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: spacing.md, minHeight: 48, fontSize: 16, color: colors.text },
  hint: { ...typography.small, color: colors.textSoft },
  errorBox: { backgroundColor: 'rgba(214,69,69,0.1)', borderRadius: radius.md, padding: spacing.md },
  errorText: { color: colors.danger },
  link: { ...typography.body, color: colors.primary, fontWeight: '700', textAlign: 'center', marginTop: spacing.lg },
});
