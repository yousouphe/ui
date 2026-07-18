// Connectivity detection that mirrors the web PWA approach: browser/OS online signal is only a
// hint, so we confirm real reachability with a lightweight probe to the backend before declaring
// "online". Guards against duplicate probes and uses a short capped backoff while offline.
//
// (Phase 4 wires this to @react-native-community/netinfo for the OS signal; here it uses a
// timed probe so the behaviour is testable and framework-light in the scaffold.)
import { useEffect, useRef, useState } from 'react';
import { config } from '@/config';

const BACKOFF_MS = [3000, 5000, 8000, 12000, 15000];

async function probe(): Promise<boolean> {
  const controller = new AbortController();
  const t = setTimeout(() => controller.abort(), 3500);
  try {
    // /health is an unauthenticated 204 endpoint (Phase 3), analogous to the web ping.php.
    const res = await fetch(`${config.apiBaseUrl}/health?t=${Date.now()}`, {
      method: 'GET',
      cache: 'no-store',
      signal: controller.signal,
    });
    return res.ok || res.status === 204;
  } catch {
    return false;
  } finally {
    clearTimeout(t);
  }
}

export function useConnectivity() {
  const [online, setOnline] = useState(true);
  const checking = useRef(false);
  const attempt = useRef(0);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    let mounted = true;
    const run = async () => {
      if (checking.current) return;
      checking.current = true;
      const ok = await probe();
      checking.current = false;
      if (!mounted) return;
      setOnline(ok);
      if (ok) {
        attempt.current = 0;
      } else {
        const delay = BACKOFF_MS[Math.min(attempt.current, BACKOFF_MS.length - 1)];
        attempt.current += 1;
        timer.current = setTimeout(run, delay);
      }
    };
    run();
    return () => {
      mounted = false;
      if (timer.current) clearTimeout(timer.current);
    };
  }, []);

  return { online };
}
