// Search-as-you-type address picker (Nigeria-restricted, debounced). Address search is the
// primary location method per the brief; current-location and map-pin come as enhancements in
// Phase 7. Minimises API calls with a debounce + abort of in-flight requests.
import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { geocode, type Place } from '@/api/geo';
import { colors, radius, spacing, typography } from '@/theme/theme';

export function AddressSearch({
  label,
  value,
  onSelect,
}: {
  label: string;
  value: Place | null;
  onSelect: (place: Place) => void;
}) {
  const [query, setQuery] = useState(value?.address ?? '');
  const [results, setResults] = useState<Place[]>([]);
  const [loading, setLoading] = useState(false);
  const [focused, setFocused] = useState(false);
  const abortRef = useRef<AbortController | null>(null);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (!focused) return;
    if (timerRef.current) clearTimeout(timerRef.current);
    if (query.trim().length < 3) {
      setResults([]);
      return;
    }
    timerRef.current = setTimeout(async () => {
      abortRef.current?.abort();
      const controller = new AbortController();
      abortRef.current = controller;
      setLoading(true);
      try {
        setResults(await geocode(query, controller.signal));
      } catch {
        setResults([]);
      } finally {
        setLoading(false);
      }
    }, 350);
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, [query, focused]);

  return (
    <View style={styles.wrap}>
      <Text style={styles.label}>{label}</Text>
      <View style={styles.inputRow}>
        <TextInput
          style={styles.input}
          value={query}
          onChangeText={setQuery}
          onFocus={() => setFocused(true)}
          placeholder="Search a street, estate, landmark…"
          placeholderTextColor={colors.textSoft}
          accessibilityLabel={label}
        />
        {loading ? <ActivityIndicator color={colors.primary} style={styles.spinner} /> : null}
      </View>
      {focused && results.length > 0 ? (
        <View style={styles.dropdown}>
          {results.map((r, i) => (
            <Pressable
              key={`${r.lat},${r.lng},${i}`}
              style={styles.item}
              accessibilityRole="button"
              onPress={() => {
                onSelect(r);
                setQuery(r.address);
                setResults([]);
                setFocused(false);
              }}
            >
              <Text style={styles.itemName}>{r.name}</Text>
              <Text style={styles.itemAddr} numberOfLines={1}>{r.address}</Text>
            </Pressable>
          ))}
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { gap: spacing.xs },
  label: { ...typography.small, color: colors.textSoft },
  inputRow: { flexDirection: 'row', alignItems: 'center' },
  input: {
    flex: 1, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border,
    borderRadius: radius.md, padding: spacing.md, minHeight: 48, fontSize: 16, color: colors.text,
  },
  spinner: { marginLeft: -32 },
  dropdown: {
    backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border, borderRadius: radius.md,
    overflow: 'hidden', marginTop: spacing.xs,
  },
  item: { padding: spacing.md, borderBottomWidth: 1, borderBottomColor: colors.border },
  itemName: { ...typography.body, color: colors.text, fontWeight: '600' },
  itemAddr: { ...typography.small, color: colors.textSoft },
});
