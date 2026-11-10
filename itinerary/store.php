<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/event-data.php';
require_once __DIR__ . '/../business/store.php';

function itinerary_install(mysqli $db): void
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS trip_forge_itineraries (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(160) NOT NULL,
            description TEXT NOT NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS trip_forge_itinerary_items (
            itinerary_id INT UNSIGNED NOT NULL,
            position SMALLINT UNSIGNED NOT NULL,
            source_type ENUM('business', 'event') NOT NULL,
            source_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (itinerary_id, position),
            FOREIGN KEY (itinerary_id) REFERENCES trip_forge_itineraries (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($statements as $sql) {
        if (!$db->query($sql)) {
            throw new RuntimeException('Itinerary setup failed: ' . $db->error);
        }
    }
}

function itinerary_query(mysqli $db, string $sql)
{
    $result = $db->query($sql);
    if ($result === false) {
        throw new RuntimeException('Itinerary query failed: ' . $db->error);
    }
    return $result;
}

function itinerary_event_visibility(): string
{
    return 'e.is_published = 1 AND e.is_canceled = 0 AND COALESCE(e.is_recurring_parent, 0) = 0';
}

function itinerary_choices(mysqli $db): array
{
    $choices = ['business' => [], 'event' => []];
    foreach (business_list($db, true) as $business) {
        $choices['business'][$business['id']] = $business['name'];
    }
    $events = itinerary_query($db, 'SELECT e.id, e.title, e.start_datetime FROM events e
        WHERE ' . itinerary_event_visibility() . '
        AND COALESCE(e.end_datetime, e.start_datetime) >= NOW() ORDER BY e.start_datetime, e.id');
    while ($event = $events->fetch_assoc()) {
        $choices['event'][(int) $event['id']] = $event['title'] . ' · ' . $event['start_datetime'];
    }
    return $choices;
}

function itinerary_list(mysqli $db, bool $publicOnly, int $id = 0): array
{
    $sql = 'SELECT id, title, description, is_published FROM trip_forge_itineraries WHERE 1=1';
    if ($publicOnly) {
        $sql .= ' AND is_published = 1';
    }
    if ($id > 0) {
        $sql .= ' AND id = ' . $id;
    }
    $result = itinerary_query($db, $sql . ' ORDER BY title, id');
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        if ($publicOnly) {
            unset($row['is_published']);
        }
        $rows[] = $row;
    }
    return $rows;
}

function itinerary_items(mysqli $db, int $id, bool $publicOnly): array
{
    $result = itinerary_query($db, "SELECT i.source_type, i.source_id, i.position,
        b.name, b.category, b.description, b.address, b.phone, b.website,
        e.title, e.slug, e.start_datetime, e.end_datetime, e.all_day, e.location
        FROM trip_forge_itinerary_items i
        LEFT JOIN business_directory_listings b
          ON i.source_type = 'business' AND i.source_id = b.id AND b.is_published = 1
        LEFT JOIN events e ON i.source_type = 'event' AND i.source_id = e.id AND " . itinerary_event_visibility() . "
        WHERE i.itinerary_id = {$id} ORDER BY i.position");
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $sourceId = (int) $row['source_id'];
        $item = ['type' => $row['source_type'], 'id' => $sourceId, 'position' => (int) $row['position']];
        if ($row['name'] !== null) {
            $item['business'] = array_intersect_key($row, array_flip(['name', 'category', 'description', 'address', 'phone', 'website']));
        } elseif ($row['title'] !== null) {
            $item['event'] = [
                'title' => $row['title'], 'start' => $row['start_datetime'], 'end' => $row['end_datetime'],
                'allDay' => (bool) $row['all_day'], 'location' => $row['location'],
                'url' => eventforge_build_event_detail_url($sourceId, $row['slug']),
            ];
        } elseif ($publicOnly) {
            continue;
        }
        $items[] = $item;
    }
    return $items;
}

function itinerary_save(mysqli $db, int $id, array $input): int
{
    $title = $input['title'] ?? '';
    $description = $input['description'] ?? '';
    $selected = $input['stops'] ?? [];
    if (!is_string($title) || trim($title) === '' || preg_match('/\A.{1,160}\z/us', trim($title)) !== 1
        || !is_string($description) || preg_match('/\A.{0,10000}\z/us', $description) !== 1
        || !is_array($selected) || count($selected) > 50) {
        throw new InvalidArgumentException('Enter a title and up to 50 stops. Check the field lengths.');
    }
    $title = trim($title);
    $description = trim($description);
    $published = (int) (($input['is_published'] ?? null) === '1');
    $choices = itinerary_choices($db);

    if (!$db->begin_transaction()) {
        throw new RuntimeException('Could not start itinerary transaction: ' . $db->error);
    }
    try {
        $retained = [];
        if ($id > 0) {
            if (!itinerary_query($db, 'SELECT id FROM trip_forge_itineraries WHERE id = ' . $id . ' FOR UPDATE')->fetch_assoc()) {
                throw new InvalidArgumentException('This itinerary no longer exists.');
            }
            foreach (itinerary_items($db, $id, false) as $item) {
                $retained[$item['type'] . ':' . $item['id']] = true;
            }
        }
        $stops = [];
        foreach ($selected as $value) {
            if ($value === '') {
                continue;
            }
            if (!is_string($value) || !preg_match('/^(business|event):([1-9][0-9]{0,9})$/D', $value, $match)
                || (!isset($choices[$match[1]][$match[2]]) && !isset($retained[$value]))) {
                throw new InvalidArgumentException('Choose a listed business or event for each stop.');
            }
            $stops[] = [$match[1], (int) $match[2]];
        }
        if ($published && !$stops) {
            throw new InvalidArgumentException('Add a stop before publishing.');
        }

        $stmt = $id > 0
            ? $db->prepare('UPDATE trip_forge_itineraries SET title=?, description=?, is_published=? WHERE id=?')
            : $db->prepare('INSERT INTO trip_forge_itineraries (title, description, is_published) VALUES (?, ?, ?)');
        if (!$stmt) {
            throw new RuntimeException('Itinerary save preparation failed: ' . $db->error);
        }
        if ($id > 0) {
            $stmt->bind_param('ssii', $title, $description, $published, $id);
        } else {
            $stmt->bind_param('ssi', $title, $description, $published);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Itinerary save failed: ' . $stmt->error);
        }
        $id = $id ?: (int) $db->insert_id;
        itinerary_query($db, 'DELETE FROM trip_forge_itinerary_items WHERE itinerary_id = ' . $id);
        if ($stops) {
            $insert = $db->prepare('INSERT INTO trip_forge_itinerary_items
                (itinerary_id, position, source_type, source_id) VALUES (?, ?, ?, ?)');
            if (!$insert) {
                throw new RuntimeException('Itinerary stop preparation failed: ' . $db->error);
            }
            $insert->bind_param('iisi', $id, $position, $type, $sourceId);
            foreach ($stops as $index => [$type, $sourceId]) {
                $position = $index + 1;
                if (!$insert->execute()) {
                    throw new RuntimeException('Itinerary stop save failed: ' . $insert->error);
                }
            }
        }
        if (!$db->commit()) {
            throw new RuntimeException('Could not commit itinerary: ' . $db->error);
        }
        return $id;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
