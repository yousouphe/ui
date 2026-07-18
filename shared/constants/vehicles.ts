// Vehicle-type identifiers mirrored from the backend (bike/car/van). Identifiers + display
// metadata only — pricing multipliers and ETA speeds are computed by the backend and must NOT
// be duplicated here (they are admin-configurable server-side).

export const VEHICLE_TYPE = {
  BIKE: 'bike',
  CAR: 'car',
  VAN: 'van',
} as const;
export type VehicleType = (typeof VEHICLE_TYPE)[keyof typeof VEHICLE_TYPE];

export const VEHICLE_LABEL: Record<VehicleType, string> = {
  bike: 'Motorbike',
  car: 'Car',
  van: 'Van',
};

export const VEHICLE_TYPES: VehicleType[] = [
  VEHICLE_TYPE.BIKE,
  VEHICLE_TYPE.CAR,
  VEHICLE_TYPE.VAN,
];

// Backend caps/rules surfaced for display/UX only (authoritative values live server-side).
export const RIDER_MAX_CONCURRENT_ORDERS = 3;
export const MAX_RIDERS_RETURNED = 10;
