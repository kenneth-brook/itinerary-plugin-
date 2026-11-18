<?php
declare(strict_types=1);

require_once __DIR__ . '/../business/cors.php';
forge_api_cors(['GET', 'POST'], true);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/visitor.php';
trip_visitor_start();
try {
    $userId = trip_visitor_id();
    if (!$userId) {
        http_response_code(401);
        throw new InvalidArgumentException('Sign in to save your itinerary.');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        http_response_code(405);
        throw new InvalidArgumentException('Method not allowed.');
    }
    $db = eventforge_bootstrap_connection();
    if (!$db) {
        throw new RuntimeException('Database unavailable.');
    }
    if ($method === 'GET') {
        $id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($id === false) {
            throw new InvalidArgumentException('Invalid itinerary ID.');
        }
        $plans = itinerary_list($db, false, $id, $userId);
        $payload = ['itineraries' => $plans];
        if ($id > 0) {
            if (!$plans) {
                http_response_code(404);
                throw new InvalidArgumentException('Itinerary not found.');
            }
            $plan = $plans[0];
            $plan['stops'] = itinerary_items($db, $id, false);
            $payload = ['itinerary' => $plan];
        }
    } else {
        $body = file_get_contents('php://input', false, null, 0, 100001);
        if ($body === false || strlen($body) > 100000) {
            http_response_code(413);
            throw new InvalidArgumentException('The request is too large.');
        }
        $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !trip_csrf_valid($input['csrf_token'] ?? null)) {
            http_response_code(403);
            throw new InvalidArgumentException('The form expired. Reload the planner and try again.');
        }
        $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($id === false) {
            throw new InvalidArgumentException('Invalid itinerary ID.');
        }
        if (($input['action'] ?? '') === 'delete') {
            if ($id <= 0) {
                throw new InvalidArgumentException('Choose a saved itinerary.');
            }
            itinerary_query($db, "DELETE FROM trip_forge_itineraries WHERE id={$id} AND owner_user_id={$userId}");
            if (!$db->affected_rows) {
                http_response_code(404);
                throw new InvalidArgumentException('Itinerary not found.');
            }
            $payload = ['deleted' => true];
        } elseif (($input['action'] ?? '') === 'save') {
            if ($id > 0 && !itinerary_list($db, false, $id, $userId)) {
                http_response_code(404);
                throw new InvalidArgumentException('Itinerary not found.');
            }
            $payload = ['id' => itinerary_save($db, $id, $input, $userId)];
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
    }
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (JsonException $e) {
    http_response_code(400);
    echo '{"error":"Invalid JSON request."}';
} catch (InvalidArgumentException $e) {
    if (http_response_code() < 400) {
        http_response_code(422);
    }
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Saved trip API: ' . $e->getMessage());
    http_response_code(503);
    echo '{"error":"Saved itineraries are unavailable."}';
}
