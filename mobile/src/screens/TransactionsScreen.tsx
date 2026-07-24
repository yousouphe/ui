// Transaction history for a sender or rider (financial module Phase 4). Mirrors the web
// transactions.php: one normalised list with search, period/type/status filters, sort, a
// running summary (credits/debits, and closing balance for riders), and paged loading.
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { FlatList, RefreshControl, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Card, EmptyState, ErrorState, LoadingState, MoneyText } from '@/components';
import { financeApi } from '@/api/services';
import { useAuth } from '@/auth/AuthContext';
import { colors, radius, spacing, typography } from '@/theme/theme';
import type { TransactionFilters, TransactionItem, TransactionSummary } from '@shared/contracts/api';

const RANGES = ['all', 'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month'] as const;
const SORTS = ['newest', 'oldest', 'highest', 'lowest'] as const;
const STATUSES = ['', 'successful', 'pending', 'failed', 'refunded'] as const;

function Chip({ label, active, onPress }: { label: string; active: boolean; onPress: () => void }) {
  return (
    <TouchableOpacity onPress={onPress} style={[styles.chip, active && styles.chipActive]} accessibilityRole="button">
      <Text style={[styles.chipText, active && styles.chipTextActive]}>{label}</Text>
    </TouchableOpacity>
  );
}

export function TransactionsScreen() {
  const { t } = useTranslation();
  const { user } = useAuth();
  const isRider = user?.role === 'rider';
  const typeOptions = useMemo(
    () => (isRider ? ['', 'ride_payment', 'withdrawal'] : ['', 'ride_payment', 'refund']),
    [isRider],
  );

  const [filters, setFilters] = useState<TransactionFilters>({ range: 'all', sort: 'newest' });
  const [q, setQ] = useState('');
  const [items, setItems] = useState<TransactionItem[]>([]);
  const [summary, setSummary] = useState<TransactionSummary | null>(null);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [state, setState] = useState<{ loading: boolean; error: string | null }>({ loading: true, error: null });

  const load = useCallback(async (nextPage = 1, append = false) => {
    if (!append) setState({ loading: true, error: null });
    try {
      const res = await financeApi.transactions({ ...filters, q: q.trim(), page: nextPage });
      setItems((prev) => (append ? [...prev, ...res.transactions] : res.transactions));
      setSummary(res.summary);
      setPage(res.page);
      setPages(res.pages);
      setState({ loading: false, error: null });
    } catch {
      setState({ loading: false, error: t('tx.load_error') });
    }
  }, [filters, q, t]);

  useEffect(() => { load(1, false); /* reload when filters change */ }, [filters]); // eslint-disable-line react-hooks/exhaustive-deps

  if (state.loading && items.length === 0) return <LoadingState />;
  if (state.error && items.length === 0) return <ErrorState message={state.error} onRetry={() => load(1, false)} />;

  return (
    <FlatList
      style={styles.screen}
      data={items}
      keyExtractor={(it) => it.id}
      refreshControl={<RefreshControl refreshing={false} onRefresh={() => load(1, false)} />}
      onEndReachedThreshold={0.4}
      onEndReached={() => { if (page < pages && !state.loading) load(page + 1, true); }}
      ListHeaderComponent={
        <View style={styles.header}>
          <Text style={styles.title}>{t('tx.title')}</Text>

          {summary ? (
            <View style={styles.summaryRow}>
              {summary.hasBalance ? (
                <View style={styles.stat}><Text style={styles.statLabel}>{t('tx.closing')}</Text><MoneyText amount={summary.closing} style={styles.statValue} /></View>
              ) : null}
              <View style={styles.stat}><Text style={styles.statLabel}>{t('tx.total_credits')}</Text><Text style={[styles.statValue, { color: colors.success }]}>+<MoneyDisplay n={summary.credits} /></Text></View>
              <View style={styles.stat}><Text style={styles.statLabel}>{t('tx.total_debits')}</Text><Text style={[styles.statValue, { color: colors.danger }]}>-<MoneyDisplay n={summary.debits} /></Text></View>
            </View>
          ) : null}

          <TextInput
            style={styles.search}
            value={q}
            onChangeText={setQ}
            onSubmitEditing={() => load(1, false)}
            returnKeyType="search"
            placeholder={t('tx.search_placeholder')}
            placeholderTextColor={colors.textSoft}
            accessibilityLabel={t('tx.search')}
          />

          <FilterRow label={t('tx.period')}>
            {RANGES.map((r) => <Chip key={r} label={t(`tx.range.${r}`)} active={(filters.range ?? 'all') === r} onPress={() => setFilters((f) => ({ ...f, range: r }))} />)}
          </FilterRow>
          <FilterRow label={t('tx.type')}>
            {typeOptions.map((ty) => <Chip key={ty || 'all'} label={t(`tx.type.${ty === '' ? 'all' : ty === 'ride_payment' ? 'ride_payments' : ty === 'withdrawal' ? 'withdrawals' : 'refunded'}`)} active={(filters.type ?? '') === ty} onPress={() => setFilters((f) => ({ ...f, type: ty }))} />)}
          </FilterRow>
          <FilterRow label={t('tx.status')}>
            {STATUSES.map((s) => <Chip key={s || 'all'} label={s === '' ? t('tx.status.all') : t(`tx.status.${s}`)} active={(filters.status ?? '') === s} onPress={() => setFilters((f) => ({ ...f, status: s }))} />)}
          </FilterRow>
          <FilterRow label={t('tx.sort')}>
            {SORTS.map((s) => <Chip key={s} label={t(`tx.sort.${s}`)} active={(filters.sort ?? 'newest') === s} onPress={() => setFilters((f) => ({ ...f, sort: s }))} />)}
          </FilterRow>
        </View>
      }
      renderItem={({ item }) => {
        const credit = item.amount >= 0;
        const catKey = item.category === 'ride_payment' ? 'ride_payments' : item.category === 'withdrawal' ? 'withdrawals' : 'refunded';
        return (
          <Card style={styles.card}>
            <View style={styles.cardRow}>
              <View style={styles.cardLeft}>
                <Text style={styles.cardDesc}>{item.description}</Text>
                <Text style={styles.cardMeta}>{t(`tx.type.${catKey}`)}{item.orderCode ? ` · ${item.orderCode}` : ''}</Text>
                <Text style={styles.cardMeta}>{new Date(item.date).toLocaleString()}{item.reference ? ` · ${item.reference}` : ''}</Text>
              </View>
              <View style={styles.cardRight}>
                <Text style={[styles.amount, { color: credit ? colors.success : colors.danger }]}>{credit ? '+' : '-'}<MoneyDisplay n={Math.abs(item.amount)} /></Text>
                <Text style={styles.status}>{t(`tx.status.${item.status}`, item.status)}</Text>
              </View>
            </View>
          </Card>
        );
      }}
      ListEmptyComponent={<EmptyState title={t('tx.none')} />}
      contentContainerStyle={items.length === 0 ? styles.emptyContainer : styles.listContent}
    />
  );
}

