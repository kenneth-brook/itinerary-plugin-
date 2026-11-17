<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/event-data.php';
require_once __DIR__ . '/../business/store.php';

function itinerary_install(mysqli $db): void
{
    $lock = 'trip_' . substr(hash('sha256', (string) itinerary_query($db, 'SELECT DATABASE()')->fetch_row()[0]), 0, 40);
    $result = itinerary_query($db, "SELECT GET_LOCK('{$lock}', 10)");
    if ((int) $result->fetch_row()[0] !== 1) {
        throw new RuntimeException('Another itinerary upgrade is in progress. Try again shortly.');
    }
    try {
        itinerary_apply_schema($db);
    } finally {
        itinerary_query($db, "SELECT RELEASE_LOCK('{$lock}')");
    }
}

function itinerary_apply_schema(mysqli $db): void
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS trip_forge_users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(254) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            UNIQUE KEY trip_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS trip_forge_login_attempts (
            bucket CHAR(64) NOT NULL PRIMARY KEY,
            attempts INT UNSIGNED NOT NULL DEFAULT 1,
            started_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS trip_forge_itineraries (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(160) NOT NULL,
            description TEXT NOT NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            owner_user_id INT UNSIGNED DEFAULT NULL,
            KEY trip_owner (owner_user_id, id)
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
    $columns = itinerary_query($db, "SELECT column_name FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'trip_forge_itineraries'");
    $hasOwner = false;
    while ($column = $columns->fetch_row()) {
        $hasOwner = $hasOwner || $column[0] === 'owner_user_id';
    }
    if (!$hasOwner) {
        itinerary_query($db, 'ALTER TABLE trip_forge_itineraries
            ADD owner_user_id INT UNSIGNED DEFAULT NULL, ADD KEY trip_owner (owner_user_id, id)');
    }
}

function itinerary_schema_ready(mysqli $db): bool
{
    $result = itinerary_query($db, "SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'trip_forge_itineraries' AND column_name = 'owner_user_id'");
    $hasOwner = (bool) $result->fetch_row();
    $tables = itinerary_query($db, "SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name IN
        ('trip_forge_users', 'trip_forge_login_attempts', 'trip_forge_itineraries', 'trip_forge_itinerary_items')");
    return $hasOwner && (int) $tables->fetch_row()[0] === 4;
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

function itinerary_list(mysqli $db, bool $publicOnly, int $id = 0, int $userId = 0): array
{
    $sql = 'SELECT id, title, description, is_published FROM trip_forge_itineraries WHERE ';
    $sql .= $userId > 0 ? 'owner_user_id = ' . $userId : 'owner_user_id IS NULL';
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
        b.name, b.category, b.subcategory, b.description, b.address, b.city, b.state, b.zip,
        b.phone, b.website, b.email, b.is_member, b.sponsor_level, b.geolocation, b.qrcode, b.photo, b.logo, b.audio,
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
            $item['business'] = business_public_record(array_intersect_key($row, business_fields()));
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

function itinerary_save(mysqli $db, int $id, array $input, int $userId = 0): int
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
    $published = (int) ($userId === 0 && ($input['is_published'] ?? null) === '1');
    $ownerClause = $userId > 0 ? 'owner_user_id = ' . $userId : 'owner_user_id IS NULL';
    $choices = itinerary_choices($db);

    if (!$db->begin_transaction()) {
        throw new RuntimeException('Could not start itinerary transaction: ' . $db->error);
    }
    try {
        $retained = [];
        if ($id > 0) {
            if (!itinerary_query($db, 'SELECT id FROM trip_forge_itineraries WHERE id = ' . $id . ' AND ' . $ownerClause . ' FOR UPDATE')->fetch_assoc()) {
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
            ? $db->prepare('UPDATE trip_forge_itineraries SET title=?, description=?, is_published=? WHERE id=? AND ' . $ownerClause)
            : $db->prepare('INSERT INTO trip_forge_itineraries (title, description, is_published, owner_user_id) VALUES (?, ?, ?, ?)');
        if (!$stmt) {
            throw new RuntimeException('Itinerary save preparation failed: ' . $db->error);
        }
        if ($id > 0) {
            $stmt->bind_param('ssii', $title, $description, $published, $id);
        } else {
            $owner = $userId > 0 ? $userId : null;
            $stmt->bind_param('ssii', $title, $description, $published, $owner);
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
