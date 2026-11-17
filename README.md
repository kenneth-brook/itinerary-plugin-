# Visitor Itinerary Planner

Visitors can build a trip from published businesses and Event Forge events,
add/remove/reorder up to 50 stops, and share the stop list without an account.
An account is required to save a trip to the database. Saved trips belong to
the signed-in visitor and can be reopened, updated, copied, and deleted.

## Installation and upgrade

Requires PHP 7.4+, MySQLi, MySQL/MariaDB, Event Forge 0.7.2 schema 9, and the
Business Directory. Copy `itinerary/` into the existing Event Forge `events/`
folder. Upload the companion Business Directory and Panel updates together,
then sign in to the panel as an administrator and select **Set up available
modules** from Overview. The upgrade creates visitor account and login
attempt tables, and adds nullable ownership to existing itinerary records.
It preserves the old records and stop IDs. The database user needs CREATE
and ALTER privileges for setup.

Link the public website to `/event-forge/events/itinerary/index.php`, using
the actual Event Forge prefix. The panel links to this visitor planner.
Visitor account sessions are independent of Event Forge staff sessions.
Serve production accounts over HTTPS. Registration uses email and a password
of at least 12 bytes and at most 72 bytes. Login and registration are rate
limited by IP and email. This release has no email verification or password
recovery workflow, so account recovery needs a later email integration.

## Guest drafts and accounts

An unfinished trip is retained in browser session storage for the current
tab. Its title, notes, and ordered stops are restored after registration or
sign-in. Account creation does not automatically save a guest draft. Press
**Save trip** to persist it. **Start a new trip** clears the current draft.
Closing the tab can discard an unsaved guest draft. Shared links include
only ordered stop IDs. Saved titles, notes and account details stay private.

Previously selected event occurrences remain in saved trips after their
dates pass. Draft/deleted business records, canceled/unpublished events,
and recurrence templates do not expose source details. Their references
remain in the private itinerary so the visitor can remove or replace them.
Source details are resolved at read time.

## APIs

`GET itinerary/saved-api.php` lists the signed-in visitor's trips. Adding
`?id=123` returns a single owned trip and its ordered stops. Send JSON with
`action`, `csrf_token`, `id` (zero for a new trip), `title`, `description`, and
`stops` to save. Stops use `business:123` and `event:456` values. Delete with
`action=delete`, `id` and the CSRF token. The planner supplies the token.
Anonymous access returns 401 and another visitor's ID returns 404.

The existing `GET itinerary/api.php` and `?id=123` endpoints continue to
serve previously published unowned suggested itineraries. Private visitor
trips are excluded. The new panel manages businesses and provides a link to
the public planner. It does not edit visitors' private trips.
