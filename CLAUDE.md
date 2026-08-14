# TEC Ticket Scanner Companion — agent orientation

WordPress companion plugin for the TEC Ticket Scanner mobile app (that app lives at
`/Users/codearachnid/Sites/laravel/tec-ticket-scanner` — **its `docs/api/openapi.yaml` is
the contract's source of truth**; never change response shapes here without updating it).

## What this is

REST namespace `tec-scanner/v1`: `/me`, `/events`, `/events/{id}/attendees`
(`updated_since` delta), `/events/{id}/stats`, `POST /checkins` (batch, idempotent by
`op_id`), `POST /pair` (unauthenticated single-use-token → Application Password exchange).
Plus the wp-admin pairing page (Tickets → Scanner App) and `wp tec-scanner seed`.

## Architecture notes (hard-won — don't re-derive)

- **Delta sync**: Event Tickets check-ins only write postmeta, never `post_modified` —
  `TouchIndex` (table `wp_tec_scanner_touch`, DATETIME(6)) hooks
  `event_tickets_checkin/uncheckin`, `rsvp_checkin/uncheckin`, `save_post_{attendee types}`,
  `before_delete_post`. `updated_since` filters touch-time (fallback `post_modified_gmt`).
- **Idempotency**: `wp_tec_scanner_ops` stores per-`op_id` results; retried batches return
  stored results. `error` results are NEVER stored (must stay retryable).
- **Check-in guard (ET 5.29.2+)**: `tec_tickets_attendee_checkin` filter validates the
  BACKING ORDER's status via the provider. Tickets Commerce resolves the order from the
  attendee's **`post_parent`** (`tec_tc_get_order($attendee->post_parent)`) — an attendee
  without a completed `tec_tc_order` parent cannot check in. The seeder creates real order
  posts for exactly this reason. `already_checked_in` is detected from checkin meta BEFORE
  provider resolution so duplicates surface even if a provider module is disabled.
- **Tickets Commerce must be ENABLED** (`tribe_update_option('tickets_commerce_enabled', true)`;
  check `tec_tickets_commerce_is_enabled()`) or the data API won't resolve TC attendees'
  provider. It's a tribe option (inside `tribe_events_calendar_options`), not a WP option.
- **Pairing**: tokens are 20-byte hex, stored as SHA-256-keyed transients (5 min TTL),
  consumed before minting; `WP_Application_Passwords::create_new_application_password()`
  issues the credential. Per-IP rate limit (10 / 5 min). QR payload:
  `{v, type: "tec-scanner-pair", url, user, token}`.
- Provider meta maps live in `src/Attendees/Providers.php` (verified against ET 5.29.x;
  see the mobile repo's PLAN.md "Verified facts" for the sources).
- Capability: `tec_scanner_checkin` (administrator + editor on activation; filter
  `tec_scanner_checkin_roles`). HTTPS enforced except `wp_get_environment_type()`
  local/development (filter `tec_scanner_allow_insecure_transport`).

## Dev environment

- Dev site: `/Users/codearachnid/Sites/WordPress/wp-dev` → https://wp-dev.test
  (WP 7.0.x, Event Tickets 5.29.2.1, PHP 8.5, Herd). `wp` CLI works from that dir.
- **wp-cli on PHP 8.5 prints deprecation noise on STDOUT** — pipe through
  `| grep -v Deprecated`, and `| tail -1` when capturing values (e.g. `--porcelain`).
- `wp db query` fails (no mysql binary in PATH) — use `wp eval` with `$wpdb` instead.
- Seed/reset test data: `wp tec-scanner seed --fresh --attendees=20`
  (event + GA/VIP/RSVP tickets + orders + attendees incl. refunded/pending/checked-in cases).
- Smoke test: create an app password (`wp user application-password create 1 name --porcelain | tail -1`)
  and curl `https://wp-dev.test/wp-json/tec-scanner/v1/...` with `-k -u 'login:pass'`.
- Lint: `php -l` per file (no test suite yet — wp-env/PHPUnit contract tests are the
  next milestone; golden-test against the mobile repo's `docs/api/fixtures/`).
