/*
 * This file is part of the "typo3_letterbox" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

import Persistent from '@typo3/backend/storage/persistent.js';

const STORAGE_KEY = 'letterbox.listCollapsed';
const BOUND = 'letterboxBound';
const COLLAPSED = 'letterbox-inbox--collapsed';
const FRAGMENT_HEADER = 'X-Letterbox-Fragment';
const DELAY = 300;

const applyCollapsed = (inbox, collapsed) => {
  inbox.querySelector('#letterbox-list').hidden = collapsed;
  inbox.querySelector('.letterbox-rail').hidden = !collapsed;
  inbox.classList.toggle(COLLAPSED, collapsed);
};

const render = (inbox, collapsed) => {
  applyCollapsed(inbox, collapsed);
  // The focused button disappears with its container, so focus moves to the opposite one.
  inbox.querySelector(`[data-letterbox-list-toggle="${collapsed ? 'show' : 'hide'}"]`).focus();
};

const setCollapsed = (inbox, collapsed) => {
  render(inbox, collapsed);
  // When saving fails, the screen goes back so it matches what is stored.
  Persistent.set(STORAGE_KEY, collapsed ? 1 : 0).catch(() => render(inbox, !collapsed));
};

// Without JavaScript the buttons stay hidden and the list stays open.
const revealToggles = (root) => {
  root.querySelectorAll('[data-letterbox-list-toggle]').forEach((button) => {
    button.hidden = false;
  });
};

const region = (inbox, name) => inbox.querySelector(`[data-letterbox-region="${name}"]`);

/**
 * Only the list and detail routes of this module are fetched, never downloads, the preview frame or mutations.
 */
const moduleUrl = (inbox, href) => {
  let url;
  try {
    url = new URL(href, window.location.href);
  } catch {
    return null;
  }
  const paths = [inbox.dataset.letterboxIndex, inbox.dataset.letterboxShow].map((route) => new URL(route, window.location.href).pathname);

  return url.origin === window.location.origin && paths.includes(url.pathname) ? url : null;
};

// A row opens a mail, every other control answers with the region it sits in.
const fragmentFor = (element) => (element.closest('.letterbox-row') ? 'detail' : element.closest('[data-letterbox-region]')?.dataset.letterboxRegion ?? null);

const fetchFragment = async (url, name, signal) => {
  const response = await fetch(url, { headers: { [FRAGMENT_HEADER]: name }, credentials: 'same-origin', cache: 'no-store', signal });
  if (!response.ok || response.redirected || !(response.headers.get('Content-Type') ?? '').startsWith('text/html')) {
    throw new Error(`Fragment request failed with status ${response.status}`);
  }
  // Parsed into an inert document, so the preview iframe already carries its sandbox when it is connected.
  const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
  const node = parsed.body.querySelector(`[data-letterbox-region="${name}"]`);
  if (node === null) {
    throw new Error(`Fragment "${name}" missing in response`);
  }

  return document.adoptNode(node);
};

/**
 * Core tabs bind on page load only: v14 tab.js delegates clicks and keys but migrates and wraps tab bars on load,
 * v13 Bootstrap delegates clicks but binds arrow keys to the active tab.
 */
const initTabs = async (root) => {
  if (customElements.get('typo3-backend-tab-scroller') !== undefined) {
    const { Tab } = await import('@typo3/backend/tab.js');
    Tab.initialize(root);
    root.querySelectorAll('.nav-tabs').forEach((navTabs) => {
      const scroller = document.createElement('typo3-backend-tab-scroller');
      navTabs.before(scroller);
      scroller.append(navTabs);
    });
    return;
  }
  const { Tab } = await import('bootstrap');
  root.querySelectorAll('[data-bs-toggle="tab"].active').forEach((tab) => Tab.getOrCreateInstance(tab));
};

const status = (inbox) => inbox.querySelector('[data-letterbox-status]');

const announce = (inbox, message) => {
  const element = status(inbox);
  element.textContent = '';
  // Emptying first lets the same message be announced twice in a row.
  window.setTimeout(() => {
    element.textContent = message;
  }, 50);
};

const countMessage = (inbox) => {
  const labels = status(inbox).dataset;
  const count = region(inbox, 'list').dataset.letterboxCount;

  return count === '1' ? labels.letterboxCountOneLabel : labels.letterboxCountLabel.replace('%s', count);
};

