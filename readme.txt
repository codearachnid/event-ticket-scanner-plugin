=== Event Ticket Scanner ===
Contributors: codearachnid
Plugin URI: https://eventticketscanner.com/
Tags: event tickets, the events calendar, check-in, qr code, tickets
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Companion REST API for the TEC Ticket Scanner mobile app: offline-first attendee sync, batched check-ins, and QR pairing for Event Tickets.

== Description ==

Pairs a phone running the TEC Ticket Scanner app with your WordPress site so door staff can scan Event Tickets QR codes and get instant green / amber / red check-in results — even offline.

What it adds on top of the free Event Tickets plugin:

* **QR pairing** — a wp-admin page (Tickets → Scanner App) renders a single-use, 5-minute QR code; scanning it hands the device its own revocable Application Password. No shared site-wide API keys.
* **Delta attendee sync** — `updated_since` support backed by a check-in touch index (Event Tickets check-ins don't bump `post_modified`, so the plugin tracks changes itself).
* **Batched, idempotent check-ins** — the app queues check-ins offline and pushes them with client UUIDs; retried batches return stored results instead of double-applying. Un-check-in supported.
* **Per-event stats** — totals and per-ticket-type check-in counts.
* **Scoped scanner accounts** — an **Event Scanner** role that can do nothing but check attendees in, restricted to the events you assign it. Scanner users never see events they aren't assigned to, in the app or in the API.
* **Organizer links** — point an Organizer at a user account and that user scans every event listing the organizer, automatically, as the calendar grows.
* **Proper auth** — WordPress Application Passwords over HTTPS plus a dedicated `tec_scanner_checkin` capability (administrators and editors by default, filterable via `tec_scanner_checkin_roles`).

Supports Tickets Commerce and RSVP attendees out of the box, and Event Tickets Plus WooCommerce attendees when present.

REST namespace: `tec-scanner/v1` (`/me`, `/events`, `/events/{id}/attendees`, `/events/{id}/stats`, `/checkins`, `/pair`).

== Installation ==

1. Install and activate Event Tickets 5.7 or newer.
2. Upload and activate this plugin.
3. Go to Tickets → Scanner App and generate a pairing code, or create an Application Password manually under Users → Profile.

== Scanner users ==

Tickets → Scanner Users creates check-in-only accounts and assigns each one to specific events.

* **Assign events** — tick the events an account may scan, on the Scanner Users screen or on the user's own profile. A scanner with no assignments sees nothing.
* **Link an Organizer** — edit an Organizer and pick a user under "Scanner access". That user scans every event listing the organizer, in addition to any events assigned directly.
* **Pair their phone** — "Pair a device" renders a QR for that specific account; the app receives an Application Password belonging to them, not to you.
* **Unrestricted accounts** — administrators and editors hold `tec_scanner_scan_all_events` and scan everything. Filter `tec_scanner_scan_all_events_roles` to change that, and `tec_scanner_manager_roles` to control who can manage scanner users.

WP-CLI:

`wp tec-scanner scanner create doorstaff --events=501,502`
`wp tec-scanner scanner assign doorstaff 503`
`wp tec-scanner scanner link-organizer 77 doorstaff`
`wp tec-scanner scanner list`

== Changelog ==

= 1.1.0 =
* Event Scanner role: check-in-only accounts restricted to assigned events, enforced on every endpoint.
* Organizer → user links grant scanning access to that organizer's whole calendar.
* Scanner Users admin screen, per-user profile assignments, per-user device pairing, and `wp tec-scanner scanner` commands.

= 1.0.0 =
* Initial release: pairing, attendee sync, batched check-ins, stats.
