// Rider background location. Runs ONLY while the rider is online or on an active delivery, at a
// battery-efficient interval, and stops as soon as it's no longer needed — per the brief and the
// Android/iOS background-location rules. Senders never run background location. Uses a debounced
// server push (the backend also dedups ≥55 m / ≥15 s).
//
// Requires expo-location + expo-task-manager (declared in package.json / app.json). Guarded so a
// denied permission degrades gracefully instead of crashing.
import * as Location from 'expo-location';
import * as TaskManager from 'expo-task-manager';
import { riderApi } from '@/api/services';

const TASK = 'aike-rider-location';

// Background task: push the latest fix to the backend (only meaningful while online/active).
if (!TaskManager.isTaskDefined(TASK)) {
  TaskManager.defineTask(TASK, async ({ data, error }: { data?: { locations?: Location.LocationObject[] }; error?: unknown }) => {
    if (error || !data?.locations?.length) return;
    const last = data.locations[data.locations.length - 1];
    try {
      await riderApi.pushLocation(last.coords.latitude, last.coords.longitude, 'available');
    } catch {
      // Offline / transient — the next tick (or the app's own refresh) will catch up.
    }
  });
}

export async function startRiderLocation(): Promise<boolean> {
  const fg = await Location.requestForegroundPermissionsAsync();
  if (fg.status !== 'granted') return false;
  // Background permission is requested only for riders, only when going online.
  const bg = await Location.requestBackgroundPermissionsAsync();
  const already = await Location.hasStartedLocationUpdatesAsync(TASK).catch(() => false);
  if (already) return true;
  await Location.startLocationUpdatesAsync(TASK, {
    accuracy: Location.Accuracy.Balanced,   // not High — battery-efficient
    timeInterval: 20000,                     // ~20s
    distanceInterval: 60,                    // or ~60m moved
    foregroundService: {
      notificationTitle: 'Aike is sharing your location',
      notificationBody: 'Only while you are online or on a delivery.',
    },
    showsBackgroundLocationIndicator: bg.status === 'granted',
    pausesUpdatesAutomatically: true,
  });
  return true;
}

export async function stopRiderLocation(): Promise<void> {
  const running = await Location.hasStartedLocationUpdatesAsync(TASK).catch(() => false);
  if (running) {
    await Location.stopLocationUpdatesAsync(TASK).catch(() => undefined);
  }
}
