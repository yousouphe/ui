// In-app notification history. Wired to GET /notifications (paginated via meta.cursor) and
// POST /notifications/{id}/read. Push delivery is separate (services/push.ts); this is the durable
// list, so it works even when a push was missed or permission was denied. Tapping an unread item
// marks it read. (Deep-linking from a notification's web `url` to the matching native screen is a
// later enhancement — the payload carries a web path, not a native route.)
import React, { useCallback, useEffect, useState } from 'react';
import { FlatList, RefreshControl, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { EmptyState, ErrorState, LoadingState } from '@/components';
import { notificationsApi } from '@/api/services';
import { colors, radius, spacing, typography } from '@/theme/theme';
import type { NotificationItem } from '@shared/contracts/api';

function timeAgo(iso: string): string {
  const then = Date.parse(iso);
  if (Number.isNaN(then)) return '';
  const s = Math.max(0, Math.floor((Date.now() - then) / 1000));
  if (s < 60) return 'just now';
  const m = Math.floor(s / 60);
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h ago`;
  return `${Math.floor(h / 24)}d ago`;
}

export function NotificationsScreen() {
  const [state, setState] = useState<{ loading: boolean; error: string | null; items: NotificationItem[] }>({ loading: true, error: null, items: [] });

  const load = useCallback(async () => {
    try {
      const res = await notificationsApi.list();
      setState({ loading: false, error: null, items: res.notifications });
    } catch {
      setState((s) => ({ ...s, loading: false, error: 'Could not load your alerts.' }));
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  async function open(item: NotificationItem) {
    if (item.read) return;
    // Optimistic: flip locally, then persist. A failure just leaves it to the next refresh.
    setState((s) => ({ ...s, items: s.items.map((n) => (n.id === item.id ? { ...n, read: true } : n)) }));
    try { await notificationsApi.markRead(item.id); } catch { /* best-effort */ }
  }

  if (state.loading) return <LoadingState />;
  if (state.error) return <ErrorState message={state.error} onRetry={load} />;
  if (state.items.length === 0) {
    return <EmptyState title="You're all caught up" subtitle="Delivery and payment updates will appear here." />;
  }

  return (
    <FlatList
      style={styles.screen}
      contentContainerStyle={styles.content}
      data={state.items}
      keyExtractor={(n) => String(n.id)}
      refreshControl={<RefreshControl refreshing={false} onRefresh={load} />}
      renderItem={({ item }) => (
        <TouchableOpacity onPress={() => open(item)} accessibilityRole="button">
          <View style={[styles.card, !item.read && styles.unread]}>
            <View style={styles.rowTop}>
              <Text style={styles.title} numberOfLines={1}>{item.title}</Text>
              {!item.read ? <View style={styles.dot} /> : null}
            </View>
            <Text style={styles.body}>{item.body}</Text>
            <Text style={styles.time}>{timeAgo(item.createdAt)}</Text>
          </View>
        </TouchableOpacity>
      )}
    />
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: spacing.lg, gap: spacing.md },
  card: { backgroundColor: colors.surface, borderRadius: radius.lg, borderWidth: 1, borderColor: colors.border, padding: spacing.lg, gap: spacing.xs },
  unread: { borderColor: colors.primary },
  rowTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: spacing.sm },
  title: { ...typography.h2, color: colors.text, flexShrink: 1 },
  dot: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.primary },
  body: { ...typography.body, color: colors.text },
  time: { ...typography.small, color: colors.textSoft },
});
