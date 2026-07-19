// Core design-system components used across screens: status badges, cards, money text, and the
// three standard async states (skeleton, empty, error). Kept restrained and dependency-light.
import React from 'react';
import { ActivityIndicator, StyleSheet, Text, View, ViewStyle } from 'react-native';
import { colors, radius, spacing, typography } from '@/theme/theme';
import { BOOKING_STATUS_COLOR, type BookingStatus } from '@shared/constants/statuses';

export { Button } from './Button';

const STATUS_LABEL: Record<string, string> = {
  draft: 'Draft', submitted: 'Requested', matched: 'Rider selected', accepted: 'Accepted',
  arrived_at_pickup: 'At pickup', package_received: 'Picked up', in_transit: 'In transit',
  delivered: 'Delivered', cancelled: 'Cancelled',
};

export function StatusBadge({ status }: { status: BookingStatus | string }) {
  const bg = BOOKING_STATUS_COLOR[status as BookingStatus] ?? colors.textSoft;
  return (
    <View style={[styles.badge, { backgroundColor: bg }]} accessibilityLabel={`Status: ${STATUS_LABEL[status] ?? status}`}>
      <Text style={styles.badgeText}>{STATUS_LABEL[status] ?? status}</Text>
    </View>
  );
}

export function Card({ children, style }: { children: React.ReactNode; style?: ViewStyle }) {
  return <View style={[styles.card, style]}>{children}</View>;
}

export function MoneyText({ amount, style }: { amount: number | null; style?: object }) {
  return <Text style={[styles.money, style]}>{amount === null ? '—' : `₦${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`}</Text>;
}

export function Skeleton({ height = 64 }: { height?: number }) {
  return <View style={[styles.skeleton, { height }]} accessibilityLabel="Loading" />;
}

export function LoadingState() {
  return (
    <View style={styles.center}>
      <ActivityIndicator size="large" color={colors.primary} />
    </View>
  );
}

export function EmptyState({ title, subtitle }: { title: string; subtitle?: string }) {
  return (
    <View style={styles.center}>
      <Text style={styles.emptyTitle}>{title}</Text>
      {subtitle ? <Text style={styles.emptySub}>{subtitle}</Text> : null}
    </View>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <View style={styles.center} accessibilityRole="alert">
      <Text style={styles.errorText}>{message}</Text>
      {onRetry ? <Text style={styles.retryLink} onPress={onRetry} accessibilityRole="button">Try again</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  badge: { alignSelf: 'flex-start', borderRadius: radius.pill, paddingHorizontal: spacing.md, paddingVertical: 4 },
  badgeText: { color: '#fff', fontSize: 12, fontWeight: '700' },
  card: {
    backgroundColor: colors.surface, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.border,
    padding: spacing.lg, gap: spacing.sm,
  },
  money: { ...typography.h2, color: colors.text },
  skeleton: { backgroundColor: 'rgba(15,42,68,0.08)', borderRadius: radius.md, marginBottom: spacing.md },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.xl, gap: spacing.sm },
  emptyTitle: { ...typography.h2, color: colors.text },
  emptySub: { ...typography.small, color: colors.textSoft, textAlign: 'center' },
  errorText: { ...typography.body, color: colors.warning, textAlign: 'center' },
  retryLink: { ...typography.body, color: colors.primary, fontWeight: '700', marginTop: spacing.sm },
});
