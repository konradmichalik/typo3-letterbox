# Backend module

Back to [documentation overview](README.md).

![Letterbox backend module with mail list and HTML preview](images/backend-module.png)

The module is under System › Letterbox and is available to administrators only. It reads the file set in `transport_mbox_file`, so existing mbox files stay readable.

* The mail list shows caught mails with pagination. It can be collapsed to give the detail view the full width, and the state is stored per backend user.
* The search field finds mails by sender, recipient and subject. The quick filters All, Unread and Attachments show their counts for the current search. Search, filter and page stay in every link, also while a mail is open.
* Unread mails carry a dot. A mail counts as read once its detail view has been opened, separately for each backend user. With the Unread filter active, an opened mail stays in the list until the list is fetched again.
* The detail view has these tabs:
  * HTML: the sandboxed preview
  * Text: the plain text part, with `http` and `https` URLs linked
  * Headers: the main headers, with an "All headers" link
  * Source: the raw message, which can be downloaded
  * Links: every link in the mail with copy and open buttons. Password reset links are flagged.
  * Attachments: each attachment can be downloaded
* The preview width can be switched between desktop (640px), tablet (520px) and mobile (360px).
* The HTML preview runs in a sandboxed frame and remote images are blocked. "Load remote images" allows them for the current mail only. They are loaded over https and nothing else, and the preview comes from its own route (`system_letterbox.preview`) with a restrictive content security policy. Images that point to a missing `cid:` part are reported above the preview.
* Deleting a single mail and clearing all mails ask for confirmation first, and a message confirms the result. After a delete the list keeps its search and filter.
* Without caught mails the module shows an empty state. When `debugMode` is already enabled in the [extension settings](configuration.md#extension-settings), it also shows the command that sends test mails.

## Navigation without reload

The module is a TYPO3 JavaScript module (`Resources/Public/JavaScript/inbox.js`). Mail rows, pagination, quick filters, the width switch and "Load remote images" replace only the list or the detail view, and search does the same after 300 ms of typing or on Enter. The browser history and the backend URL follow, so back and reload work as usual.

Without JavaScript every one of these is a normal link or form and loads the full page.

Known limitations:

* In Chrome, the first Tab after opening an HTML mail without reload can move focus out of the module frame. Core modules behave the same.
* The backend URL is kept in sync through core's `typo3-iframe-loaded` event. If core stops dispatching it, the address bar stays on the previous URL.

The number of mails per page and the module itself are set in the [extension settings](configuration.md#extension-settings).
