# Backend module

Back to [documentation overview](README.md).

![Letterbox backend module with mail list and HTML preview](images/backend-module.png)

The module is under System › Letterbox and is available to administrators only. It reads the file set in `transport_mbox_file`, so existing mbox files stay readable.

* The mail list shows caught mails with pagination. It ends at the bottom of the visible area and its rows scroll inside it. It can be collapsed to give the detail view the full width, and the state is stored per backend user.
* Each row shows the subject and "sender to recipient", using names where the mail has them and addresses otherwise.
* The search field finds mails by sender, recipient (name or address) and subject. The quick filters All, Unread and Attachments show their counts for the current search. Search, filter and page stay in every link, also while a mail is open.
* Unread mails carry a dot. A mail counts as read once its detail view has been opened, separately for each backend user. With the Unread filter active, an opened mail stays in the list until the list is fetched again.
* The detail view has these tabs:
  * HTML: the sandboxed preview, with a Preview and Code switch. Code shows the HTML part as the sender sent it, escaped and unsanitised. It is also shown, without the switch, when no preview can be built (HTML over 2 MiB, too many elements or a parse failure).
  * Text: the plain text part, with `http` and `https` URLs linked
  * Headers: the main headers, with an "All headers" link
  * Source: the raw message, which can be downloaded
  * Links: every link in the mail with copy and open buttons. Password reset links are flagged.
  * Attachments: each attachment can be downloaded
* The preview width can be switched between desktop (full width), tablet (520px) and mobile (360px).
* The HTML preview runs in a sandboxed frame and remote images are blocked. "Load remote images" allows them for the current mail only. They are loaded over https and nothing else, and the preview comes from its own route (`system_letterbox.preview`) with a restrictive content security policy. Images that point to a missing `cid:` part are reported above the preview.
* Deleting a single mail and clearing all mails ask for confirmation first, and a message confirms the result. After a delete the list keeps its search and filter. Opening a mail that no longer exists, for example through the browser history, returns to the list with a message.
* Without caught mails the module shows an empty state. When `debugMode` is already enabled in the [extension settings](configuration.md#extension-settings), it also shows the command that sends test mails.

## External access page

See [external access](external-access.md) for what the page controls.

The "External access" button in the doc header opens `system_letterbox.access`, also admin only. It shows:

* Whether external access is on. When it is off, each reason says what to change, for example which value `LETTERBOX_EXTERNAL_ACCESS` needs.
* The tester links with scope, expiry, creator and state. "Revoke" asks for confirmation and takes effect immediately.
* A form to create a tester link: a label and recipient patterns (one per line, at least one). Every link is read-only and valid for 7 days.
* Whether the API token is configured (at least 32 characters), with a `curl` example. The token itself is never shown.

The URL of a new link (`<site URL>_letterbox/inbox?t=<token>`) appears once, in the response to the create form, and is not stored or put in a flash message. Letterbox keeps only a hash of the token, so a lost link cannot be shown again and has to be revoked and replaced.

## Navigation without reload

The module is a TYPO3 JavaScript module (`Resources/Public/JavaScript/inbox.js`). Mail rows, pagination, quick filters, the width switch and "Load remote images" replace only the list or the detail view, and search does the same after 300 ms of typing or on Enter. The browser history and the backend URL follow, so back and reload work as usual. Typing in the search field replaces the current history entry instead of adding one per keystroke; Enter adds one.

Without JavaScript every one of these is a normal link or form and loads the full page.

Known limitations:

* In Chrome, the first Tab after opening an HTML mail without reload can move focus out of the module frame. Core modules behave the same.
* The backend URL is kept in sync through core's `typo3-iframe-loaded` event. If core stops dispatching it, the address bar stays on the previous URL.

The number of mails per page and the module itself are set in the [extension settings](configuration.md#extension-settings).
