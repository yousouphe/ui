// The rider's withdrawal history with live status (GET /rider/withdrawals). Transfer status is
// webhook-driven on the backend, so this reflects the latest state each time it loads.
import React, { useCallback, useEffect, useState } from 'react';
import { RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { Card, EmptyState, ErrorState, LoadingState, MoneyText } from '@/components';
import { riderApi } from '@/api/services';
import { colors, radius, spacing, typography } from '@/theme/theme';
import type { WithdrawalItem, WithdrawalStatus } from '@shared/contracts/api';

const STATUS_COLOR: Record<WithdrawalStatus, string> = {
  pending: colors.warning,
  processing: colors.primary,
  paid: colors.success,
  rejected: colors.danger,
};

export function WithdrawalsScreen() {
  const [state, setState] = useState<{ loading: boolean; error: string | null; items: WithdrawalItem[] }>({ loading: true, error: null, items: [] });

  const load = useCallback(async () => {
    try {
      const res = await riderApi.withdrawals();
      setState({ loading: false, error: null, items: res.withdrawals });
    } catch {
      setState((s) => ({ ...s, loading: false, error: 'Could not load your withdrawals.' }));
    }
  }, []);
  useEffect(() => { load(); }, [load]);

  if (state.loading) return <LoadingState />;
  if (state.error) return <ErrorState message={state.error} onRetry={load} />;
  if (state.items.length === 0) {
    return <EmptyState title="No withdrawals yet" subtitle="Your payout requests and their status will appear here." />;
  }

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content} refreshControl={<RefreshControl refreshing={false} onRefresh={load} />}>
      <Text style={styles.title}>Withdrawals</Text>
      {state.items.map((w, i) => (
        <Card key={`${w.requestedAt}-${i}`}>
          <View style={styles.row}>
            <MoneyText amount={w.amount} />
            <View style={[styles.badge, { backgroundColor: STATUS_COLOR[w.status] }]}>
              <Text style={styles.badgeText}>{w.status}</Text>
            </View>
          </View>
          <Text style={styles.soft}>{w.bankName} · {w.accountNumberMasked}</Text>
          <Text style={styles.soft}>Requested {new Date(w.requestedAt).toLocaleDateString()}{w.processedAt ? ` · processed ${new Date(w.processedAt).toLocaleDateString()}` : ''}</Text>
          {w.note ? <Text style={styles.note}>{w.note}</Text> : null}
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
  note: { ...typography.small, color: colors.text },
  row: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  badge: { borderRadius: radius.pill, paddingHorizontal: spacing.md, paddingVertical: 4 },
  badgeText: { color: '#fff', fontSize: 12, fontWeight: '700', textTransform: 'capitalize' },
});
