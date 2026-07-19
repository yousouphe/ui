import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components';
import { useAuth } from '@/auth/AuthContext';
import { ApiClientError } from '@/api/client';
import { colors, radius, spacing, typography } from '@/theme/theme';

export function LoginScreen({ navigation }: { navigation?: { navigate: (s: string) => void } }) {
  const { t } = useTranslation();
  const { signIn } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function submit() {
    setError(null);
    setLoading(true);
    try {
      await signIn({ email: email.trim().toLowerCase(), password });
      // On success the root navigator swaps to the role-based tree automatically.
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : t('error.generic'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.flex}>
      <ScrollView contentContainerStyle={styles.container} keyboardShouldPersistTaps="handled">
        <Text style={styles.wordmark}>AIKE</Text>
        <Text style={styles.heading}>{t('auth.signIn')}</Text>
        {error ? <View style={styles.errorBox}><Text style={styles.errorText}>{error}</Text></View> : null}
        <Text style={styles.label}>{t('auth.email')}</Text>
        <TextInput
          style={styles.input}
          value={email}
          onChangeText={setEmail}
          autoCapitalize="none"
          keyboardType="email-address"
          autoComplete="email"
          accessibilityLabel={t('auth.email')}
        />
        <Text style={styles.label}>{t('auth.password')}</Text>
        <TextInput
          style={styles.input}
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          accessibilityLabel={t('auth.password')}
        />
        <Button title={t('auth.signIn')} onPress={submit} loading={loading} style={{ marginTop: spacing.lg }} />
        <Text style={styles.link} onPress={() => navigation?.navigate('ForgotPassword')} accessibilityRole="button">
          {t('auth.forgot')}
        </Text>
        <Text style={styles.link} onPress={() => navigation?.navigate('Register')} accessibilityRole="button">
          {t('auth.noAccount')} {t('auth.signUp')}
        </Text>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, backgroundColor: colors.bg },
  container: { flexGrow: 1, justifyContent: 'center', padding: spacing.xl, gap: spacing.sm },
  wordmark: { ...typography.wordmark, fontSize: 40, color: colors.primary, textAlign: 'center', marginBottom: spacing.md },
  heading: { ...typography.h1, color: colors.text, marginBottom: spacing.md },
  label: { ...typography.small, color: colors.textSoft, marginTop: spacing.sm },
  input: {
    backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md,
    padding: spacing.md, fontSize: 16, color: colors.text, minHeight: 48,
  },
  errorBox: { backgroundColor: 'rgba(220,38,38,0.1)', borderRadius: radius.md, padding: spacing.md },
  errorText: { color: colors.danger },
  link: { color: colors.primary, textAlign: 'center', marginTop: spacing.lg, fontWeight: '600' },
});
