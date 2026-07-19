// Sender payment receipts: every booking they've paid for, newest first (GET /payments). There is
// no separate charge ledger — a paid booking carries its Paystack reference and amount.
import React, { useCallback, useEffect, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Card, EmptyState, ErrorState, LoadingState, MoneyText } from '@/components';
import { senderApi } from '@/api/services';
import { colors, spacing, typography } from '@/theme/theme';
import type { PaymentReceipt } from '@shared/contracts/api';

export function ReceiptsScreen() {
  const [state, setState] = useState<{ loading: boolean; error: string | null; items: PaymentReceipt[] }>({ loading: true, error: null, items: [] });

  const load = useCallback(async () => {
    try {
      const res = await senderApi.payments();
      setState({ loading: false, error: null, items: res.payments });
    } catch {
      setState((s) => ({ ...s, loading: false, error: 'Could not load your receipts.' }));
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  if (state.loading) return <LoadingState />;
  if (state.error) return <ErrorState message={state.error} onRetry={load} />;
  if (state.items.length === 0) {
    return <EmptyState title="No receipts yet" subtitle="Payments for your deliveries will appear here." />;
  }

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content} refreshControl={<RefreshControl refreshing={false} onRefresh={load} />}>
      <Text style={styles.title}>Receipts</Text>
      {state.items.map((r) => (
        <Card key={`${r.bookingId}-${r.reference ?? ''}`}>
          <View style={styles.row}>
            <Text style={styles.code}>{r.bookingCode}</Text>
            <MoneyText amount={r.amount} />
          </View>
          <Text style={styles.soft}>{new Date(r.paidAt).toLocaleString()}</Text>
          {r.reference ? <Text style={styles.ref}>Ref: {r.reference}</Text> : null}
        </Card>
      ))}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: spacing.lg, gap: spacing.md },
  title: { ...typography.h1, color: colors.text },
  soft: { ...typography.small, color: colors.textSoft },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: spacing.md },
  code: { ...typography.h2, color: colors.text, flexShrink: 1 },
  ref: { ...typography.small, color: colors.textSoft, fontVariant: ['tabular-nums'] },
});
