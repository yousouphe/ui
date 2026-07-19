// Report a problem with a delivered booking. Wired to POST /complaints (the backend validates the
// category, checks the booking is delivered and owned by the caller, and notifies admins). Reached
// from the Track screen after delivery.
import React, { useState } from 'react';
import { ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { Button, Card, EmptyState } from '@/components';
import { senderApi } from '@/api/services';
import { ApiClientError } from '@/api/client';
import { colors, radius, spacing, typography } from '@/theme/theme';
import type { ComplaintCategory } from '@shared/contracts/api';

type Nav = { goBack: () => void };
type Route = { params: { bookingId: number } };

const CATEGORIES: { key: ComplaintCategory; label: string }[] = [
  { key: 'damaged_item', label: 'Item damaged' },
  { key: 'late_delivery', label: 'Late delivery' },
  { key: 'wrong_item', label: 'Wrong item' },
  { key: 'rider_behavior', label: 'Rider behaviour' },
  { key: 'other', label: 'Other' },
];

export function ComplaintScreen({ navigation, route }: { navigation: Nav; route: Route }) {
  const { bookingId } = route.params;
  const [category, setCategory] = useState<ComplaintCategory>('damaged_item');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  async function submit() {
    setBusy(true); setError(null);
    try {
      const res = await senderApi.complain(bookingId, category, message.trim());
      setDone(res.message);
    } catch (e) {
      setError(e instanceof ApiClientError ? e.message : 'Could not submit your report.');
    } finally {
      setBusy(false);
    }
  }

  if (done) {
    return (
      <View style={styles.screen}>
        <EmptyState title="Report submitted" subtitle={done} />
        <View style={styles.pad}><Button title="Done" onPress={() => navigation.goBack()} /></View>
      </View>
    );
  }

  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
      <Text style={styles.title}>Report a problem</Text>
      <Card>
        <Text style={styles.soft}>What went wrong?</Text>
        <View style={styles.chips}>
          {CATEGORIES.map((c) => (
            <TouchableOpacity key={c.key} onPress={() => setCategory(c.key)} style={[styles.chip, category === c.key && styles.chipActive]} accessibilityRole="button" accessibilityState={{ selected: category === c.key }}>
              <Text style={[styles.chipText, category === c.key && styles.chipTextActive]}>{c.label}</Text>
            </TouchableOpacity>
          ))}
        </View>
        <Text style={styles.soft}>Describe the issue</Text>
        <TextInput style={[styles.input, { minHeight: 110 }]} value={message} onChangeText={setMessage} multiline placeholder="Tell us what happened…" placeholderTextColor={colors.textSoft} />
        {error ? <Text style={styles.error}>{error}</Text> : null}
        <Button title="Submit report" onPress={submit} loading={busy} disabled={!message.trim() || busy} />
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
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: spacing.sm },
  chip: { paddingVertical: spacing.sm, paddingHorizontal: spacing.md, borderRadius: radius.pill, borderWidth: 1, borderColor: colors.border, backgroundColor: colors.surface },
  chipActive: { backgroundColor: colors.primary, borderColor: colors.primary },
  chipText: { ...typography.small, color: colors.textSoft, fontWeight: '700' },
  chipTextActive: { color: '#fff' },
  error: { color: colors.danger },
});
