# TEC Ticket Scanner Companion — agent orientation

WordPress companion plugin for the TEC Ticket Scanner mobile app (that app lives at
`/Users/codearachnid/Sites/laravel/tec-ticket-scanner` — **its `docs/api/openapi.yaml` is
the contract's source of truth**; never change response shapes here without updating it).

## What this is

REST namespace `event-ticket-scanner/v1`: `/me`, `/events`, `/events/{id}/attendees`
(`updated_since` delta), `/events/{id}/stats`, `POST /checkins` (batch, idempotent by
`op_id`), `POST /pair` (unauthenticated single-use-token → Application Password exchange).
Plus the wp-admin pairing page (Tickets → Scanner App), the Scanner Users page
(Tickets → Scanner Users), `wp event-ticket-scanner` (create/assign/link-organizer/list) and `wp event-ticket-scanner seed`.

## Architecture notes (hard-won — don't re-derive)

- **Delta sync**: Event Tickets check-ins only write postmeta, never `post_modified` —
  `TouchIndex` (table `wp_event_ticket_scanner_touch`, DATETIME(6)) hooks
  `event_tickets_checkin/uncheckin`, `rsvp_checkin/uncheckin`, `save_post_{attendee types}`,
  `before_delete_post`. `updated_since` filters touch-time (fallback `post_modified_gmt`).
- **Idempotency**: `wp_event_ticket_scanner_ops` stores per-`op_id` results; retried batches return
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
  `{v, type: "event-ticket-scanner-pair", url, user, token}`.
- Provider meta maps live in `src/Attendees/Providers.php` (verified against ET 5.29.x;
  see the mobile repo's PLAN.md "Verified facts" for the sources).
- Capability: `event_ticket_scanner_checkin` (administrator + editor on activation; filter
  `event_ticket_scanner_checkin_roles`). HTTPS enforced except `wp_get_environment_type()`
  local/development (filter `event_ticket_scanner_allow_insecure_transport`).
- **Event scoping** (`src/Assignments.php`): a user is *unrestricted* with
  `event_ticket_scanner_scan_all_events` (admin/editor) or *restricted* to a scope =
  direct assignments ∪ organizer-linked events. Enforced server-side in three
  places, all of which must stay in sync: `/events` (`post__in`, and an early
  empty response — `post__in => []` is IGNORED by WP_Query and would leak every
  event), `Controller::guard_event()` (attendees + stats → 403
  `event_ticket_scanner_event_forbidden`), and `CheckinProcessor::apply()` (→
  `not_authorized`). That check-in denial carries an internal `_no_store` flag so
  it never lands in the idempotency ledger — the same `op_id` must still apply if
  the operator is assigned afterwards.
- Direct assignments = one user-meta row per event (`_event_ticket_scanner_event_id`), not
  a serialized array, so "who scans event X" is a plain meta query.
- **Organizer links** (`src/Organizers.php`): `_event_ticket_scanner_user_id` postmeta on a
  `tribe_organizer` → that user scans every event with that `_EventOrganizerID`.
  One user per organizer. The lookup is a **direct `$wpdb` query on purpose**: TEC
  joins `wp_tec_occurrences` into every `WP_Query` for `tribe_events` and silently
  drops past events (verified on TEC 6.x — a `meta_query` for `_EventOrganizerID`
  returns nothing for past events), and scope must not depend on dates.
- Role `event_ticket_scanner` ("Event Scanner"; migrated from `tec_scanner` by
  `Capabilities::migrate_legacy_role()` — the slug lives in each user's caps meta) = `read` + `event_ticket_scanner_checkin`, nothing
  else; `register_role()` actively strips `event_ticket_scanner_scan_all_events` from it.
  Managers hold `event_ticket_scanner_manage_scanners` (administrator; filter
  `event_ticket_scanner_manager_roles`) — that cap gates the Scanner Users page, the
  profile field, the organizer metabox, and pairing on behalf of another user.

## Dev environment

- Dev site: `/Users/codearachnid/Sites/WordPress/wp-dev` → https://wp-dev.test
  (WP 7.0.x, Event Tickets 5.29.2.1, PHP 8.5, Herd). `wp` CLI works from that dir.
- **wp-cli on PHP 8.5 prints deprecation noise on STDOUT** — pipe through
  `| grep -v Deprecated`, and `| tail -1` when capturing values (e.g. `--porcelain`).
- `wp db query` fails (no mysql binary in PATH) — use `wp eval` with `$wpdb` instead.
- Seed/reset test data: `wp event-ticket-scanner seed --fresh --attendees=20`
  (event + GA/VIP/RSVP tickets + orders + attendees incl. refunded/pending/checked-in cases).
- Smoke test: create an app password (`wp user application-password create 1 name --porcelain | tail -1`)
  and curl `https://wp-dev.test/wp-json/event-ticket-scanner/v1/...` with `-k -u 'login:pass'`.
- Lint: `php -l` per file (no test suite yet — wp-env/PHPUnit contract tests are the
  next milestone; golden-test against the mobile repo's `docs/api/fixtures/`).
