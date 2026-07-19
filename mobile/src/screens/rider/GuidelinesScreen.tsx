// Rider guidelines / training. The web version (rider/training.php) is a static, informational page
// — there is no completion gate or DB flag — so this mirrors it as bundled content (works offline).
// The section topics match the web page.
import React from 'react';
import { ScrollView, StyleSheet, Text } from 'react-native';
import { Card } from '@/components';
import { colors, spacing, typography } from '@/theme/theme';

const SECTIONS: { title: string; points: string[] }[] = [
  { title: 'Professionalism', points: ['Be punctual and courteous with every sender and recipient.', 'Dress neatly and keep your vehicle clean.'] },
  { title: 'Communication', points: ['Confirm pickup and drop-off details before you set off.', 'Keep the sender updated and answer calls promptly.'] },
  { title: 'Handling packages', points: ['Treat every item with care — secure fragile parcels.', 'Never open or tamper with a package.'] },
  { title: 'Safety', points: ['Obey traffic laws and wear your helmet on a bike.', 'Do not use your phone while riding — pull over first.'] },
  { title: 'Ratings', points: ['Great service earns better ratings and more offers.', 'A high completion rate keeps you ranked higher for senders.'] },
  { title: 'Conduct', points: ['Payment is collected as agreed — never demand extra.', 'Report any problem through the app so support can help.'] },
];

export function GuidelinesScreen() {
  return (
    <ScrollView style={styles.screen} contentContainerStyle={styles.content}>
      <Text style={styles.title}>Rider guidelines</Text>
      <Text style={styles.soft}>A quick guide to delivering well on Aike.</Text>
      {SECTIONS.map((s) => (
        <Card key={s.title}>
          <Text style={styles.h2}>{s.title}</Text>
          {s.points.map((p, i) => (
            <Text key={i} style={styles.point}>• {p}</Text>
          ))}
        </Card>
      ))}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.bg },
  content: { padding: spacing.lg, gap: spacing.md },
  title: { ...typography.h1, color: colors.text },
  h2: { ...typography.h2, color: colors.text },
  soft: { ...typography.small, color: colors.textSoft },
  point: { ...typography.body, color: colors.text },
});
