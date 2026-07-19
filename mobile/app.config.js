// Dynamic Expo config (Phase 10). Expo loads this in preference to app.json and passes the static
// app.json content in as `config`, so this file is a thin RELEASE layer on top of that base:
//   - injects NON-SECRET build-time env into `extra` (so src/config.ts can read it on device),
//   - wires EAS Update (OTA) with an appVersion-scoped runtime version,
//   - adds the notification + image-picker (KYC) plugins and the Android permissions they need.
// No secrets belong here — secret Mapbox/Paystack/webhook keys stay in the backend only.
const APP_VERSION = '1.0.0';

module.exports = ({ config }) => {
  const projectId = process.env.AIKE_EAS_PROJECT_ID || config.extra?.eas?.projectId || '';

  return {
    ...config,
    version: APP_VERSION,
    // OTA updates only apply to a build with the same appVersion — a native change (new deps,
    // permissions) ships as a new store build, JS-only fixes ship as an `eas update`.
    runtimeVersion: { policy: 'appVersion' },
    updates: {
      ...(config.updates || {}),
      ...(projectId ? { url: `https://u.expo.dev/${projectId}` } : {}),
      fallbackToCacheTimeout: 0,
    },
    android: {
      ...config.android,
      permissions: [
        ...(config.android?.permissions || []),
        // Android 14 split the background-location foreground service into its own permission;
        // CAMERA is for the KYC document capture (expo-image-picker).
        'FOREGROUND_SERVICE',
        'FOREGROUND_SERVICE_LOCATION',
        'CAMERA',
      ],
    },
    ios: {
      ...config.ios,
      infoPlist: {
        ...(config.ios?.infoPlist || {}),
        NSCameraUsageDescription: 'Aike uses the camera so riders can photograph identity and vehicle documents for verification.',
        NSPhotoLibraryUsageDescription: 'Aike lets riders attach identity and vehicle documents from their photo library for verification.',
      },
    },
    plugins: [
      ...(config.plugins || []),
      ['expo-notifications', { color: config.primaryColor || '#0b6ec9' }],
      [
        'expo-image-picker',
        {
          photosPermission: 'Aike lets riders attach identity and vehicle documents from their photo library for verification.',
          cameraPermission: 'Aike uses the camera so riders can photograph identity and vehicle documents for verification.',
        },
      ],
    ],
    extra: {
      ...(config.extra || {}),
      // NON-SECRET runtime config, read by src/config.ts. Populated from the EAS build profile env.
      AIKE_API_BASE_URL: process.env.AIKE_API_BASE_URL,
      AIKE_MAPBOX_PUBLIC_TOKEN: process.env.AIKE_MAPBOX_PUBLIC_TOKEN,
      AIKE_EAS_PROJECT_ID: projectId,
      AIKE_GOOGLE_IOS_CLIENT_ID: process.env.AIKE_GOOGLE_IOS_CLIENT_ID,
      AIKE_GOOGLE_ANDROID_CLIENT_ID: process.env.AIKE_GOOGLE_ANDROID_CLIENT_ID,
      AIKE_GOOGLE_WEB_CLIENT_ID: process.env.AIKE_GOOGLE_WEB_CLIENT_ID,
      eas: { projectId },
    },
  };
};
