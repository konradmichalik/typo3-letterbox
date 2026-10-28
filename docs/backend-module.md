# Backend module

Back to [documentation overview](README.md).

![Letterbox backend module with mail list and HTML preview](images/backend-module.png)

The module is under System › Letterbox and is available to administrators only. It reads the file set in `transport_mbox_file`, so existing mbox files stay readable.

* The mail list shows caught mails with pagination. It ends at the bottom of the visible area and its rows scroll inside it. It can be collapsed to give the detail view the full width, and the state is stored per backend user.
* Each row shows the subject and "sender to recipient", using names where the mail has them and addresses otherwise.
* The search field finds mails by sender, recipient (name or address) and subject. The quick filters All, Unread and Attachments show their counts for the current search. Search, filter and page stay in every link, also while a mail is open.
* Unread mails carry a dot. A mail counts as read once its detail view has been opened, separately for each backend user. With the Unread filter active, an opened mail stays in the list until the list is fetched again.
* While the current user has unread mails, the list header has a "Mark all as read" button. It marks every caught mail as read for this user only, keeps search, filter and page, and reloads the module. The read state holds at most the 2000 most recently read mails per user.
* The detail view has these tabs:
  * HTML: the sandboxed preview, with a Preview and Code switch (icon buttons) on the right above it. Code shows the HTML part as the sender sent it, escaped and unsanitised. It is also shown, without the switch, when no preview can be built (HTML over 2 MiB, too many elements or a parse failure).
  * Text: the plain text part, with `http` and `https` URLs linked
  * Headers: the main headers, with an "All headers" link
  * Source: the raw message, which can be downloaded
  * Links: every link in the mail with copy and open buttons. Password reset links are flagged.
  * Checks: rule based findings, sorted error, warning, info, each with a short excerpt. The tab counts errors and warnings. Rules: HTML part over 102 KB (Gmail clips it), no plain-text part, images without `alt`, no `lang` on the `html` element, `http:` links, remote images (with possible tracking pixels), `script`, `form`, `iframe`, `object` or `embed` in the HTML part, and a From domain that is not a domain of any TYPO3 site (skipped when no site exists; subdomains of a site host match). Nothing is fetched from the network. A HTML part over the parser limits only gets the size and text based rules.
  * Attachments: each attachment can be downloaded
* The meta line above the list shows the mbox file, the number and size of the mails and the [retention limits](operations.md#retention).
* The preview width can be switched between desktop (full width), tablet (520px) and mobile (360px).
* The HTML preview runs in a sandboxed frame and remote images are blocked. "Load remote images" allows them for the current mail only. They are loaded over https and nothing else, and the preview comes from its own route (`system_letterbox.preview`) with a restrictive content security policy. Images that point to a missing `cid:` part are reported above the preview.
* Deleting a single mail and clearing all mails ask for confirmation first, and a message confirms the result. After a delete the list keeps its search and filter. Opening a mail that no longer exists, for example through the browser history, returns to the list with a message.
* Without caught mails the module shows an empty state. When `debugMode` is already enabled in the [extension settings](configuration.md#extension-settings), it also shows the command that sends test mails.

## External access page

See [external access](external-access.md) for what the page controls.

The "External access" button in the doc header opens `system_letterbox.access`, also admin only. It shows:

* Whether external access is on. When it is off, each reason says what to change, for example which value `LETTERBOX_EXTERNAL_ACCESS` needs.
* Whether `LETTERBOX_ACCESS_TOKEN` is configured (at least 32 characters). The token itself is never shown.
* The tester inbox URL without the token, the API base URL and a `curl` example with a placeholder token.
* A reminder that testers get `<inbox URL>?t=<token>` and that changing the token revokes access for everyone.

## Navigation without reload

The module is a TYPO3 JavaScript module (`Resources/Public/JavaScript/inbox.js`). Mail rows, pagination, quick filters, the width switch and "Load remote images" replace only the list or the detail view, and search does the same after 300 ms of typing or on Enter. The browser history and the backend URL follow, so back and reload work as usual. Typing in the search field replaces the current history entry instead of adding one per keystroke; Enter adds one.

Without JavaScript every one of these is a normal link or form and loads the full page.

Known limitations:

* In Chrome, the first Tab after opening an HTML mail without reload can move focus out of the module frame. Core modules behave the same.
* The backend URL is kept in sync through core's `typo3-iframe-loaded` event. If core stops dispatching it, the address bar stays on the previous URL.

The module is complemented by a [toolbar item, a Reports status and a dashboard widget](operations.md#toolbar-item).

The number of mails per page and the module itself are set in the [extension settings](configuration.md#extension-settings).