const setRowSelected = (row, selected) => {
  row.closest('li')?.classList.toggle('active', selected);
  row.querySelector('small.text-truncate')?.classList.toggle('text-variant', !selected);
  if (selected) {
    row.setAttribute('aria-current', 'true');
  } else {
    row.removeAttribute('aria-current');
  }
};

/**
 * The detail request marked the mail read on the server, so the list follows without being fetched again.
 */
const markOpened = (inbox, id) => {
  const list = region(inbox, 'list');
  const rows = [...list.querySelectorAll('.letterbox-row')];
  rows.filter((row) => row.hasAttribute('aria-current') && row.dataset.letterboxId !== id).forEach((row) => setRowSelected(row, false));
  const row = rows.find((candidate) => candidate.dataset.letterboxId === id);
  if (row === undefined) {
    return;
  }
  setRowSelected(row, true);
  const dot = row.querySelector('.letterbox-unread');
  if (dot === null) {
    return;
  }
  // The dot is followed by its visually hidden "Unread" text.
  dot.nextElementSibling?.remove();
  dot.remove();
  const unreadCount = list.querySelector('[data-letterbox-count-of="unread"]');
  if (unreadCount !== null) {
    unreadCount.textContent = String(Math.max(0, Number(unreadCount.textContent) - 1));
  }
};

/**
 * List links keep the selected mail, its tab and the preview width, like the server builds them. Each route has
 * its own token, so a link moves to the route that matches whether a mail is selected.
 */
const LIST_STATE = ['id', 'tab', 'width'];

const withListState = (inbox, params, state, names) => {
  const next = new URLSearchParams(params);
  names.forEach((name) => {
    if (state.searchParams.has(name)) {
      next.set(name, state.searchParams.get(name));
    } else {
      next.delete(name);
    }
  });
  const target = new URL(next.has('id') ? inbox.dataset.letterboxShow : inbox.dataset.letterboxIndex, window.location.href);
  next.forEach((value, name) => {
    if (name !== 'token') {
      target.searchParams.set(name, value);
    }
  });

  return target;
};

const syncSearchForm = (inbox, form, state) => {
  const hidden = [...form.querySelectorAll('input[type="hidden"]')];
  const target = withListState(inbox, new URLSearchParams(hidden.map((input) => [input.name, input.value])), state, LIST_STATE);
  hidden.forEach((input) => input.remove());
  target.searchParams.forEach((value, name) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.prepend(input);
  });
  form.setAttribute('action', target.pathname);
};

/**
 * A detail swap leaves the list in place, so its links and search form follow the newly opened state.
 */
const syncListState = (inbox, state) => {
  const list = region(inbox, 'list');
  list.querySelectorAll('a[href]').forEach((link) => {
    const current = moduleUrl(inbox, link.href);
    if (current !== null) {
      // A row selects its own mail, only the preview width carries over.
      link.href = withListState(inbox, current.searchParams, state, link.classList.contains('letterbox-row') ? ['width'] : LIST_STATE).href;
    }
  });
  list.querySelectorAll('form[method="get"]').forEach((form) => syncSearchForm(inbox, form, state));
};

/**
 * Remembers which control had focus inside a region, so its counterpart can take it again after the swap.
 */
const focusMemory = (container) => {
  const active = document.activeElement;
  if (active === null || !container.contains(active)) {
    return null;
  }

  return { id: active.id, href: active instanceof HTMLAnchorElement ? active.href : null, name: active.getAttribute('name'), value: active.value };
};

const restoreFocus = (container, memory) => {
  const candidates = [...container.querySelectorAll('a[href], button, input')];
  const target = candidates.find((element) => memory.id !== '' && element.id === memory.id)
    ?? candidates.find((element) => memory.href !== null && element.href === memory.href)
    ?? candidates.find((element) => memory.name !== null && element.getAttribute('name') === memory.name && element.value === memory.value);
  target?.focus();

  return target !== undefined;
};

/**
 * The search field is replaced while the user may still be typing, so it keeps their text and caret.
 */