// A plain money string (no wrapping <Text>) for use inside styled Text rows.
function MoneyDisplay({ n }: { n: number }) {
  return <>{'₦' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</>;
}

function FilterRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <View style={styles.filterRow}>
      <Text style={styles.filterLabel}>{label}</Text>
      <View style={styles.chips}>{children}</View>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  header: { padding: spacing.lg, gap: spacing.sm },
  title: { ...typography.h1, color: colors.text },
  summaryRow: { flexDirection: 'row', gap: spacing.sm, flexWrap: 'wrap' },
  stat: { flexGrow: 1, backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.border, padding: spacing.md, minWidth: 100 },
  statLabel: { ...typography.small, color: colors.textSoft },
  statValue: { ...typography.h2, color: colors.text },
  search: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md, padding: spacing.md, minHeight: 44, color: colors.text, marginTop: spacing.xs },
  filterRow: { gap: spacing.xs },
  filterLabel: { ...typography.small, color: colors.textSoft },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.xs },
  chip: { paddingVertical: 6, paddingHorizontal: 12, borderRadius: radius.pill, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
  chipActive: { backgroundColor: colors.primary, borderColor: colors.primary },
  chipText: { ...typography.small, color: colors.textSoft },
  chipTextActive: { color: '#fff', fontWeight: '700' },
  listContent: { paddingHorizontal: spacing.lg, paddingBottom: spacing.xl, gap: spacing.sm },
  emptyContainer: { flexGrow: 1 },
  card: { marginBottom: spacing.sm },
  cardRow: { flexDirection: 'row', justifyContent: 'space-between', gap: spacing.md },
  cardLeft: { flexShrink: 1, gap: 2 },
  cardRight: { alignItems: 'flex-end', gap: 2 },
  cardDesc: { ...typography.body, color: colors.text, fontWeight: '600' },
  cardMeta: { ...typography.small, color: colors.textSoft },
  amount: { ...typography.h2 },
  status: { ...typography.small, color: colors.textSoft, textTransform: 'capitalize' },
});
