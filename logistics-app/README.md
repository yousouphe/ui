# Module 2: Rider Discovery and Request Dispatch

This package extends the flow after booking creation.

## What it includes
- shared login for sender and rider accounts
- sender dashboard with booking list
- nearby rider discovery based on pickup coordinates
- cost proposal and request dispatch to a rider
- rider dashboard for accept/reject actions
- rider location update page
- MySQL schema for:
  - `rider_profiles`
  - `rider_requests`
  - updated `bookings`

## Demo accounts
- Sender: `sender@example.com` / `password`
- Rider 1: `rider1@example.com` / `password`
- Rider 2: `rider2@example.com` / `password`
- Rider 3: `rider3@example.com` / `password`

## Main pages
- `index.php`
- `login.php`
- `dashboard.php`
- `bookings/discover.php`
- `bookings/request_status.php`
- `rider/dashboard.php`
- `rider/update_location.php`

## Setup
1. Import `sql/module2_schema.sql`
2. Update `config/env.php`
3. Serve with PHP

## Notes
- Rider ranking uses pickup coordinates and a Haversine distance query.
- The sender proposes a cost before dispatching the rider request.
- When a rider accepts, booking status changes to `matched`.
- When a rider accepts, the rider profile status changes to `busy`.
