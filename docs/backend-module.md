# Backend module

Back to [documentation overview](README.md).

![Letterbox backend module with mail list and HTML preview](images/backend-module.png)

The module is under System › Letterbox and is available to administrators only. It reads the file set in `transport_mbox_file`, so existing mbox files stay readable.

* The mail list shows caught mails with pagination, the detail view shows headers, text and HTML.
* The HTML preview runs in a sandboxed frame and remote images are blocked.
* The source of a mail and each attachment can be downloaded.
* A single mail can be deleted. Clearing all mails asks for confirmation first.
* Without caught mails the module shows an empty state, with a hint to enable `debugMode` for test mails.

The number of mails per page and the module itself are set in the [extension settings](configuration.md#extension-settings).
