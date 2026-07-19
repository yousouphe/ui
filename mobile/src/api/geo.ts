// On-device geocoding via Mapbox, using the PUBLIC token (safe on device by design — same as the
// web). Restricted to Nigeria. This never touches the secret token (that stays server-side for
// Directions/pricing). Kept out of services.ts because it talks to Mapbox, not the Aike backend.
import { config } from '@/config';

export type Place = { name: string; address: string; lat: number; lng: number };

export async function geocode(query: string, signal?: AbortSignal): Promise<Place[]> {
  const token = config.mapboxPublicToken;
  if (!token || query.trim().length < 3) return [];
  const url =
    `https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(query)}.json` +
    `?access_token=${token}&country=ng&limit=6&language=en&autocomplete=true`;
  const res = await fetch(url, { signal });
  if (!res.ok) return [];
  const json = (await res.json()) as { features?: Array<{ text?: string; place_name?: string; center?: [number, number] }> };
  return (json.features ?? [])
    .filter((f) => Array.isArray(f.center) && f.center.length === 2)
    .map((f) => ({
      name: f.text ?? f.place_name ?? '',
      address: f.place_name ?? f.text ?? '',
      lng: (f.center as [number, number])[0],
      lat: (f.center as [number, number])[1],
    }));
}
