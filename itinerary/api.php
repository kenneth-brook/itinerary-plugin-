<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo '{"error":"Method not allowed."}';
    exit;
}

$id = 0;
if (isset($_GET['id'])) {
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        http_response_code(400);
        echo '{"error":"Invalid itinerary ID."}';
        exit;
    }
}

try {
    require_once __DIR__ . '/store.php';
    $db = eventforge_bootstrap_connection();
    if (!$db) {
        throw new RuntimeException('Event Forge database is unavailable.');
    }

    $itineraries = itinerary_list($db, true, $id);
    if ($id > 0) {
        if (!$itineraries) {
            http_response_code(404);
            echo '{"error":"Itinerary not found."}';
            exit;
        }
        $itinerary = $itineraries[0];
        $itinerary['stops'] = itinerary_items($db, $id, true);
        $payload = ['itinerary' => $itinerary];
    } else {
        $payload = ['itineraries' => $itineraries];
    }
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Itinerary API: ' . $e->getMessage());
    http_response_code(503);
    echo '{"error":"Itineraries unavailable."}';
}
