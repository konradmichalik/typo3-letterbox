# Backend module

Back to [documentation overview](README.md).

![Letterbox backend module with mail list and HTML preview](images/backend-module.png)

The module is under System › Letterbox and is available to administrators only. It reads the file set in `transport_mbox_file`, so existing mbox files stay readable.

* The mail list shows caught mails with pagination, the detail view shows headers, text and HTML.
* The search field finds mails by sender, recipient and subject. The quick filters All, Unread and Attachments show their counts for the current search. Search, filter and page stay in every link, also while a mail is open.
* Unread mails carry a dot. A mail counts as read once its detail view has been opened, separately for each backend user.
* The HTML preview runs in a sandboxed frame and remote images are blocked.
* The source of a mail and each attachment can be downloaded.
* Deleting a single mail and clearing all mails ask for confirmation first, and a message confirms the result. After a delete the list keeps its search and filter.
* Without caught mails the module shows an empty state. When `debugMode` is already enabled in the [extension settings](configuration.md#extension-settings), it also shows the command that sends test mails.

The number of mails per page and the module itself are set in the [extension settings](configuration.md#extension-settings).
