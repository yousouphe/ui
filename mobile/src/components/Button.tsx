import React from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, ViewStyle } from 'react-native';
import { colors, radius, spacing, MIN_TOUCH } from '@/theme/theme';

type Variant = 'primary' | 'secondary' | 'danger';

export function Button({
  title,
  onPress,
  variant = 'primary',
  loading = false,
  disabled = false,
  style,
}: {
  title: string;
  onPress: () => void;
  variant?: Variant;
  loading?: boolean;
  disabled?: boolean;
  style?: ViewStyle;
}) {
  const isDisabled = disabled || loading;
  const bg = variant === 'danger' ? colors.danger : variant === 'secondary' ? colors.surface : colors.primary;
  const fg = variant === 'secondary' ? colors.primary : '#fff';
  return (
    <Pressable
      onPress={onPress}
      disabled={isDisabled}
      accessibilityRole="button"
      accessibilityState={{ disabled: isDisabled, busy: loading }}
      style={({ pressed }) => [
        styles.base,
        { backgroundColor: bg, opacity: isDisabled ? 0.6 : pressed ? 0.9 : 1 },
        variant === 'secondary' && styles.secondaryBorder,
        style,
      ]}
    >
      {loading ? <ActivityIndicator color={fg} /> : <Text style={[styles.label, { color: fg }]}>{title}</Text>}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  base: {
    minHeight: MIN_TOUCH,
    borderRadius: radius.md,
    paddingHorizontal: spacing.xl,
    paddingVertical: spacing.md,
    alignItems: 'center',
    justifyContent: 'center',
  },
  secondaryBorder: { borderWidth: 1, borderColor: colors.border },
  label: { fontWeight: '700', fontSize: 16 },
});
