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
    button, select, input, textarea { font: inherit; max-width: 100%; padding: .35rem; }
    label { display: block; }
    input, textarea { width: 100%; box-sizing: border-box; }
    .actions { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .25rem; }
  </style>
  <script src="planner.js" defer></script>
</head>
<body>
<main>
  <h1>Trip Planner</h1>
  <p>Build your trip below. Sign in when you want to save it.</p>
  <button type="button" data-logout hidden>Sign out</button>
  <section data-my-trips hidden>
    <h2>My saved trips</h2>
    <ul></ul>
    <p data-delete-status role="status"></p>
  </section>
  <p><a href="?new=1">Start a new trip</a></p>
  <section data-suggestions hidden><h2>Suggested itineraries</h2><ul></ul></section>
  <section data-planner>
    <h2 data-heading>Build your trip</h2>
    <p data-status role="status">Loading businesses and events…</p>
    <ol data-plan></ol>
    <div data-controls hidden>
      <label for="trip-title">Trip name</label>
      <input id="trip-title" maxlength="160" value="My trip">
      <label for="trip-description">Trip notes</label>
      <textarea id="trip-description" maxlength="10000" rows="3"></textarea>
      <label for="source">Add a business or event</label>
      <select id="source"><option value="">Choose a stop</option></select>
      <button type="button" data-add>Add</button>
      <button type="button" data-save hidden>Save trip</button>
      <button type="button" data-save-copy hidden>Save as a new trip</button>
      <p data-guest><a data-account href="account.php">Sign in to save</a> or
        <a data-account href="account.php?mode=register">create an account</a>.</p>
      <label for="share">Share your trip</label>
      <input id="share" readonly>
    </div>
    <noscript><p>Enable JavaScript to build and share your own itinerary.</p></noscript>
  </section>
</main>
</body>
</html>
