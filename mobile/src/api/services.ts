// Typed API service functions over the low-level client. These are the ONLY place the app
// talks to the backend; screens/hooks call these, never fetch directly. No business logic here —
// the backend owns all trusted decisions.
import { apiRequest } from './client';
import { saveTokens, clearTokens } from '@/storage/secureTokens';
import type {
  AuthTokens,
  Booking,
  BookingListFilter,
  ChatMessage,
  ComplaintCategory,
  CreateBookingRequest,
  EstimateRequest,
  LoginRequest,
  NotificationItem,
  PaymentReceipt,
  PriceBreakdown,
  ReceiptDetail,
  RiderCandidate,
  RiderBankAccount,
  RiderKyc,
  RiderJobFilter,
  RiderOffer,
  RiderWallet,
  TransactionFilters,
  TransactionsResult,
  UpdateBookingRequest,
  UserProfile,
  WithdrawalItem,
} from '@shared/contracts/api';
import type { VehicleType } from '@shared/constants/vehicles';

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
  async google(idToken: string): Promise<UserProfile> {
    const res = await apiRequest<AuthResult>('/auth/google', { method: 'POST', body: { idToken }, auth: false });
    await saveTokens(res);
    return res.user;
  },
  completeProfile(body: { phone: string; role?: 'sender' | 'rider'; vehicleType?: string }): Promise<UserProfile> {
    return apiRequest<UserProfile>('/profile/complete', { method: 'POST', body });
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
  updateBooking(id: number, patch: UpdateBookingRequest): Promise<{ booking: Booking; priceChanged: boolean }> {
    return apiRequest(`/bookings/${id}`, { method: 'PATCH', body: patch });
  },
  rebook(id: number): Promise<{ booking: Booking }> {
    return apiRequest(`/bookings/${id}/rebook`, { method: 'POST' });
  },
  payments(): Promise<{ payments: PaymentReceipt[] }> {
    return apiRequest('/payments');
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
  withdrawals(): Promise<{ withdrawals: WithdrawalItem[] }> {
    return apiRequest('/rider/withdrawals');
  },
  bank(): Promise<{ bank: RiderBankAccount | null }> {
    return apiRequest('/rider/bank');
  },
  verifyBank(accountNumber: string, bankCode: string): Promise<{ accountName: string }> {
    return apiRequest('/rider/bank/verify', { method: 'POST', body: { accountNumber, bankCode } });
  },
  saveBank(accountNumber: string, bankCode: string): Promise<{ bankName: string; accountName: string }> {
    return apiRequest('/rider/bank', { method: 'POST', body: { accountNumber, bankCode } });
  },
  updateVehicle(vehicleType: VehicleType): Promise<{ vehicleType: string }> {
    return apiRequest('/rider/profile', { method: 'PATCH', body: { vehicleType } });
  },
  kyc(): Promise<RiderKyc> {
    return apiRequest('/rider/kyc');
  },
  submitKyc(form: FormData): Promise<{ kycStatus: string }> {
    return apiRequest('/rider/kyc', { method: 'POST', body: form });
  },
};

// Chat is shared by both parties on a booking; the backend derives the receiver, so callers only
// send a booking id + text. Poll `messages(id, since)` with the last id to fetch just new messages.
export const chatApi = {
  messages(bookingId: number, since = 0): Promise<{ messages: ChatMessage[]; lastId: number }> {
    return apiRequest(`/bookings/${bookingId}/messages${since > 0 ? `?since=${since}` : ''}`);
  },
  send(bookingId: number, message: string): Promise<{ message: ChatMessage }> {
    return apiRequest(`/bookings/${bookingId}/messages`, { method: 'POST', body: { message } });
  },
};

// Transaction history + receipts, shared by senders and riders. Mirrors the web transactions.php
// and payments/receipt.php; the backend derives the role-specific view.
export const financeApi = {
  transactions(filters: TransactionFilters = {}): Promise<TransactionsResult> {
    const q = new URLSearchParams();
    Object.entries(filters).forEach(([k, v]) => { if (v !== undefined && v !== '') q.append(k, String(v)); });
    const qs = q.toString();
    return apiRequest(`/transactions${qs ? `?${qs}` : ''}`);
  },
  receipt(bookingId: number): Promise<{ receipt: ReceiptDetail }> {
    return apiRequest(`/bookings/${bookingId}/receipt`);
  },
  resendReceipt(bookingId: number): Promise<{ message: string }> {
    return apiRequest(`/bookings/${bookingId}/receipt/resend`, { method: 'POST' });
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
