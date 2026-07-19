// Mobile push (FCM on Android / APNs on iOS, via Expo). On sign-in we ask permission, get the
// Expo push token, and register it with the backend (POST /notifications/device). The backend's
// unified dispatch (config/push.php) then delivers every notification event to the device. Tapping
// a notification deep-links to the screen carried in its `data.url`.
//
// Guarded throughout: denied permission or an emulator without push support degrades to no push
// (the in-app notifications list still works via GET /notifications).
import { Platform } from 'react-native';
import * as Notifications from 'expo-notifications';
import { notificationsApi } from '@/api/services';

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowAlert: true,
    shouldPlaySound: true,
    shouldSetBadge: false,
  }),
});

export async function registerForPush(): Promise<void> {
  try {
    const settings = await Notifications.getPermissionsAsync();
    let status = settings.status;
    if (status !== 'granted') {
      status = (await Notifications.requestPermissionsAsync()).status;
    }
    if (status !== 'granted') return;

    if (Platform.OS === 'android') {
      await Notifications.setNotificationChannelAsync('default', {
        name: 'Aike',
        importance: Notifications.AndroidImportance.DEFAULT,
      });
    }
    const tokenData = await Notifications.getExpoPushTokenAsync();
    const token = tokenData.data;
    if (token) {
      await notificationsApi.registerDevice(Platform.OS === 'ios' ? 'ios' : 'android', token);
    }
  } catch {
    // No push on this device/build — the app still works; in-app alerts cover the gap.
  }
}

// Wire notification taps to navigation. `navigate` receives the screen the url implies.
export function attachNotificationTapHandler(onOpen: (url: string | null) => void): () => void {
  const sub = Notifications.addNotificationResponseReceivedListener((response) => {
    const url = (response.notification.request.content.data as { url?: string } | undefined)?.url ?? null;
    onOpen(url);
  });
  return () => sub.remove();
}
