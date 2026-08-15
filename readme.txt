=== Event Ticket Scanner ===
Contributors: codearachnid
Tags: check-in, qr code, tickets, events, box office
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn any phone into an offline-first ticket scanner for Event Tickets, with per-event scanner accounts and one-scan device pairing.

== Description ==

**Your door should not depend on the venue's wifi.**

Event Ticket Scanner connects the free Event Ticket Scanner mobile app to your WordPress site, so your staff can check attendees in from their own phones — instantly, and with no connection at the door.

The app downloads the attendee list before doors open. From then on every scan is answered by the phone itself, in milliseconds: green for valid, amber for already scanned, red for refunded or unknown. Scans queue on the device and sync back the moment there's signal again. A dead hotspot becomes a non-event instead of a queue out the door.

Everything runs between your phone and your site. There is no middleman service, no per-scan fee, and no account to create anywhere else.

= Why teams choose it =

* **It works when nothing else does.** Full offline scanning, with an ordered queue that reconciles automatically. Airplane mode is a supported configuration, not a failure case.
* **Nobody double-scans.** Every queued operation carries its own ID, so a retry after a dropped connection can never check the same person in twice — even across several phones on the same door.
* **Give staff exactly one event.** Create a scanner account, tick the events it may scan, hand over the phone. That account cannot see, load, or check in anything else — the restriction is enforced on the server, not just hidden in the app.
* **Pair a phone in about five seconds.** Show a QR code in wp-admin, point the phone at it, done. No typing site URLs or passwords on a phone screen at 7pm.
* **Free and GPL.** The whole plugin, every feature, no upsell tier and no "pro" gate on the useful parts.

= Security you can actually verify =

Door staff are often temporary, and their phones get lost. The plugin is built for that reality:

* **No data leaves your server.** The plugin makes zero outbound HTTP requests — grep the source. Your attendee list is never copied to a third-party service, because there is no third-party service.
* **Real WordPress credentials, per device.** Pairing issues a standard WordPress Application Password scoped to one device. Lose a phone and you revoke that one credential from the user's profile; every other device keeps working.
* **Pairing codes expire.** Codes are single-use, live for five minutes, are stored only as a SHA-256 hash, and are consumed before any credential is minted. Pairing attempts are rate limited per IP.
* **HTTPS required.** The API refuses to answer over plain HTTP outside local development.
* **Least privilege by default.** The Event Scanner role holds exactly two capabilities: read, and check attendees in. Not edit posts, not view orders, not see your calendar.
* **Clean uninstall.** Remove the plugin and its tables, capabilities, and role go with it.

= Assign scanners the way you already work =

Scanner access is managed in the two places you would look for it:

* **Events → Scanners** lists every scanner account, the events each one covers, and a pairing button per person.
* **The event edit screen** has a Ticket Scanners box listing everyone eligible for that specific event — scanner accounts, administrators, users linked to the event's organizer, and anyone who can edit the event.
* **Organizers** can be linked to a user account, so that person automatically scans every event that organizer runs, today and next season, without anyone maintaining a list.

= Built on Event Tickets =

Works with the free Event Tickets plugin and reads attendees from Tickets Commerce and RSVP out of the box, plus WooCommerce attendees when Event Tickets Plus is active. Check-ins written by the app appear in the normal Event Tickets attendee screens, and check-ins made in wp-admin appear on the phones — it is one attendee list, not a parallel one.

= Get the app =

The Event Ticket Scanner mobile app is available for iOS and Android. See [eventticketscanner.com](https://eventticketscanner.com/) for download links and a walkthrough of the door workflow.

== Installation ==

1. Install and activate Event Tickets 5.7 or newer, and make sure your site is served over HTTPS.
2. Install and activate Event Ticket Scanner.
3. Go to **Events → Scanners** and create a scanner account for each person working the door, ticking the events they should cover.
4. Install the Event Ticket Scanner app on their phone.
5. Press **Pair a device** next to that person's name and point their phone's camera at the QR code.
6. Open the event in the app before doors, let the attendee list download, and scan.

Administrators can also pair their own phone from **Tickets → Scanner App**, or connect the app by hand with a site URL, username, and Application Password.

== Frequently Asked Questions ==

= Does it really work with no signal? =

Yes. The attendee list is stored on the phone, and scans are validated on the device. Check-ins queue locally and upload when a connection returns. The only thing you need connectivity for is the initial download before doors open.

= What if two doors scan the same ticket? =

Whichever scan reaches the server first wins, and the second gets an "already checked in" result showing when and by which device. Because each queued operation carries its own identifier, a retry after a dropped connection is never applied twice.

= Is any attendee data sent to a third party? =

No. The plugin makes no outbound requests of any kind. The app talks directly to your site over HTTPS and nowhere else.

= A staff member lost their phone. What do I do? =

Go to that user's profile, find the Application Password created for that device, and revoke it. That phone loses access immediately; other devices are unaffected.

= Can a scanner see events they are not working? =

No. A scanner account is restricted to the events assigned to it, and the restriction is enforced on the server for every request — the event list, the attendee list, the stats, and each individual check-in. An account with no assignments sees nothing at all.

= Do I need Event Tickets Plus? =

No. Tickets Commerce and RSVP attendees are supported with the free Event Tickets plugin. Event Tickets Plus adds WooCommerce attendees, which are supported too when present.

= Can I still check people in from wp-admin? =

Yes, and it stays in sync. The plugin tracks check-ins made anywhere on the site so the phones pick them up on their next sync, and vice versa.

= Does this work with recurring events or past events? =

Yes. Scanner assignments are not tied to dates, so access to an event survives the event ending — useful for reconciling late scans the morning after.

= Is there a REST API I can build against? =

Yes, under the `tec-scanner/v1` namespace: `/me`, `/events`, `/events/{id}/attendees` with delta sync, `/events/{id}/stats`, `/checkins`, and `/pair`. It is documented as an OpenAPI contract.

== Screenshots ==

1. The Scanners screen: every scanner account, the events they cover, and one-press device pairing.
2. Pairing a phone by QR code — the credential is issued to that device alone.
3. The Ticket Scanners box on an event, listing everyone eligible to work that door.
4. The mobile app scanning a ticket offline, with an instant valid result.
5. Live check-in totals per ticket type.

== External services ==

This plugin does not connect to any external service. All communication happens between your own WordPress site and the scanning device on your own network or the device's mobile connection.

== Changelog ==

= 1.1.0 =
* New: Event Scanner role — check-in-only accounts restricted to the events you assign them, enforced on every API endpoint.
* New: Events → Scanners screen to create scanner accounts, assign events, and pair each person's device.
* New: Ticket Scanners meta box on the event edit screen for assigning door staff per event.
* New: link an Organizer to a user account to grant scanning access across that organizer's whole calendar.
* New: `wp event-ticket-scanner` WP-CLI commands for creating, assigning, and listing scanners.
* Changed: the scanner role is now `event_ticket_scanner` and its capabilities are `event_ticket_scanner_*`, matching the plugin slug. Existing accounts are migrated automatically on upgrade.
* Improved: `/me` now reports the caller's event scope so the app can hide what it cannot load.

= 1.0.0 =
* Initial release: QR pairing, delta attendee sync, batched offline check-ins, and per-event stats.

== Upgrade Notice ==

= 1.1.0 =
Adds scoped scanner accounts so door staff only see the events they are working. Existing administrator and editor access is unchanged.
