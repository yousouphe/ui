// Shared API request/response contracts for the Aike mobile <-> backend `/api/v1` layer.
// TypeScript types only (no logic, no secrets). The backend is the source of truth; these types
// describe the JSON envelope and payload shapes the mobile client relies on.

import type { BookingStatus, PaymentStatus, Role } from '../constants/statuses';
import type { VehicleType } from '../constants/vehicles';

export const API_VERSION = 'v1';

/** Every API response uses this envelope. */
export interface ApiEnvelope<T> {
  ok: boolean;
  data: T | null;
  error: ApiError | null;
  meta?: { requestId?: string; cursor?: string | null };
}

export interface ApiError {
  code: string; // stable machine code, e.g. "RIDER_AT_CAPACITY", "INVALID_TRANSITION"
  message: string; // human-readable, safe to display
  fields?: Record<string, string>; // per-field validation messages
}

export interface AuthTokens {
  accessToken: string;
  refreshToken: string;
  expiresInSeconds: number;
}

export interface UserProfile {
  id: number;
  fullName: string;
  email: string;
  phone: string | null;
  role: Role;
  profileCompleted: boolean;
  avatarUrl: string | null;
}

export interface LatLng {
  lat: number;
  lng: number;
}

export interface PriceBreakdown {
  minimumFee: number;
  perKm: number;
  multiplier: number;
  tax: number;
  total: number;
  distanceKm: number;
  durationMinutes: number;
}

export interface RiderCandidate {
  userId: number;
  fullName: string;
  vehicleType: VehicleType;
  rating: number | null;
  distanceKm: number | null;
  etaMinutes: number | null;
  suggestedFee: number | null;
  pricingAvailable: boolean;
  lastSeenSecondsAgo: number | null; // null => never seen; large => stale (not "live")
}

export interface RiderOffer {
  requestId: number;
  bookingId: number;
  bookingCode: string;
  pickupAddress: string;
  dropoffAddress: string;
  vehicleType: VehicleType | null;
  itemName: string;
  proposedCost: number | null;
}

export interface Booking {
  id: number;
  status: BookingStatus;
  paymentStatus: PaymentStatus;
  vehicleType: VehicleType | null;
  pickup: { address: string } & Partial<LatLng>;
  dropoff: { address: string } & Partial<LatLng>;
  agreedCost: number | null;
  selectedRiderUserId: number | null;
  createdAt: string;
  updatedAt: string;
}

/** Requests. */
export interface LoginRequest { email: string; password: string; }
export interface EstimateRequest { pickup: LatLng; dropoff: LatLng; vehicleType: VehicleType; }
export interface CreateBookingRequest {
  pickup: { address: string } & LatLng;
  dropoff: { address: string } & LatLng;
  vehicleType: VehicleType;
  recipientName: string;
  recipientPhone: string;
  itemName: string;
  itemCategory?: string;
  itemDescription?: string;
  notes?: string;
}
export type BookingListFilter = 'active' | 'unpaid' | 'history';
export type RiderJobFilter = 'active' | 'pending' | 'completed' | 'cancelled';

/** Unsafe writes carry an Idempotency-Key header; see docs/04. */
export const IDEMPOTENCY_HEADER = 'Idempotency-Key';
export const AUTH_HEADER = 'Authorization';
