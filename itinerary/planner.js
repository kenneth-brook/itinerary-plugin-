const planner = document.querySelector('[data-planner]');
const list = planner.querySelector('[data-plan]');
const source = planner.querySelector('#source');
const share = planner.querySelector('#share');
const status = planner.querySelector('[data-status]');
const add = planner.querySelector('[data-add]');
const title = planner.querySelector('#trip-title');
const description = planner.querySelector('#trip-description');
const url = new URL(location.href);
const storageKey = 'trip-forge-draft:' + url.pathname;
const validStop = value => typeof value === 'string' && /^(event|business):[1-9][0-9]{0,9}$/.test(value);
const labels = new Map();
let userId = 0;
let csrfToken = '';
let savedId = 0;
let stops = [];
let storageAvailable = true;

function restoreDraft() {
  const shared = new URLSearchParams(location.hash.slice(1)).get('stops');
  try {
    if (url.searchParams.has('new')) sessionStorage.removeItem(storageKey);
    const draft = JSON.parse(sessionStorage.getItem(storageKey));
    if (shared !== null) {
      stops = shared.split(',').filter(validStop).slice(0, 50);
      savedId = 0;
    } else if (!url.searchParams.has('id') && draft && Array.isArray(draft.stops)
      && (url.searchParams.has('resume') || Number(draft.savedId) === savedId)) {
      stops = draft.stops.filter(validStop).slice(0, 50);
      title.value = typeof draft.title === 'string' ? draft.title : 'My trip';
      description.value = typeof draft.description === 'string' ? draft.description : '';
      savedId = Number(draft.userId) === userId ? Number(draft.savedId) || 0 : 0;
    }
  } catch (error) {
    storageAvailable = false;
  }
}

async function fetchJSON(path, options = {}) {
  const response = await fetch(path, { credentials: 'same-origin', ...options });
  let result;
  try { result = await response.json(); }
  catch (error) { throw new Error('The service could not complete the request. Please try again.'); }
  if (!response.ok) throw new Error(result.error || 'Could not complete the request.');
  return result;
}

function selectedId(parameter) {
  const value = url.searchParams.get(parameter);
  if (value === null) return 0;
  if (!/^[1-9][0-9]{0,9}$/.test(value)) throw new Error('Invalid itinerary ID.');
  return Number(value);
}

function addChoice(group, value, label) {
  labels.set(value, label);
  const option = document.createElement('option');
  option.value = value;
  option.textContent = label;
  group.append(option);
}

async function refreshSavedTrips() {
  if (!userId) return;
  const { itineraries } = await fetchJSON('saved-api.php');
  const section = document.querySelector('[data-my-trips]');
  const items = section.querySelector('ul');
  items.replaceChildren();
  itineraries.forEach(trip => {
    const item = document.createElement('li');
    const link = document.createElement('a');
    link.href = '?saved=' + trip.id;
    link.textContent = trip.title;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.dataset.delete = trip.id;
    remove.textContent = 'Delete ' + trip.title;
    item.append(link, ' ', remove);
    items.append(item);
  });
  section.hidden = itineraries.length === 0;
}

async function initialize() {
  const requestedSaved = selectedId('saved');
  const templateId = selectedId('id');
  const [businesses, events, account] = await Promise.all([
    fetchJSON('../business/api.php'),
    fetchJSON('../api.php?display=calendar&keep_current_month=0'),
    fetchJSON('account-api.php'),
  ]);
  userId = account.user_id;
  csrfToken = account.csrf_token;
  document.querySelector('[data-logout]').hidden = !userId;
  planner.querySelector('[data-guest]').hidden = Boolean(userId);
  planner.querySelectorAll('[data-save], [data-save-copy]').forEach(button => { button.hidden = !userId; });
  const businessGroup = document.createElement('optgroup');
  businessGroup.label = 'Businesses';
  businesses.businesses.forEach(business => addChoice(businessGroup, 'business:' + business.id, business.name));
  const eventGroup = document.createElement('optgroup');
  eventGroup.label = 'Events';
  events.events.forEach(event => {
    const value = 'event:' + event.id;
    if (!event.extendedProps?.isCanceled && !labels.has(value)) {
      addChoice(eventGroup, value, event.title + ' · ' + event.start);
    }
  });
  source.append(businessGroup, eventGroup);
  let plan = null;
  if (requestedSaved && userId) {
    plan = (await fetchJSON('saved-api.php?id=' + requestedSaved)).itinerary;
    savedId = requestedSaved;
  } else if (templateId) {
    plan = (await fetchJSON('api.php?id=' + templateId)).itinerary;
  }
  if (plan) {
    title.value = plan.title;
    description.value = plan.description;
    planner.querySelector('[data-heading]').textContent = plan.title;
    stops = plan.stops.map(stop => stop.type + ':' + stop.id);
    plan.stops.forEach(stop => {
      const value = stop.type + ':' + stop.id;
      if (!labels.has(value)) addChoice(source, value,
        stop.business?.name ?? stop.event?.title ?? 'Unavailable stop');
    });
  }
  restoreDraft();
  render();
  planner.querySelector('[data-controls]').hidden = false;
  status.textContent = '';
  if (!storageAvailable) status.textContent = 'Enable site storage to retain this draft through sign-in.';
  if (requestedSaved && !userId) {
    planner.querySelectorAll('[data-account]').forEach(link => {
      const destination = new URL(link.href);
      destination.searchParams.set('saved', requestedSaved);
      link.href = destination.href;
    });
    status.textContent = 'Sign in to open your saved trip.';
  }
  await refreshSavedTrips();
  const suggestions = await fetchJSON('api.php');
  const section = document.querySelector('[data-suggestions]');
  suggestions.itineraries.forEach(trip => {
    const item = document.createElement('li');
    const link = document.createElement('a');
    link.href = '?id=' + trip.id;
    link.textContent = trip.title;
    item.append(link);
    section.querySelector('ul').append(item);
  });
  section.hidden = suggestions.itineraries.length === 0;
}

