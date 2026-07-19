// Native map preview for tracking: pickup, drop-off, and the live rider position. Uses
// react-native-maps (Google Maps on Android, Apple Maps on iOS). Rendered lazily and guarded so a
// build without the native module (e.g. Expo Go before a dev build) simply omits the map rather
// than crashing — the surrounding screen still shows status/ETA text.
import React from 'react';
import { StyleSheet, View } from 'react-native';
import { colors, radius } from '@/theme/theme';

export type MapPoint = { lat: number; lng: number; label?: string; kind?: 'pickup' | 'dropoff' | 'rider' };

// Lazy require so the JS bundle/screens still load if the native module isn't present.
let MapView: any = null;
let Marker: any = null;
try {
  // eslint-disable-next-line @typescript-eslint/no-var-requires
  const maps = require('react-native-maps');
  MapView = maps.default;
  Marker = maps.Marker;
} catch {
  MapView = null;
}

const PIN_COLOR: Record<NonNullable<MapPoint['kind']>, string> = {
  pickup: colors.primary,
  dropoff: colors.success,
  rider: colors.accent,
};

export function MapPreview({ points, height = 220 }: { points: MapPoint[]; height?: number }) {
  const valid = points.filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lng));
  if (!MapView || valid.length === 0) {
    return null; // graceful: no map module or no coordinates -> render nothing
  }
  const lats = valid.map((p) => p.lat);
  const lngs = valid.map((p) => p.lng);
  const region = {
    latitude: (Math.min(...lats) + Math.max(...lats)) / 2,
    longitude: (Math.min(...lngs) + Math.max(...lngs)) / 2,
    latitudeDelta: Math.max(0.02, (Math.max(...lats) - Math.min(...lats)) * 1.6),
    longitudeDelta: Math.max(0.02, (Math.max(...lngs) - Math.min(...lngs)) * 1.6),
  };
  return (
    <View style={[styles.wrap, { height }]}>
      <MapView style={StyleSheet.absoluteFill} initialRegion={region} pointerEvents="none">
        {valid.map((p, i) => (
          <Marker
            key={`${p.kind ?? 'pt'}-${i}`}
            coordinate={{ latitude: p.lat, longitude: p.lng }}
            title={p.label}
            pinColor={PIN_COLOR[p.kind ?? 'pickup']}
          />
        ))}
      </MapView>
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { borderRadius: radius.lg, overflow: 'hidden', borderWidth: 1, borderColor: colors.border },
});
