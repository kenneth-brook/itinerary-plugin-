const planner = document.querySelector('[data-planner]');
const list = planner.querySelector('[data-plan]');
const source = planner.querySelector('#source');
const share = planner.querySelector('#share');
const status = planner.querySelector('[data-status]');
const add = planner.querySelector('[data-add]');
const labels = new Map(Array.from(source.options, option => [option.value, option.textContent]));
const initial = JSON.parse(planner.dataset.initial);
const shared = new URLSearchParams(location.hash.slice(1)).get('stops');
const stops = shared === null ? initial : shared.split(',').slice(0, 50)
  .filter(value => /^(event|business):[1-9][0-9]{0,9}$/.test(value));

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
  const url = new URL(location.href);
  url.hash = new URLSearchParams({ stops: stops.join(',') }).toString();
  share.value = url.href;
  add.disabled = stops.length >= 50;
  if (focusIndex >= 0) list.children[focusIndex].focus();
}

add.addEventListener('click', () => {
  if (source.value === '') {
    source.focus();
    return;
  }
  stops.push(source.value);
  status.textContent = 'Stop added.';
  source.value = '';
  render(stops.length - 1);
});
share.addEventListener('focus', () => share.select());
window.addEventListener('hashchange', () => location.reload());
render();
planner.querySelector('[data-controls]').hidden = false;
