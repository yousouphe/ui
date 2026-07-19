// Request a payout. Wired to POST /rider/withdrawals (transactional + idempotent server-side, so a
// double-tap or retry never double-spends). The available balance comes from /rider/wallet. Adding
// and verifying a bank account is not yet on mobile (R2) — until it is, the backend returns NO_BANK
// and we surface that message telling the rider to set up payout details on the web.
import React, { useCallback, useEffect, useState } from 'react';
import { ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { Button, Card, EmptyState, LoadingState, MoneyText } from '@/components';
import { riderApi, newIdempotencyKey } from '@/api/services';
import { ApiClientError } from '@/api/client';
import { colors, radius, spacing, typography } from '@/theme/theme';

export function WithdrawScreen({ navigation }: { navigation?: { goBack: () => void } }) {
  const [available, setAvailable] = useState<number | null>(null);
  const [amount, setAmount] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const w = await riderApi.wallet();
      setAvailable(w.availableBalance);
    } catch {
      setError('Could not load your balance.');
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => { load(); }, [load]);

  const value = Number(amount);
  const valid = Number.isFinite(value) && value > 0 && (available == null || value <= available);

  async function submit() {
    setBusy(true); setError(null);
    try {
      const res = await riderApi.withdraw(value, newIdempotencyKey());
      setDone(res.message);
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : 'Could not submit your withdrawal.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <LoadingState />;
  if (done) {
    return (
      <View style={styles.screen}>
        <EmptyState title="Withdrawal requested" subtitle={done} />
        <View style={styles.pad}><Button title="Back to wallet" onPress={() => navigation?.goBack()} /></View>
      </View>
    );
  }

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
      <Text style={styles.title}>Withdraw</Text>
      <Card>
        <Text style={styles.soft}>Available to withdraw</Text>
        <MoneyText amount={available} />
      </Card>
      <Card>
        <Text style={styles.soft}>Amount (₦)</Text>
        <TextInput style={styles.input} value={amount} onChangeText={setAmount} keyboardType="numeric" placeholder="0" placeholderTextColor={colors.textSoft} />
        <Text style={styles.soft}>Funds go to the bank account on your profile. Add or change it on the web app.</Text>
        {error ? <Text style={styles.error}>{error}</Text> : null}
        <Button title="Request withdrawal" onPress={submit} loading={busy} disabled={!valid || busy} />
      </Card>
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
