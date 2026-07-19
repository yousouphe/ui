// In-booking chat between the sender and the assigned rider. Polls GET /bookings/{id}/messages
// with the last id (so it only pulls new messages), and marking-as-read happens server-side on
// fetch — so a message I sent flips from one tick (delivered) to two ticks (read) once the other
// side polls. Works for both roles; the backend derives the receiver.
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { FlatList, KeyboardAvoidingView, Platform, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { ErrorState, LoadingState } from '@/components';
import { chatApi } from '@/api/services';
import { ApiClientError } from '@/api/client';
import { colors, radius, spacing, typography } from '@/theme/theme';
import type { ChatMessage } from '@shared/contracts/api';

type Route = { params: { bookingId: number; title?: string } };

function Ticks({ m }: { m: ChatMessage }) {
  if (!m.mine) return null;
  // One tick = delivered/sent; two ticks = read.
  const read = m.readAt != null;
  return <Text style={[styles.ticks, read && styles.ticksRead]}>{read ? '✓✓' : '✓'}</Text>;
}

export function ChatScreen({ route }: { route: Route }) {
  const { bookingId } = route.params;
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const lastId = useRef(0);
  const listRef = useRef<FlatList<ChatMessage>>(null);

  const poll = useCallback(async () => {
    try {
      const res = await chatApi.messages(bookingId, lastId.current);
      if (res.messages.length > 0) {
        // Merge: new messages appended; existing ones (read-state changes) refreshed on a full reload.
        if (lastId.current === 0) {
          setMessages(res.messages);
        } else {
          setMessages((prev) => [...prev, ...res.messages.filter((m) => !prev.some((p) => p.id === m.id))]);
        }
        lastId.current = res.lastId;
      }
      setError(null);
    } catch (e) {
      if (lastId.current === 0) setError(e instanceof ApiClientError ? e.message : 'Could not load the chat.');
    } finally {
      setLoading(false);
    }
  }, [bookingId]);

  // Full reload (id reset) picks up read-state changes on my earlier messages.
  const reload = useCallback(async () => {
    lastId.current = 0;
    try {
      const res = await chatApi.messages(bookingId, 0);
      setMessages(res.messages);
      lastId.current = res.lastId;
      setError(null);
    } catch { /* keep what we have */ }
  }, [bookingId]);

  useEffect(() => {
    poll();
    const t = setInterval(reload, 5000); // reload so ticks update; cheap for a 2-person thread
    return () => clearInterval(t);
  }, [poll, reload]);

  useEffect(() => {
    if (messages.length > 0) setTimeout(() => listRef.current?.scrollToEnd({ animated: true }), 50);
  }, [messages.length]);

  async function send() {
    const text = draft.trim();
    if (!text || sending) return;
    setSending(true);
    setDraft('');
    try {
      const res = await chatApi.send(bookingId, text);
      setMessages((prev) => [...prev, res.message]);
      lastId.current = Math.max(lastId.current, res.message.id);
    } catch (e) {
      setDraft(text); // restore on failure
      setError(e instanceof ApiClientError ? e.message : 'Message not sent.');
    } finally {
      setSending(false);
    }
  }

  if (loading) return <LoadingState />;
  if (error && messages.length === 0) return <ErrorState message={error} onRetry={poll} />;

  return (
    <KeyboardAvoidingView style={styles.screen} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={90}>
      <FlatList
        ref={listRef}
        data={messages}
        keyExtractor={(m) => String(m.id)}
        contentContainerStyle={styles.list}
        renderItem={({ item }) => (
          <View style={[styles.bubbleRow, item.mine ? styles.rowMine : styles.rowTheirs]}>
            <View style={[styles.bubble, item.mine ? styles.mine : styles.theirs]}>
              <Text style={[styles.msg, item.mine && styles.msgMine]}>{item.message}</Text>
              <View style={styles.meta}>
                <Text style={[styles.time, item.mine && styles.timeMine]}>{item.createdAt ? new Date(item.createdAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}</Text>
                <Ticks m={item} />
              </View>
            </View>
          </View>
        )}
        ListEmptyComponent={<Text style={styles.empty}>Say hello — messages are shared with the other party on this delivery.</Text>}
      />
      <View style={styles.composer}>
        <TextInput style={styles.input} value={draft} onChangeText={setDraft} placeholder="Message…" placeholderTextColor={colors.textSoft} multiline />
        <TouchableOpacity style={[styles.sendBtn, (!draft.trim() || sending) && styles.sendDisabled]} onPress={send} disabled={!draft.trim() || sending} accessibilityRole="button" accessibilityLabel="Send">
          <Text style={styles.sendText}>Send</Text>
        </TouchableOpacity>
      </View>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  list: { padding: spacing.md, gap: spacing.sm, flexGrow: 1 },
  bubbleRow: { flexDirection: 'row' },
  rowMine: { justifyContent: 'flex-end' },
  rowTheirs: { justifyContent: 'flex-start' },
  bubble: { maxWidth: '80%', borderRadius: radius.lg, paddingHorizontal: spacing.md, paddingVertical: spacing.sm },
  mine: { backgroundColor: colors.primary, borderTopRightRadius: 4 },
  theirs: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderTopLeftRadius: 4 },
  msg: { ...typography.body, color: colors.text },
  msgMine: { color: '#fff' },
  meta: { flexDirection: 'row', alignItems: 'center', justifyContent: 'flex-end', gap: spacing.xs, marginTop: 2 },
  time: { fontSize: 10, color: colors.textSoft },
  timeMine: { color: 'rgba(255,255,255,0.8)' },
  ticks: { fontSize: 11, color: 'rgba(255,255,255,0.7)' },
  ticksRead: { color: '#8fe3ff' },
  empty: { ...typography.small, color: colors.textSoft, textAlign: 'center', marginTop: spacing.xl, paddingHorizontal: spacing.xl },
  composer: { flexDirection: 'row', alignItems: 'flex-end', gap: spacing.sm, padding: spacing.md, borderTopWidth: 1, borderTopColor: colors.border, backgroundColor: colors.surface },
  input: { flex: 1, maxHeight: 120, backgroundColor: colors.bg, borderWidth: 1, borderColor: colors.border, borderRadius: radius.lg, paddingHorizontal: spacing.md, paddingVertical: spacing.sm, fontSize: 16, color: colors.text },
  sendBtn: { backgroundColor: colors.primary, borderRadius: radius.lg, paddingHorizontal: spacing.lg, paddingVertical: spacing.md, justifyContent: 'center' },
  sendDisabled: { opacity: 0.5 },
  sendText: { color: '#fff', fontWeight: '700' },
});
