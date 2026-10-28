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
  * Checks: rule based findings with a short excerpt, sorted error, warning, info. The tab counts errors and warnings. See [Checks](#checks).
  * Attachments: each attachment can be downloaded
* The meta line above the list shows the mbox file, the number and size of the mails and the [retention limits](operations.md#retention).
* The preview width can be switched between desktop (full width), tablet (520px) and mobile (360px).
* The HTML preview runs in a sandboxed frame and remote images are blocked. "Load remote images" allows them for the current mail only. They are loaded over https and nothing else, and the preview comes from its own route (`system_letterbox.preview`) with a restrictive content security policy. Images that point to a missing `cid:` part are reported above the preview. An inline image used more than once is embedded only once, as the background of a placeholder with the same size, so a newsletter that repeats its logo a thousand times keeps the preview small. An image that no browser could decode stays broken in that case, as it would in a mail client.
* Header actions are icon buttons: release, download `.eml` and delete. See [Release](#release).
* Deleting a single mail and clearing all mails ask for confirmation first, and a message confirms the result. After a delete the list keeps its search and filter. Opening a mail that no longer exists, for example through the browser history, returns to the list with a message.
* Without caught mails the module shows an empty state. When `debugMode` is already enabled in the [extension settings](configuration.md#extension-settings), it also shows the command that sends test mails.

## Checks

The Checks tab runs fixed rules over the stored mail. Nothing is fetched from the network, links and images are only read. Findings show a badge with the severity word, a message and, where there is something to point at, an excerpt of the offending HTML (at most 120 characters, whitespace collapsed). There are no line numbers. The tab label counts errors and warnings, and shows no number at 0. A mail without findings shows "No findings."

| Rule | Severity | Triggers when |
|------|----------|---------------|
| `script_or_form` | Error | The HTML part contains `script`, `form`, `iframe`, `object` or `embed`. One finding per element type, with the first such tag as excerpt. |
| `html_size` | Warning | The HTML part is larger than 102 KB (104448 bytes), which Gmail clips. |
| `missing_text` | Warning | The mail has no plain-text part, or it is blank. |
| `img_alt` | Warning | An `img` has no `alt` attribute. An empty `alt` counts as set. One finding with the count. |
| `http_link` | Warning | A link starts with `http:`. One finding with the count and the first URL. |
| `from_not_site_domain` | Warning | The domain of the From address is neither a host of a TYPO3 site (including language and base variants) nor a subdomain of one. Skipped when no site exists. |
| `missing_lang` | Info | The HTML part has an `html` element without a `lang` attribute. A fragment without `html` is not flagged. |
| `remote_image` | Info | An `img` loads from `http:` or `https:`. The message says when it looks like a tracking pixel: `width` and `height` attributes, or CSS `width` and `height`, both 0 or 1 (with or without `px`). |

An HTML part over the parser limits (see HTML above) still gets `html_size` and the rules that read only the text, links and From address; the rules that read the HTML structure are skipped.

## Release

Release sends a caught mail on to one real address, for example to show a client how the mail looks in their inbox. It is off until `MAIL/transport_letterbox_release` lists addresses, see [configuration](configuration.md#release). Without such a list the button does not appear.

Click "Release to a real address" in the mail header (admins only). The dialog repeats Subject, From and the current recipients, and asks for the address to release to. It is pre-filled with the first To recipient that matches the release list. An address outside the list is refused in the dialog and again on the server. Release needs JavaScript, like the delete confirmation.

What is sent:

* The stored message unchanged, except that the `X-Letterbox-Envelope-To` header is removed, with CRLF line endings. To and Cc still show the original recipients.
* Only to the chosen address, as the envelope recipient. Nobody else gets it.
* The envelope sender is `MAIL/defaultMailFromAddress`, else the From address of the mail. The site's own address comes first, because the From of a contact form mail is the visitor, who would get the bounces, and SPF would fail for their domain. The From header itself stays unchanged.
* Through `MAIL/transport_letterbox_delivery`, never through Letterbox itself. A configured `transport_spool_type` is ignored for the release, because the spool would be flushed back into Letterbox.

The copy in Letterbox stays. TYPO3 logs `Released mail <id> to a recipient at <domain> by backend user <uid>` at info level (the domain only, not the address). A failing transport shows an error message, and TYPO3 logs `Release of mail <id> to a recipient at <domain> failed: <exception class>` at warning level. The exception message is not logged, because it can carry addresses or server answers.

When release is configured but cannot work, the button still opens the dialog, which then names the setting to change and has only a Close button:

| Reason | Cause | Change |
|--------|-------|--------|
| `release_invalid` | An entry of `transport_letterbox_release` is not an address or `*@domain` pattern. | Fix the entry. One bad entry switches release off. |
| `delivery_missing` | `transport_letterbox_delivery` is empty. | Set it to a real transport such as `smtp`. |
| `delivery_loops` | The delivery would hand the mail back to Letterbox, see below. | Point delivery at a real mail server. |
| `sender_missing` | The mail has no usable From address and `defaultMailFromAddress` is empty or invalid. | Set `MAIL/defaultMailFromAddress`. |

Loops that are detected: delivery is the Letterbox transport, delivery `mbox` (core's mbox transport writes into the Letterbox file), delivery `sendmail` while the sendmail command (`transport_sendmail_command`, else `sendmail_path`) runs `letterbox:receive`, and a DSN (`dsn` delivery, or a class name while `MAIL/dsn` is set) that contains `letterbox:receive` or uses `native://` while `sendmail_path` runs it. The transport uses the same detection for normal delivery. Not detectable: an external sendmail binary or SMTP server that forwards mail back into `letterbox:receive` on its own. Check such a setup by hand.

## External access page

See [external access](external-access.md) for what the page controls.

The "External access" button in the doc header opens `system_letterbox.access`, also admin only. It shows:

* Whether external access is on. When it is off, each reason says what to change, for example which value `LETTERBOX_EXTERNAL_ACCESS` needs.
* Whether `LETTERBOX_ACCESS_TOKEN` is configured (at least 32 characters). The token itself is never shown.
* The ready-to-share tester link `<inbox URL>?t=<link secret>`, once the token is configured. The link secret is derived from the token and opens only the read-only inbox, see [external access](external-access.md#tester-inbox). Admins can read every mail anyway, so the page shows it in full.
* The API base URL and a `curl` example with a placeholder token.
* A reminder that changing the token revokes the link for everyone.

## Navigation without reload

The module is a TYPO3 JavaScript module (`Resources/Public/JavaScript/inbox.js`). Mail rows, pagination, quick filters, the width switch and "Load remote images" replace only the list or the detail view, and search does the same after 300 ms of typing or on Enter. The browser history and the backend URL follow, so back and reload work as usual. Typing in the search field replaces the current history entry instead of adding one per keystroke; Enter adds one.

Without JavaScript every one of these is a normal link or form and loads the full page.

Known limitations:

* In Chrome, the first Tab after opening an HTML mail without reload can move focus out of the module frame. Core modules behave the same.
* The backend URL is kept in sync through core's `typo3-iframe-loaded` event. If core stops dispatching it, the address bar stays on the previous URL.

The module is complemented by a [toolbar item, a Reports status and a dashboard widget](operations.md#toolbar-item).

The number of mails per page and the module itself are set in the [extension settings](configuration.md#extension-settings).
