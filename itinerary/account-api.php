<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/visitor.php';
trip_visitor_start();
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        http_response_code(405);
        throw new InvalidArgumentException('Method not allowed.');
    }
    if ($method === 'POST') {
        $body = file_get_contents('php://input', false, null, 0, 10001);
        if ($body === false || strlen($body) > 10000) {
            http_response_code(413);
            throw new InvalidArgumentException('The request is too large.');
        }
        $input = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !trip_csrf_valid($input['csrf_token'] ?? null)) {
            http_response_code(403);
            throw new InvalidArgumentException('The form expired. Please try again.');
        }
        $action = $input['action'] ?? '';
        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['trip_csrf'] = bin2hex(random_bytes(32));
        } elseif (in_array($action, ['register', 'login'], true)) {
            if (!is_string($input['email'] ?? null) || !is_string($input['password'] ?? null)) {
                throw new InvalidArgumentException('Enter your email and password.');
            }
            $db = eventforge_bootstrap_connection();
            if (!$db) {
                throw new RuntimeException('Database unavailable.');
            }
            trip_authenticate($db, $action, $input['email'], $input['password']);
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
    }
    echo json_encode(['user_id' => trip_visitor_id(), 'csrf_token' => $_SESSION['trip_csrf']], JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(400);
    echo '{"error":"Invalid JSON request."}';
} catch (InvalidArgumentException $e) {
    if (http_response_code() < 400) {
        http_response_code(422);
    }
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Trip account API: ' . $e->getMessage());
    http_response_code(503);
    echo '{"error":"Account services are unavailable."}';
}
