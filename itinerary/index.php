<?php
declare(strict_types=1);

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
    $plans = itinerary_list($db, true);
    $plan = array_column($plans, null, 'id')[$id] ?? null;
    if ($id > 0 && $plan === null) {
        http_response_code(404);
        exit('Itinerary not found.');
    }
    $choices = itinerary_choices($db);
    $initial = [];
    if ($plan !== null) {
        foreach (itinerary_items($db, $id, true) as $stop) {
            $initial[] = $stop['type'] . ':' . $stop['id'];
            $choices[$stop['type']][$stop['id']] = $choices[$stop['type']][$stop['id']]
                ?? $stop['business']['name'] ?? ($stop['event']['title'] . ' · ' . $stop['event']['start']);
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
  <section data-planner data-initial="<?= trip_page_escape(json_encode($initial, JSON_THROW_ON_ERROR)) ?>">
    <h2><?= trip_page_escape($plan['title'] ?? 'Build your trip') ?></h2>
    <?php if (!empty($plan['description'])): ?><p><?= trip_page_escape($plan['description']) ?></p><?php endif; ?>
    <ol data-plan>
      <?php foreach ($initial as $value): ?>
        <?php [$type, $sourceId] = explode(':', $value, 2); ?>
        <li><?= trip_page_escape($choices[$type][$sourceId]) ?></li>
      <?php endforeach; ?>
    </ol>
    <div data-controls hidden>
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
      <label for="share">Share or bookmark your trip</label>
      <input id="share" readonly>
    </div>
    <noscript><p>Enable JavaScript to build and share your own itinerary.</p></noscript>
  </section>
</main>
</body>
</html>
