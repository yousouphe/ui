// Set up the rider's payout account. Loads the Paystack bank list (GET /rider/banks) and the saved
// account (GET /rider/bank), lets the rider pick a bank + enter an account number, verify it
// (POST /rider/bank/verify → resolved name preview), and save (POST /rider/bank). The resolved name
// is always the one Paystack returns — the backend never trusts a typed name.
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { Button, Card, LoadingState } from '@/components';
import { riderApi } from '@/api/services';
import { ApiClientError } from '@/api/client';
import { colors, radius, spacing, typography } from '@/theme/theme';
import type { RiderBankAccount } from '@shared/contracts/api';

type Bank = { code: string; name: string };

export function BankAccountScreen() {
  const [loading, setLoading] = useState(true);
  const [banks, setBanks] = useState<Bank[]>([]);
  const [current, setCurrent] = useState<RiderBankAccount | null>(null);
  const [query, setQuery] = useState('');
  const [bank, setBank] = useState<Bank | null>(null);
  const [accountNumber, setAccountNumber] = useState('');
  const [resolvedName, setResolvedName] = useState<string | null>(null);
  const [verifying, setVerifying] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const load = useCallback(async () => {
    try {
      const [b, c] = await Promise.all([riderApi.banks(), riderApi.bank()]);
      setBanks(b.banks);
      setCurrent(c.bank);
    } catch {
      setError('Could not load bank details.');
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => { load(); }, [load]);

  const matches = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q || bank) return [];
    return banks.filter((b) => b.name.toLowerCase().includes(q)).slice(0, 8);
  }, [query, banks, bank]);

  async function verify() {
    if (!bank) return;
    setVerifying(true); setError(null); setResolvedName(null);
    try {
      const res = await riderApi.verifyBank(accountNumber.trim(), bank.code);
      setResolvedName(res.accountName);
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : 'Could not verify the account.');
    } finally {
      setVerifying(false);
    }
  }

  async function save() {
    if (!bank) return;
    setSaving(true); setError(null);
    try {
      const res = await riderApi.saveBank(accountNumber.trim(), bank.code);
      setCurrent({ bankName: res.bankName, bankCode: bank.code, accountNumberMasked: '****' + accountNumber.trim().slice(-4), accountName: res.accountName, verified: true });
      setSaved(true);
      setBank(null); setQuery(''); setAccountNumber(''); setResolvedName(null);
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : 'Could not save the account.');
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <LoadingState />;

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
      <Text style={styles.title}>Payout bank</Text>
      {current ? (
        <Card>
          <Text style={styles.soft}>Current payout account{saved ? ' (updated)' : ''}</Text>
          <Text style={styles.h2}>{current.accountName}</Text>
          <Text style={styles.soft}>{current.bankName} · {current.accountNumberMasked}</Text>
        </Card>
      ) : null}
      <Card>
        <Text style={styles.soft}>{current ? 'Change account' : 'Add your account'}</Text>
        {bank ? (
          <TouchableOpacity onPress={() => { setBank(null); setResolvedName(null); }} accessibilityRole="button">
            <Text style={styles.picked}>{bank.name} — tap to change</Text>
          </TouchableOpacity>
        ) : (
          <>
            <TextInput style={styles.input} value={query} onChangeText={setQuery} placeholder="Search your bank" placeholderTextColor={colors.textSoft} />
            {matches.map((b) => (
              <TouchableOpacity key={b.code} onPress={() => { setBank(b); setQuery(b.name); }} style={styles.bankRow} accessibilityRole="button">
                <Text style={styles.bankName}>{b.name}</Text>
              </TouchableOpacity>
            ))}
          </>
        )}
        <TextInput style={styles.input} value={accountNumber} onChangeText={(t) => { setAccountNumber(t); setResolvedName(null); }} placeholder="Account number" placeholderTextColor={colors.textSoft} keyboardType="number-pad" maxLength={10} />
        {resolvedName ? <Text style={styles.ok}>✓ {resolvedName}</Text> : null}
        {error ? <Text style={styles.error}>{error}</Text> : null}
        <View style={styles.row}>
          <Button title="Verify" variant="secondary" onPress={verify} loading={verifying} disabled={!bank || accountNumber.trim().length < 10} style={styles.flex} />
          <Button title="Save" onPress={save} loading={saving} disabled={!resolvedName} style={styles.flex} />
        </View>
        <Text style={styles.soft}>Verify first — we save the name your bank returns.</Text>
      </Card>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: spacing.lg, gap: spacing.md },
  title: { ...typography.h1, color: colors.text },
  h2: { ...typography.h2, color: colors.text },
  soft: { ...typography.small, color: colors.textSoft },
  input: { backgroundColor: colors.bg, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: spacing.md, minHeight: 48, fontSize: 16, color: colors.text },
  bankRow: { paddingVertical: spacing.sm, borderBottomWidth: 1, borderBottomColor: colors.border },
  bankName: { ...typography.body, color: colors.text },
  picked: { ...typography.body, color: colors.primary, fontWeight: '700' },
  ok: { color: colors.success, fontWeight: '700' },
  error: { color: colors.danger },
  row: { flexDirection: 'row', gap: spacing.md },
  flex: { flex: 1 },
});