function remember() {
  try {
    sessionStorage.setItem(storageKey, JSON.stringify({ stops, title: title.value,
      description: description.value, savedId, userId }));
    storageAvailable = true;
  } catch (error) {
    storageAvailable = false;
    status.textContent = 'Your browser cannot retain this draft between pages. Enable site storage before signing in.';
  }
}

function action(label, disabled, onClick) {
  const button = document.createElement('button');
  button.type = 'button';
  button.textContent = label;
  button.disabled = disabled;
  button.addEventListener('click', onClick);
  return button;
}

function render(focusIndex = -1) {
  list.replaceChildren();
  stops.forEach((value, index) => {
    const row = document.createElement('li');
    row.tabIndex = -1;
    row.textContent = labels.get(value) ?? 'This stop is no longer available.';
    const buttons = document.createElement('div');
    buttons.className = 'actions';
    function move(nextIndex) {
      [stops[index], stops[nextIndex]] = [stops[nextIndex], stops[index]];
      status.textContent = 'Stop moved to position ' + (nextIndex + 1) + '.';
      render(nextIndex);
    }
    buttons.append(
      action('Move up', index === 0, () => move(index - 1)),
      action('Move down', index === stops.length - 1, () => move(index + 1)),
      action('Remove', false, () => {
        stops.splice(index, 1);
        status.textContent = 'Stop removed.';
        render(Math.min(index, stops.length - 1));
        if (!stops.length) source.focus();
      }),
    );
    row.append(buttons);
    list.append(row);
  });
  const link = new URL(location.href);
  link.search = '';
  link.hash = new URLSearchParams({ stops: stops.join(',') }).toString();
  share.value = link.href;
  add.disabled = stops.length >= 50;
  remember();
  if (focusIndex >= 0) list.children[focusIndex].focus();
}

async function request(payload) {
  return fetchJSON('saved-api.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...payload, csrf_token: csrfToken }),
  });
}

let saving = false;
async function save(copy) {
  if (saving) return;
  saving = true;
  const buttons = planner.querySelectorAll('[data-save], [data-save-copy]');
  buttons.forEach(button => { button.disabled = true; });
  remember();
  try {
    const result = await request({ action: 'save', id: copy ? 0 : savedId,
      title: title.value, description: description.value, stops });
    savedId = result.id;
    remember();
    // Keep the completed save visible without submitting the same create request again.
    const destination = new URL(location.href);
    destination.search = '?saved=' + savedId;
    destination.hash = '';
    history.replaceState(null, '', destination);
    status.textContent = 'Trip saved.';
    await refreshSavedTrips();
  } catch (error) {
    status.textContent = error.message;
  } finally {
    saving = false;
    buttons.forEach(button => { button.disabled = false; });
  }
}

add.addEventListener('click', () => {
  if (source.value === '') { source.focus(); return; }
  stops.push(source.value);
  status.textContent = 'Stop added.';
  source.value = '';
  render(stops.length - 1);
});
title.addEventListener('input', remember);
description.addEventListener('input', remember);
planner.querySelectorAll('[data-account]').forEach(link => {
  link.addEventListener('click', event => {
    remember();
    if (!storageAvailable) event.preventDefault();
  });
});
planner.querySelector('[data-save]')?.addEventListener('click', () => save(false));
planner.querySelector('[data-save-copy]')?.addEventListener('click', () => save(true));
document.querySelector('[data-my-trips]').addEventListener('click', async event => {
  const button = event.target.closest('[data-delete]');
  if (!button || !confirm('Delete this saved trip?')) return;
  button.disabled = true;
  try {
    await request({ action: 'delete', id: Number(button.dataset.delete) });
    button.closest('li').remove();
    if (Number(button.dataset.delete) === savedId) { savedId = 0; remember(); }
    document.querySelector('[data-delete-status]').textContent = 'Trip deleted.';
  } catch (error) {
    document.querySelector('[data-delete-status]').textContent = error.message;
    button.disabled = false;
  }
});
document.querySelector('[data-logout]').addEventListener('click', async event => {
  event.target.disabled = true;
  remember();
  try {
    await fetchJSON('account-api.php', { method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'logout', csrf_token: csrfToken }),
    });
    location.href = 'index.php?resume=1';
  } catch (error) {
    status.textContent = error.message;
    event.target.disabled = false;
  }
});
share.addEventListener('focus', () => share.select());
window.addEventListener('hashchange', () => location.reload());
initialize().catch(error => { status.textContent = error.message; });
