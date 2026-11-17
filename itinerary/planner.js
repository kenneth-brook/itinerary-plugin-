const planner = document.querySelector('[data-planner]');
const list = planner.querySelector('[data-plan]');
const source = planner.querySelector('#source');
const share = planner.querySelector('#share');
const status = planner.querySelector('[data-status]');
const add = planner.querySelector('[data-add]');
const title = planner.querySelector('#trip-title');
const description = planner.querySelector('#trip-description');
const userId = Number(planner.dataset.user);
let savedId = Number(planner.dataset.saved);
const labels = new Map(Array.from(source.options, option => [option.value, option.textContent]));
const url = new URL(location.href);
const storageKey = 'trip-forge-draft:' + url.pathname;
const validStop = value => typeof value === 'string' && /^(event|business):[1-9][0-9]{0,9}$/.test(value);
let stops = JSON.parse(planner.dataset.initial);
let storageAvailable = true;
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
  const response = await fetch('saved-api.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...payload, csrf_token: planner.dataset.token }),
  });
  const result = await response.json();
  if (!response.ok) throw new Error(result.error || 'Could not complete the request.');
  return result;
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
    status.textContent = 'Trip saved. Find it under My saved trips when you reopen the planner.';
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
document.querySelectorAll('[data-delete]').forEach(button => {
  button.addEventListener('click', async () => {
    if (!confirm('Delete this saved trip?')) return;
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
});
share.addEventListener('focus', () => share.select());
window.addEventListener('hashchange', () => location.reload());
render();
planner.querySelector('[data-controls]').hidden = false;