const searchMemory = (list) => {
  const input = list.querySelector('#letterbox-search');

  return input !== null && document.activeElement === input ? { value: input.value, start: input.selectionStart, end: input.selectionEnd } : null;
};

const restoreSearch = (list, memory) => {
  const input = list.querySelector('#letterbox-search');
  if (input === null) {
    return false;
  }
  input.value = memory.value;
  input.focus();
  input.setSelectionRange(memory.start, memory.end);

  return true;
};

const swapList = (inbox, node) => {
  const old = region(inbox, 'list');
  const memory = focusMemory(old);
  const search = searchMemory(old);
  old.replaceWith(node);
  revealToggles(node);
  // The stored state may still be on its way to the server, the class holds what the user sees.
  applyCollapsed(inbox, inbox.classList.contains(COLLAPSED));
  if (search !== null && restoreSearch(node, search)) {
    return;
  }
  if (memory !== null && !restoreFocus(node, memory)) {
    node.querySelector('.letterbox-quickfilter-item[aria-current="true"]')?.focus();
  }
};

/**
 * Returns whether another mail was opened. Focus moves to its subject, which screen readers read out, so the
 * swap is not announced as well.
 */
const swapDetail = (inbox, node, url) => {
  const old = region(inbox, 'detail');
  const memory = focusMemory(old);
  const previousId = old.querySelector('input[name="id"]')?.value ?? null;
  // Inserted before the old detail goes: when Chrome first tears down the old sandboxed preview frame and then
  // creates the new one, Tab from the module stops working and leaves the backend iframe.
  old.before(node);
  old.remove();
  const id = url.searchParams.get('id');
  const heading = node.querySelector('#letterbox-subject');
  if (id === null || heading === null) {
    return false;
  }
  initTabs(node).catch(() => window.location.assign(url.href));
  markOpened(inbox, id);
  // Width, remote images and the headers link keep the mail, their control keeps focus where it still exists.
  if (memory !== null && previousId === id) {
    if (!restoreFocus(node, memory)) {
      heading.focus();
    }
    return false;
  }
  heading.focus();

  return true;
};

const swap = (inbox, nodes, url) => {
  const focusWasInside = Object.keys(nodes).some((name) => region(inbox, name).contains(document.activeElement));
  const opened = nodes.detail === undefined ? false : swapDetail(inbox, nodes.detail, url);
  if (nodes.list === undefined) {
    syncListState(inbox, url);
  } else {
    swapList(inbox, nodes.list);
    if (!opened) {
      announce(inbox, countMessage(inbox));
    }
  }
  // Focus must not fall back to the document when its region was replaced, e.g. going back to the plain list.
  if (focusWasInside && !inbox.contains(document.activeElement)) {
    region(inbox, 'list').querySelector('.letterbox-quickfilter-item[aria-current="true"]')?.focus();
  }
};

/**
 * Core keeps the backend URL in sync from the load event of the module iframe, which pushState does not fire.
 * The same event from the module element lets the module router write the new URL into the address bar.
 */
const syncBackendUrl = (url) => {
  const frame = window.frameElement;
  const moduleElement = frame?.closest('typo3-iframe-module');
  if (moduleElement === null || moduleElement === undefined) {
    return;
  }
  const detail = {
    url: url.href,
    title: document.title,
    module: document.querySelector('.module[data-module-name]')?.getAttribute('data-module-name'),
  };
  moduleElement.dispatchEvent(new frame.ownerDocument.defaultView.CustomEvent('typo3-iframe-loaded', { detail, bubbles: true, composed: true }));
};

// Links and form submits order their parameters differently, so the order is ignored.
const isCurrentUrl = (url) => {
  const [next, current] = [new URL(url.href), new URL(window.location.href)];
  next.searchParams.sort();
  current.searchParams.sort();

  return next.href === current.href;
};

/**
 * "push" adds a history entry, "replace" updates the current one (search while typing), "none" leaves it alone
 * (back and forward). The same URL never adds an entry, e.g. clicking the active row or filter.
 */
const commit = (url, history) => {
  if (history !== 'none' && !isCurrentUrl(url)) {
    window.history[history === 'replace' ? 'replaceState' : 'pushState']({ letterbox: true }, '', url.href);
  }
  document.querySelectorAll('[data-letterbox-reload]').forEach((link) => {
    link.href = url.href;
  });
  syncBackendUrl(url);
};

