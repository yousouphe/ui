// Typed API service functions over the low-level client. These are the ONLY place the app
// talks to the backend; screens/hooks call these, never fetch directly. No business logic here —
// the backend owns all trusted decisions.
import { apiRequest } from './client';
import { saveTokens, clearTokens } from '@/storage/secureTokens';
import type {
  AuthTokens,
  Booking,
  BookingListFilter,
  ComplaintCategory,
  CreateBookingRequest,
  EstimateRequest,
  LoginRequest,
  NotificationItem,
  PriceBreakdown,
  RiderCandidate,
  RiderJobFilter,
  RiderOffer,
  RiderWallet,
  UserProfile,
} from '@shared/contracts/api';

type AuthResult = AuthTokens & { user: UserProfile };

export const authApi = {
  async login(body: LoginRequest): Promise<UserProfile> {
    const res = await apiRequest<AuthResult>('/auth/login', { method: 'POST', body, auth: false });
    await saveTokens(res);
    return res.user;
  },
  async register(body: Record<string, unknown>): Promise<UserProfile> {
    const res = await apiRequest<AuthResult>('/auth/register', { method: 'POST', body, auth: false });
    await saveTokens(res);
    return res.user;
  },
  async logout(): Promise<void> {
    try {
      await apiRequest<void>('/auth/logout', { method: 'POST' });
    } finally {
      await clearTokens();
    }
  },
  me(): Promise<UserProfile> {
    return apiRequest<UserProfile>('/profile');
  },
  forgot(email: string): Promise<{ message: string }> {
    return apiRequest('/auth/forgot', { method: 'POST', body: { email }, auth: false });
  },
  reset(token: string, password: string): Promise<{ message: string }> {
    return apiRequest('/auth/reset', { method: 'POST', body: { token, password }, auth: false });
  },
  updateProfile(body: { fullName?: string; phone?: string }): Promise<UserProfile> {
    return apiRequest<UserProfile>('/profile', { method: 'PATCH', body });
  },
};

export const senderApi = {
  estimate(body: EstimateRequest): Promise<PriceBreakdown> {
    return apiRequest<PriceBreakdown>('/pricing/estimate', { method: 'POST', body });
  },
  createBooking(body: CreateBookingRequest, idempotencyKey: string): Promise<{ booking: Booking; pricingPending: boolean }> {
    return apiRequest('/bookings', { method: 'POST', body, idempotencyKey });
  },
  listBookings(filter: BookingListFilter): Promise<{ bookings: Booking[] }> {
    return apiRequest(`/bookings?filter=${filter}`);
  },
  getBooking(id: number): Promise<{ booking: Booking }> {
    return apiRequest(`/bookings/${id}`);
  },
  cancel(id: number, reason: string): Promise<{ booking: Booking }> {
    return apiRequest(`/bookings/${id}/cancel`, { method: 'POST', body: { reason } });
  },
  track(id: number): Promise<{ status: string; paymentStatus: string; rider: unknown }> {
    return apiRequest(`/bookings/${id}/track`);
  },
  contact(id: number): Promise<{ role: string; fullName: string; phone: string; canCallInApp: boolean }> {
    return apiRequest(`/bookings/${id}/contact`);
  },
  riders(id: number): Promise<{ pricingPending: boolean; riders: RiderCandidate[] }> {
    return apiRequest(`/bookings/${id}/riders`);
  },
  requestRider(id: number, riderUserId: number, proposedCost: number): Promise<{ requestId: number; bookingId: number }> {
    return apiRequest(`/bookings/${id}/request`, { method: 'POST', body: { riderUserId, proposedCost } });
  },
  rate(id: number, rating: number, review?: string): Promise<{ message: string }> {
    return apiRequest(`/bookings/${id}/rating`, { method: 'POST', body: { rating, review } });
  },
  complain(bookingId: number, category: ComplaintCategory, message: string): Promise<{ message: string }> {
    return apiRequest('/complaints', { method: 'POST', body: { bookingId, category, message } });
  },
  payInit(bookingId: number, idempotencyKey: string): Promise<{ reference: string; accessCode: string | null; authorizationUrl: string | null }> {
    return apiRequest('/payments/init', { method: 'POST', body: { bookingId }, idempotencyKey });
  },
  payVerify(reference: string): Promise<{ paymentStatus: string }> {
    return apiRequest('/payments/verify', { method: 'POST', body: { reference } });
  },
};

export const riderApi = {
  profile(): Promise<Record<string, unknown>> {
    return apiRequest('/rider/profile');
  },
  setStatus(status: 'available' | 'busy' | 'offline'): Promise<{ availabilityStatus: string }> {
    return apiRequest('/rider/status', { method: 'POST', body: { status } });
  },
  pushLocation(lat: number, lng: number, status?: string): Promise<void> {
    return apiRequest('/rider/location', { method: 'POST', body: { lat, lng, status } });
  },
  offers(): Promise<{ offers: RiderOffer[] }> {
    return apiRequest('/rider/offers');
  },
  acceptOffer(requestId: number): Promise<{ bookingId: number; requestStatus: string }> {
    return apiRequest(`/rider/offers/${requestId}/accept`, { method: 'POST' });
  },
  rejectOffer(requestId: number): Promise<{ bookingId: number; requestStatus: string }> {
    return apiRequest(`/rider/offers/${requestId}/reject`, { method: 'POST' });
  },
  transition(id: number, to: string): Promise<{ booking: Booking }> {
    return apiRequest(`/rider/bookings/${id}/transition`, { method: 'POST', body: { to } });
  },
  confirmPayment(id: number): Promise<{ payout: number }> {
    return apiRequest(`/rider/bookings/${id}/confirm-payment`, { method: 'POST' });
  },
  jobs(filter: RiderJobFilter): Promise<{ bookings: Booking[] }> {
    return apiRequest(`/rider/bookings?filter=${filter}`);
  },
  wallet(): Promise<RiderWallet> {
    return apiRequest('/rider/wallet');
  },
  banks(): Promise<{ banks: { code: string; name: string }[] }> {
    return apiRequest('/rider/banks');
  },
  withdraw(amount: number, idempotencyKey: string): Promise<{ message: string }> {
    return apiRequest('/rider/withdrawals', { method: 'POST', body: { amount }, idempotencyKey });
  },
};

export const notificationsApi = {
  registerDevice(platform: 'android' | 'ios', token: string): Promise<void> {
    return apiRequest('/notifications/device', { method: 'POST', body: { platform, token } });
  },
  list(before?: number): Promise<{ notifications: NotificationItem[] }> {
    return apiRequest(`/notifications${before ? `?before=${before}` : ''}`);
  },
  markRead(id: number): Promise<void> {
    return apiRequest(`/notifications/${id}/read`, { method: 'POST' });
  },
};

export function newIdempotencyKey(): string {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}
