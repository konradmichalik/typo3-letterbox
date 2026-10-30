# Configuration

Back to [documentation overview](README.md).

## Switch

The extension setting `enable` ("Catch mails with Letterbox") turns Letterbox on without touching `additional.php`. Set it in Admin Tools › Settings › Extension Configuration › `typo3_letterbox` (System › Settings in TYPO3 14) and flush the caches.

The environment variable `LETTERBOX_ENABLE` overrides the setting on one system:

| `LETTERBOX_ENABLE` | Effect |
|--------------------|--------|
| `1`, `true`, `on` | On, whatever the setting says |
| `0`, `false`, `off` | Off, whatever the setting says |
| empty, unset or anything else | The setting decides |

When it is on and `MAIL/transport` is not Letterbox yet, `ext_localconf.php` takes over the transport. TYPO3 has loaded `settings.php` and `additional.php` at that point and has not built a mailer yet.

* `MAIL/transport` becomes the Letterbox transport and `MAIL/dsn` is cleared, because a DSN would bypass it.
* The original `transport` and `dsn` are kept for this request only, to name what Letterbox replaced, in `MAIL/transport_letterbox_takeover`. Nothing is written to `settings.php`.
* Without `transport_mbox_file`, mails go to `var/letterbox/mails.mbox` below the TYPO3 var path. The directory is created with the first mail.
* `transport_mbox_file` from `additional.php` is used as set.
* Letterbox catches every mail and delivers none, in every context. Turn it off on a live system, Reports warns in a `Production` context.
* Reports and the toolbar dropdown say "Letterbox took over the mail transport" with the source (extension setting or `LETTERBOX_ENABLE`) and the original transport as type, or DSN scheme, host and port. User, password and query of the DSN are never shown or logged.

When it is off, nothing changes. An explicit configuration in `additional.php` keeps working: the switch cannot turn that off. Remove the lines from `additional.php` instead.

Flush the caches after changing the setting. `LETTERBOX_ENABLE` is read on every request, a changed value applies once PHP sees it (for example after `ddev restart`).

## Transport

Instead of the switch, activate the transport in `config/system/additional.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['dsn'] = '';
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = \KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport::class;
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = getenv('LETTERBOX_MBOX_FILE') ?: '/var/www/mbox/mails.mbox';
```

> [!WARNING]
> If `MAIL/dsn` is not empty, TYPO3 core ignores `MAIL/transport` and sends live through the DSN. Letterbox is bypassed and mails reach real recipients. Keep `dsn` empty.

| Key | Required | Meaning |
|-----|----------|---------|
| `transport_mbox_file` | Always | Path of the mbox file. It must be writable and not match the file deny pattern. Keep it outside the web root (`Environment::getPublicPath()`), for example `/var/www/mbox/mails.mbox`: inside it, everyone can download every caught mail, and the temporary copy retention writes next to it as well. Reports shows an error and the transport logs a warning for every caught mail while the file is there. The TYPO3 var path counts as outside, because TYPO3 denies access to it in classic mode. |

## Caught mails

Every mail is stored in the mbox file and none is delivered. Every stored mail carries an `X-Letterbox-Envelope-To` header with the envelope recipients. It is needed because Bcc recipients are not part of the message headers. The header is only in the mbox file.

Storing is the purpose of the transport, so a mailbox that cannot be written (not writable, missing directory) makes the send fail with an exception.

Caught mails can contain personal data of testers and test users. The mbox file keeps their full content, including recipients. [Retention](operations.md#retention) removes old mails (by default beyond 500 mails or after 30 days), the file itself stays. Keep the file outside the web root, restrict access to it and clear it regularly in the module.

## Extension settings

Set in Admin Tools > Settings > Extension Configuration (System > Settings in TYPO3 14). The settings are grouped in tabs: Basic (the switch), Backend, Retention, External access and Debug.

| Setting | Type | Default | Effect |
|---------|------|---------|--------|
| `enable` | boolean | `0` | Catch mails with Letterbox: takes over the mail transport on this system, see [switch](#switch). `LETTERBOX_ENABLE` overrides it. |
| `enableBackendModule` | boolean | `1` | Shows the backend module. |
| `mailsPerPage` | integer | `10` | Mails per page in the list. |
| `maxMessages` | integer | `500` | Keep at most this many mails, the oldest are removed after a new mail is caught. `0` for no limit. See [retention](operations.md#retention). |
| `maxAgeDays` | integer | `30` | Remove mails older than this many days after a new mail is caught. `0` for no limit. |
| `showToolbarItem` | boolean | `1` | Shows the [toolbar item](operations.md#toolbar-item) to administrators while Letterbox catches mails. |
| `externalAccess` | boolean | `0` | Enable external access: the tester inbox and the JSON API may answer. One of several conditions, see [external access](external-access.md#activation). |
| `debugMode` | boolean | `0` | Registers the `letterbox:testmails:send` command. |

Flush the caches after changing `enable`, `debugMode` or `enableBackendModule`. `externalAccess` takes effect with the next request.

`LETTERBOX_ENABLE` and the environment variables for [external access](external-access.md#activation) are set outside TYPO3.