const setBusy = (inbox, names) => {
  inbox.querySelectorAll('[data-letterbox-region]').forEach((element) => {
    if (names.includes(element.dataset.letterboxRegion)) {
      element.setAttribute('aria-busy', 'true');
    } else {
      element.removeAttribute('aria-busy');
    }
  });
};

const createNavigator = (inbox) => {
  const spinner = inbox.querySelector('[data-letterbox-spinner]');
  let controller = null;
  let spinnerTimer = 0;

  return async (url, names, history = 'push') => {
    controller?.abort();
    const current = new AbortController();
    controller = current;
    window.clearTimeout(spinnerTimer);
    setBusy(inbox, names);
    // Quick answers swap without the spinner flashing up.
    spinnerTimer = window.setTimeout(() => {
      spinner.hidden = false;
    }, DELAY);
    try {
      const nodes = await Promise.all(names.map((name) => fetchFragment(url, name, current.signal)));
      swap(inbox, Object.fromEntries(names.map((name, index) => [name, nodes[index]])), url);
      commit(url, history);
    } catch (error) {
      // An aborted request was overtaken by a newer one, anything else falls back to a full page load.
      if (error.name !== 'AbortError') {
        window.location.assign(url.href);
      }
    } finally {
      if (controller === current) {
        window.clearTimeout(spinnerTimer);
        spinner.hidden = true;
        setBusy(inbox, []);
      }
    }
  };
};

const isPlainClick = (event) => event.button === 0 && !event.defaultPrevented && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;

const onClick = (inbox, navigate, event) => {
  const toggle = event.target.closest('[data-letterbox-list-toggle]');
  if (toggle !== null) {
    setCollapsed(inbox, toggle.dataset.letterboxListToggle === 'hide');
    return;
  }
  const link = event.target.closest('a[href]');
  if (link === null || !isPlainClick(event) || link.target !== '' || link.hasAttribute('download')) {
    return;
  }
  const name = fragmentFor(link);
  const url = moduleUrl(inbox, link.href);
  if (name === null || url === null) {
    return;
  }
  event.preventDefault();
  navigate(url, [name]);
};

// A GET form replaces the query of its action. POST forms (delete, clear) always submit normally.
const onSubmit = (inbox, navigate, event, history) => {
  const form = event.target;
  if (event.defaultPrevented || form.method !== 'get') {
    return;
  }
  const name = fragmentFor(form);
  const url = moduleUrl(inbox, form.action);
  if (name === null || url === null) {
    return;
  }
  event.preventDefault();
  url.search = new URLSearchParams(new FormData(form, event.submitter)).toString();
  navigate(url, [name], history);
};

const bindInbox = (inbox) => {
  if (inbox.dataset[BOUND] === '1') {
    return;
  }
  inbox.dataset[BOUND] = '1';
  revealToggles(inbox);

  const navigate = createNavigator(inbox);
  let searchTimer = 0;
  // Set only for the submit the typing timer triggers, Enter and buttons add a history entry.
  let typed = false;
  // Delegated on the inbox, so controls inside swapped regions keep working.
  inbox.addEventListener('click', (event) => onClick(inbox, navigate, event));
  inbox.addEventListener('submit', (event) => {
    window.clearTimeout(searchTimer);
    const history = typed ? 'replace' : 'push';
    typed = false;
    onSubmit(inbox, navigate, event, history);
  });
  inbox.addEventListener('input', (event) => {
    if (event.target.id !== 'letterbox-search') {
      return;
    }
    window.clearTimeout(searchTimer);
    // Looked up when the timer fires, the list may have been replaced in between.
    searchTimer = window.setTimeout(() => {
      typed = true;
      inbox.querySelector('#letterbox-search')?.form.requestSubmit();
      typed = false;
    }, DELAY);
  });
  window.addEventListener('popstate', () => {
    const url = moduleUrl(inbox, window.location.href);
    if (url !== null) {
      navigate(url, ['list', 'detail'], 'none');
    }
  });
};

/**
 * Safe to call again after the page content was replaced, already bound inboxes are skipped.
 */
export const init = (root = document) => {
  root.querySelectorAll('.letterbox-inbox').forEach(bindInbox);
};

init();
