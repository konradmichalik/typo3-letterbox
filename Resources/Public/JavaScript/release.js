/*
 * This file is part of the "typo3_letterbox" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';

const ICON = 'actions-paperplane';

// Mirrors RecipientPattern::matches() for early feedback. The server checks again and decides.
const allowed = (patterns, address) => {
  const normalized = address.toLowerCase();
  const at = normalized.lastIndexOf('@');

  return at > 0 && patterns.some((pattern) => (pattern.startsWith('*@') ? normalized.slice(at + 1) === pattern.slice(2) : normalized === pattern));
};

const isSingleAddress = (input) => input.checkValidity() && !/[\s,;<>]/.test(input.value.trim());

/**
 * Core opens the modal in the top frame, so the dialog only collects the address and submits the form that stays
 * in the module document.
 */
const openDialog = (button) => {
  const doc = button.ownerDocument;
  const form = doc.getElementById('letterbox-release-form');
  const template = doc.getElementById('letterbox-release-dialog');
  if (form === null || template === null) {
    return;
  }
  const content = template.content.firstElementChild.cloneNode(true);
  const input = content.querySelector('input');
  const patterns = (button.dataset.allowlist ?? '').split(',').map((pattern) => pattern.trim().toLowerCase()).filter((pattern) => pattern !== '');
  let submitted = false;

  const error = content.querySelector('.invalid-feedback');
  const setInvalid = (invalid) => {
    error.hidden = !invalid;
    input.classList.toggle('is-invalid', invalid);
    input.setAttribute('aria-invalid', invalid ? 'true' : 'false');
  };
  const confirm = (modal) => {
    const value = input.value.trim();
    if (!isSingleAddress(input) || !allowed(patterns, value)) {
      setInvalid(true);
      input.focus();
      return;
    }
    submitted = true;
    form.elements.recipient.value = value;
    modal.hideModal();
    form.submit();
  };

  const modal = Modal.advanced({
    title: button.dataset.title,
    content,
    severity: Severity.notice,
    buttons: [
      { text: button.dataset.buttonCloseText, btnClass: 'btn-default', name: 'cancel', trigger: (event, current) => current.hideModal() },
      { text: button.dataset.buttonOkText, btnClass: 'btn-primary', name: 'release', icon: ICON, trigger: (event, current) => confirm(current) },
    ],
  });
  // Core takes the title as text only, so the icon is added once the header is rendered.
  modal.updateComplete.then(() => {
    const icon = modal.ownerDocument.createElement('typo3-backend-icon');
    icon.setAttribute('identifier', ICON);
    icon.setAttribute('size', 'small');
    icon.setAttribute('aria-hidden', 'true');
    icon.classList.add('me-2');
    modal.querySelector('.t3js-modal-title')?.prepend(icon);
  });
  content.addEventListener('submit', (event) => {
    event.preventDefault();
    confirm(modal);
  });
  input.addEventListener('input', () => setInvalid(false));
  modal.addEventListener('typo3-modal-shown', () => input.focus());
  modal.addEventListener('typo3-modal-hidden', () => {
    if (!submitted) {
      button.focus();
    }
  });
};

// Delegated on the document, so the button keeps working after inbox.js swapped the detail region.
document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-letterbox-release]');
  if (button === null || button.getAttribute('aria-disabled') === 'true') {
    return;
  }
  event.preventDefault();
  openDialog(button);
});
