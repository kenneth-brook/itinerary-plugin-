<?php
declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/visitor.php';
trip_visitor_start();
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($id === false) {
    http_response_code(400);
    exit('Invalid itinerary ID.');
}

try {
    require_once __DIR__ . '/store.php';
    $db = eventforge_bootstrap_connection();
    if (!$db) {
        throw new RuntimeException('Event Forge database is unavailable.');
    }
    $userId = trip_visitor_id();
    $savedId = filter_var($_GET['saved'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($savedId === false) {
        http_response_code(400);
        exit('Invalid itinerary ID.');
    }
    if ($savedId > 0 && !$userId) {
        header('Location: account.php');
        exit;
    }
    $myPlans = $userId ? itinerary_list($db, false, 0, $userId) : [];
    $plans = itinerary_list($db, true);
    $plan = array_column($plans, null, 'id')[$id] ?? null;
    if ($id > 0 && $plan === null) {
        http_response_code(404);
        exit('Itinerary not found.');
    }
    if ($savedId > 0) {
        $plan = itinerary_list($db, false, $savedId, $userId)[0] ?? null;
        if ($plan === null) {
            http_response_code(404);
            exit('Itinerary not found.');
        }
    }
    $choices = itinerary_choices($db);
    $initial = [];
    if ($plan !== null) {
        foreach (itinerary_items($db, (int) $plan['id'], false) as $stop) {
            $initial[] = $stop['type'] . ':' . $stop['id'];
            $choices[$stop['type']][$stop['id']] = $choices[$stop['type']][$stop['id']]
                ?? $stop['business']['name'] ?? $stop['event']['title'] ?? 'Unavailable stop';
        }
    }
} catch (Throwable $e) {
    error_log('Itinerary builder: ' . $e->getMessage());
    http_response_code(503);
    exit('The itinerary builder is unavailable.');
}

function trip_page_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Trip Planner</title>
  <style>
    :root { font: 1rem/1.5 system-ui, sans-serif; background: #f3f6f9; color: #182635; }
    main { max-width: 46rem; margin: auto; padding: 1rem; }
    section { background: white; border: 1px solid #c3d0db; border-radius: .4rem; margin-block: 1rem; padding: 1rem; }
    li { margin-block: .6rem; }
    button, select, input { font: inherit; max-width: 100%; padding: .35rem; }
    label { display: block; }
    input { width: 100%; box-sizing: border-box; }
    .actions { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .25rem; }
  </style>
  <script src="planner.js" defer></script>
</head>
<body>
<main>
  <h1>Trip Planner</h1>
  <p>Build your trip below. Sign in when you want to save it.</p>
  <?php if ($userId): ?>
    <form action="account.php" method="post">
      <input type="hidden" name="csrf_token" value="<?= trip_escape($_SESSION['trip_csrf']) ?>">
      <button name="action" value="logout">Sign out</button>
    </form>
    <?php if ($myPlans): ?>
      <section><h2>My saved trips</h2><ul>
      <?php foreach ($myPlans as $entry): ?>
        <li><a href="?saved=<?= $entry['id'] ?>"><?= trip_page_escape($entry['title']) ?></a>
          <button type="button" data-delete="<?= $entry['id'] ?>">Delete <?= trip_page_escape($entry['title']) ?></button></li>
      <?php endforeach; ?>
      </ul><p data-delete-status role="status"></p></section>
    <?php endif; ?>
  <?php endif; ?>
  <p><a href="?new=1">Start a new trip</a></p>
  <?php if ($plans): ?>
    <section>
      <h2>Suggested itineraries</h2>
      <ul>
        <?php foreach ($plans as $entry): ?>
          <li><a href="?id=<?= $entry['id'] ?>"><?= trip_page_escape($entry['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($plan !== null): ?><a href="index.php">Start a blank trip</a><?php endif; ?>
    </section>
  <?php endif; ?>
  <section data-planner data-user="<?= $userId ?>" data-saved="<?= $savedId ?>"
    data-token="<?= trip_escape($_SESSION['trip_csrf']) ?>" data-initial="<?= trip_page_escape(json_encode($initial, JSON_THROW_ON_ERROR)) ?>">
    <h2><?= trip_page_escape($plan['title'] ?? 'Build your trip') ?></h2>
    <?php if (!empty($plan['description'])): ?><p><?= trip_page_escape($plan['description']) ?></p><?php endif; ?>
    <ol data-plan>
      <?php foreach ($initial as $value): ?>
        <?php [$type, $sourceId] = explode(':', $value, 2); ?>
        <li><?= trip_page_escape($choices[$type][$sourceId]) ?></li>
      <?php endforeach; ?>
    </ol>
    <div data-controls hidden>
      <label for="trip-title">Trip name</label>
      <input id="trip-title" maxlength="160" value="<?= trip_page_escape($plan['title'] ?? 'My trip') ?>">
      <label for="trip-description">Trip notes</label>
      <textarea id="trip-description" maxlength="10000" rows="3"><?= trip_page_escape($plan['description'] ?? '') ?></textarea>
      <label for="source">Add a business or event</label>
      <select id="source">
        <option value="">Choose a stop</option>
        <?php foreach ($choices as $type => $options): ?>
          <optgroup label="<?= $type === 'business' ? 'Businesses' : 'Events' ?>">
            <?php foreach ($options as $sourceId => $name): ?>
              <option value="<?= $type ?>:<?= $sourceId ?>"><?= trip_page_escape($name) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
      <button type="button" data-add>Add</button>
      <p data-status role="status"></p>
      <?php if ($userId): ?>
        <button type="button" data-save>Save trip</button>
        <button type="button" data-save-copy>Save as a new trip</button>
      <?php else: ?>
        <p><a data-account href="account.php">Sign in to save</a> or
          <a data-account href="account.php?mode=register">create an account</a>.</p>
      <?php endif; ?>
      <label for="share">Share your trip</label>
      <input id="share" readonly>
    </div>
    <noscript><p>Enable JavaScript to build and share your own itinerary.</p></noscript>
  </section>
</main>
</body>
</html>
