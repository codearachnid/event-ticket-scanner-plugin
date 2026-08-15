<p align="center">
  <img src=".wordpress-org/banner-1544x500.png" alt="Event Ticket Scanner" width="100%">
</p>

<h1 align="center">Event Ticket Scanner</h1>

<p align="center">
  <strong>Turn any phone into an offline-first ticket scanner for Event Tickets.</strong><br>
  Free, GPL, and no third-party service in the middle.
</p>

<p align="center">
  <a href="https://eventticketscanner.com/">Get the app</a> ·
  <a href="#rest-api">REST API</a> ·
  <a href="#security-model">Security</a> ·
  <a href="#development">Development</a>
</p>

---

Your door should not depend on the venue's wifi.

This plugin connects the **Event Ticket Scanner mobile app** to your WordPress site. The app pulls the attendee list before doors open, then validates every scan on the device itself — green for valid, amber for already scanned, red for refunded or unknown — in milliseconds, with the phone in airplane mode if it comes to that. Check-ins queue locally and reconcile the moment there's signal.

Everything happens between your phone and your site. The plugin makes **zero outbound HTTP requests**; there is no middleman service, no per-scan fee, and no account to create anywhere else.

## Highlights

| | |
|---|---|
| **Offline by design** | Full attendee list on the device, instant local validation, ordered queue that syncs on reconnect. |
| **No double check-ins** | Every operation carries a UUID; retries after a dropped connection return the original result instead of re-applying. |
| **Scoped scanner accounts** | An Event Scanner role restricted to the events you assign. Enforced server-side on every endpoint — not hidden in the app. |
| **Five-second pairing** | Show a QR in wp-admin, point the phone at it. The device gets its own revocable Application Password. |
| **Delta sync** | `updated_since` returns only what changed, backed by a dedicated touch index (Event Tickets check-ins never bump `post_modified`). |
| **Organizer links** | Point an Organizer at a user and they scan that organizer's whole calendar, automatically. |

Works with the free **Event Tickets** plugin: Tickets Commerce and RSVP attendees out of the box, WooCommerce attendees when Event Tickets Plus is active. Check-ins made in the app and in wp-admin are the same check-ins, in both directions.

## Install

1. Event Tickets 5.7+ active, site on HTTPS.
2. Activate this plugin.
3. **Events → Scanners** → create an account per person, tick their events.
4. **Pair a device** → point their phone at the QR code.

## Assigning door staff

Three ways in, one source of truth:

- **Events → Scanners** — every scanner account, the events it covers, pairing per person.
- **The event edit screen** — a Ticket Scanners box listing everyone eligible for that event: scanner accounts, administrators, the organizer's linked user, and anyone who can edit the event.
- **Organizers** — link an Organizer to a user account for standing access across their calendar.

A restricted account with no assignments sees nothing: an empty event list, `403` on any event it asks for by ID, and `not_authorized` on any check-in it attempts.

## Security model

| Concern | How it's handled |
|---|---|
| Attendee data leaving the site | It doesn't. No outbound requests exist in the codebase. |
| A lost phone | Revoke that one Application Password from the user's profile; other devices are unaffected. |
| Pairing codes | 20 random bytes, stored SHA-256 hashed, single use, 5-minute TTL, consumed before any credential is minted, rate limited per IP. |
| Transport | HTTPS enforced; plain HTTP refused outside `local`/`development` environments. |
| Privilege | The `tec_scanner` role holds `read` and `tec_scanner_checkin`. Nothing else. |
| Uninstall | Tables, capabilities, and role removed. |

## REST API

Namespace `tec-scanner/v1`. Authentication is a WordPress Application Password over HTTPS.

| Endpoint | Purpose |
|---|---|
| `GET /me` | Validate credentials; reports capabilities and the caller's event scope. |
| `GET /events` | Events the caller may scan, with attendee and check-in counts. |
| `GET /events/{id}/attendees` | Full or delta (`updated_since`) attendee sync. |
| `GET /events/{id}/stats` | Totals and per-ticket-type check-in counts. |
| `POST /checkins` | Batched, idempotent check-in / un-check-in. |
| `POST /pair` | Exchange a single-use pairing token for an Application Password. |

The contract is specified as OpenAPI and both this plugin and the mobile app are tested against the same fixtures.

## WP-CLI

```bash
wp tec-scanner scanner create doorstaff --events=501,502
wp tec-scanner scanner assign doorstaff 503
wp tec-scanner scanner link-organizer 77 doorstaff
wp tec-scanner scanner list
wp tec-scanner seed --fresh --attendees=20   # test data
```

## Development

Requires PHP 8.1+ and WordPress 6.8+.

```bash
./bin/build-zip.sh                 # release ZIP, excludes everything in .distignore
wp plugin check event-ticket-scanner \
  --exclude-directories=bin \
  --exclude-files=.gitignore,.distignore,CLAUDE.md
```

The second command is the release-equivalent Plugin Check run — it must report no errors before submitting to wordpress.org.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
