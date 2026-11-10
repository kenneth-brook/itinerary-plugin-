# Itinerary Plugin

Publish ordered, staff-curated itineraries that combine Business Directory
listings and Event Forge occurrences. Install PHP 7.4+ with MySQLi, Event Forge
0.7.2 (schema 9), and the Business Directory. Copy the repository's
`itinerary/` directory into the existing Event Forge `events/` directory.
Install the additive itinerary tables through the shared panel.

The module stores ordered source IDs. Business details and event times are
resolved when requested. The picker offers published businesses and current
or upcoming published events, including generated recurrence instances.
Previously selected events stay on the itinerary after their dates pass.
Draft or deleted sources, canceled events, and recurrence templates are
excluded from public stops. Unavailable references remain visible in the
panel so staff can replace them or wait for the source to be republished.

## Public API

- `GET /events/itinerary/api.php` lists published itineraries with `id`,
  `title` and `description`.
- `GET /events/itinerary/api.php?id=123` returns `{"itinerary":{...}}` with an
  ordered `stops` array. Each stop has `type`, source `id`, `position`, and
  a `business` or `event` object. Positions can have gaps when sources are
  unavailable. Event URLs are paths relative to the Event Forge host and
  event timestamps use the site's existing Event Forge conventions.
- Draft and missing itineraries return 404. Use the actual Event Forge URL
  prefix if installed under a subdirectory.

Website consumers can fetch from the same origin in the browser or from
their backend. The feeds expose staff-curated itineraries.

## Visitor planner

Link to `/events/itinerary/index.php` for the public planner. Visitors can
start from scratch or open a published suggested itinerary, then add, remove,
or reorder up to 50 stops. Share links carry only ordered source IDs in a URL
fragment. Visitors can bookmark or share the link without an account. The
planner resolves the IDs against currently available sources.
