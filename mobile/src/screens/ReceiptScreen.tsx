// Full payment receipt (financial module Phase 4). Mirrors the web payments/receipt.php: an
// immutable, professional receipt the customer or the assigned rider can view, share, or resend
// to the customer's email. Fetched from GET /bookings/{id}/receipt.
import React, { useCallback, useEffect, useState } from 'react';
import { ScrollView, Share, StyleSheet, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button, ErrorState, LoadingState } from '@/components';
import { financeApi } from '@/api/services';
import { ApiClientError } from '@/api/client';
import { colors, radius, spacing, typography } from '@/theme/theme';
import type { ReceiptDetail } from '@shared/contracts/api';

type Route = { params: { bookingId: number } };

const naira = (n: number) => '₦' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function ReceiptScreen({ route }: { route: Route }) {
  const { t } = useTranslation();
  const { bookingId } = route.params;
  const [receipt, setReceipt] = useState<ReceiptDetail | null>(null);
  const [state, setState] = useState<{ loading: boolean; error: string | null; busy: boolean; note: string | null }>({ loading: true, error: null, busy: false, note: null });

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true, error: null }));
    try {
      const res = await financeApi.receipt(bookingId);
      setReceipt(res.receipt);
      setState((s) => ({ ...s, loading: false }));
    } catch (e) {
      setState((s) => ({ ...s, loading: false, error: e instanceof ApiClientError ? e.message : t('receipt.load_error') }));
    }
  }, [bookingId, t]);

  useEffect(() => { load(); }, [load]);

  const onResend = useCallback(async () => {
    setState((s) => ({ ...s, busy: true, note: null }));
    try {
      const res = await financeApi.resendReceipt(bookingId);
      setState((s) => ({ ...s, busy: false, note: res.message }));
    } catch (e) {
      setState((s) => ({ ...s, busy: false, note: e instanceof ApiClientError ? e.message : t('receipt.resend_error') }));
    }
  }, [bookingId, t]);

  const onShare = useCallback(async () => {
    if (!receipt) return;
    const lines = [
      `Aike — ${t('receipt.title')}`,
      `${t('receipt.receipt_no')}: ${receipt.receiptNumber}`,
      `${t('receipt.order_no')}: ${receipt.orderCode}`,
      `${t('receipt.reference')}: ${receipt.reference}`,
      `${t('receipt.customer')}: ${receipt.customerName}`,
      receipt.riderName ? `${t('receipt.rider')}: ${receipt.riderName}` : '',
      `${t('receipt.total_paid')}: ${naira(receipt.totalAmount)}`,
      `${t('receipt.status_paid')}`,
    ].filter(Boolean);
    await Share.share({ message: lines.join('\n') });
  }, [receipt, t]);

  if (state.loading) return <LoadingState />;
  if (state.error || !receipt) return <ErrorState message={state.error ?? t('receipt.load_error')} onRetry={load} />;

  const Row = ({ k, v }: { k: string; v: string }) => (
    <View style={styles.row}><Text style={styles.k}>{k}</Text><Text style={styles.v}>{v}</Text></View>
  );

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content}>
      <View style={styles.card}>
        <View style={styles.head}>
          <Text style={styles.wordmark}>AIKE</Text>
          <View style={styles.badge}><Text style={styles.badgeText}>{t('receipt.status_paid')}</Text></View>
        </View>
        <Text style={styles.receiptNo}>{receipt.receiptNumber}</Text>
        <Text style={styles.date}>{new Date(receipt.createdAt).toLocaleString()}</Text>

        <View style={styles.section}>
          <Row k={t('receipt.order_no')} v={receipt.orderCode} />
          <Row k={t('receipt.reference')} v={receipt.reference} />
          <Row k={t('receipt.customer')} v={receipt.customerName} />
          {receipt.riderName ? <Row k={t('receipt.rider')} v={receipt.riderName} /> : null}
          <Row k={t('receipt.pickup')} v={receipt.pickupAddress} />
          <Row k={t('receipt.delivery')} v={receipt.deliveryAddress} />
          <Row k={t('receipt.method')} v={receipt.paymentMethod} />
        </View>

        <View style={styles.section}>
          <Row k={t('receipt.amount_net')} v={naira(receipt.amount)} />
          {receipt.vatAmount > 0 ? <Row k={`${t('receipt.vat')} (${receipt.vatPercent}%)`} v={naira(receipt.vatAmount)} /> : null}
          <View style={[styles.row, styles.totalRow]}><Text style={styles.totalK}>{t('receipt.total_paid')}</Text><Text style={styles.totalV}>{naira(receipt.totalAmount)}</Text></View>
        </View>
      </View>

      {state.note ? <Text style={styles.note}>{state.note}</Text> : null}
      <Button title={t('receipt.share')} onPress={onShare} style={{ marginTop: spacing.md }} />
      <Button title={t('receipt.resend')} variant="secondary" onPress={onResend} loading={state.busy} style={{ marginTop: spacing.sm }} />
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: spacing.lg },
  card: { backgroundColor: colors.surface, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.border, padding: spacing.lg },
  head: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  wordmark: { ...typography.wordmark, fontSize: 26, color: colors.primary, letterSpacing: 2 },
  badge: { backgroundColor: 'rgba(22,101,52,0.12)', borderRadius: radius.pill, paddingVertical: 4, paddingHorizontal: 12 },
  badgeText: { color: '#166534', fontWeight: '700', fontSize: 12 },
  receiptNo: { ...typography.h2, color: colors.text, marginTop: spacing.md },
  date: { ...typography.small, color: colors.textSoft },
  section: { marginTop: spacing.lg, gap: spacing.xs },
  row: { flexDirection: 'row', justifyContent: 'space-between', gap: spacing.md, paddingVertical: 6, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  k: { ...typography.small, color: colors.textSoft, flexShrink: 0 },
  v: { ...typography.body, color: colors.text, fontWeight: '600', textAlign: 'right', flexShrink: 1 },
  totalRow: { borderBottomWidth: 0, marginTop: spacing.xs },
  totalK: { ...typography.h2, color: colors.text },
  totalV: { ...typography.h2, color: colors.text },
  note: { ...typography.small, color: colors.success, marginTop: spacing.md, textAlign: 'center' },
});
