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

const setCollapsed = (inbox, collapsed) => {
  const list = inbox.querySelector('#letterbox-list');
  const rail = inbox.querySelector('.letterbox-rail');
  const hide = inbox.querySelector('[data-letterbox-list-toggle="hide"]');
  const show = inbox.querySelector('[data-letterbox-list-toggle="show"]');

  list.hidden = collapsed;
  rail.hidden = !collapsed;
  inbox.classList.toggle('letterbox-inbox--collapsed', collapsed);

  // The focused button disappears with its container, so focus moves to the opposite one.
  (collapsed ? show : hide).focus();
  Persistent.set(STORAGE_KEY, collapsed ? 1 : 0);
};

const bindInbox = (inbox) => {
  if (inbox.dataset[BOUND] === '1') {
    return;
  }
  inbox.dataset[BOUND] = '1';

  inbox.querySelectorAll('[data-letterbox-list-toggle]').forEach((button) => {
    // Without JavaScript the buttons stay hidden and the list stays open.
    button.hidden = false;
    button.addEventListener('click', () => setCollapsed(inbox, button.dataset.letterboxListToggle === 'hide'));
  });
};

/**
 * Safe to call again after the page content was replaced, already bound inboxes are skipped.
 */
export const init = (root = document) => {
  root.querySelectorAll('.letterbox-inbox').forEach(bindInbox);
};

init();
