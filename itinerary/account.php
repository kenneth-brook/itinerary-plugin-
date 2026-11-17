<?php
declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/visitor.php';
trip_visitor_start();
$error = '';
$returnId = filter_var($_GET['saved'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) ?: 0;
$mode = ($_GET['mode'] ?? '') === 'register' ? 'register' : 'login';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!trip_csrf_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            throw new InvalidArgumentException('The form expired. Please try again.');
        }
        if (($_POST['action'] ?? '') === 'logout') {
            $_SESSION = [];
            session_destroy();
        } else {
            if (!is_string($_POST['email'] ?? null) || !is_string($_POST['password'] ?? null)) {
                throw new InvalidArgumentException('Enter your email and password.');
            }
            $db = eventforge_bootstrap_connection();
            if (!$db) {
                throw new RuntimeException('Database unavailable.');
            }
            trip_authenticate($db, $mode, $_POST['email'], $_POST['password']);
        }
        header('Location: index.php' . ($returnId && trip_visitor_id() ? '?saved=' . $returnId : '?resume=1'), true, 303);
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Trip account: ' . $e->getMessage());
        http_response_code(503);
        $error = 'Account services are unavailable. Please try again later.';
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $mode === 'register' ? 'Create account' : 'Sign in' ?></title>
<style>body{font:1rem/1.5 system-ui,sans-serif;max-width:30rem;margin:2rem auto;padding:1rem}label{display:block;margin-top:1rem}input,button{font:inherit;padding:.5rem}input{box-sizing:border-box;width:100%}button{margin-top:1rem}</style>
</head><body>
<h1><?= $mode === 'register' ? 'Create account' : 'Sign in' ?></h1>
<p>Your trip will be waiting when you return to the planner.</p>
<?php if ($error !== ''): ?><p role="alert"><?= trip_escape($error) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= trip_escape($_SESSION['trip_csrf']) ?>">
<label for="email">Email</label><input id="email" type="email" name="email" maxlength="254" autocomplete="email" required value="<?= trip_escape(is_string($_POST['email'] ?? null) ? $_POST['email'] : '') ?>">
<label for="password">Password</label><input id="password" type="password" name="password" autocomplete="<?= $mode === 'register' ? 'new-password' : 'current-password' ?>"<?= $mode === 'register' ? ' minlength="12"' : '' ?> required>
<?php if ($mode === 'register'): ?><p>Use at least 12 characters. The password may contain up to 72 bytes.</p><?php endif; ?>
<button><?= $mode === 'register' ? 'Create account' : 'Sign in' ?></button>
</form>
<p><a href="account.php?mode=<?= $mode === 'register' ? 'login' : 'register' ?><?= $returnId ? '&amp;saved=' . $returnId : '' ?>"><?= $mode === 'register' ? 'Sign in to an existing account' : 'Create an account' ?></a></p>
<a href="index.php?resume=1">Return to your trip</a>
</body></html>
