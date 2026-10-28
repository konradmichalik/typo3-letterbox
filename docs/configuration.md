# Configuration

Back to [documentation overview](README.md).

## Transport

Letterbox is a Symfony mail transport. Activate it in `config/system/additional.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['dsn'] = '';
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = \KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport::class;
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = getenv('LETTERBOX_MBOX_FILE') ?: '/var/www/mbox/mails.mbox';
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_letterbox_mode'] = 'listed';
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_letterbox_catch'] = ['*@letterbox.invalid', 'qa@example.com'];
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_letterbox_delivery'] = 'smtp';
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_letterbox_release'] = ['*@example.org', 'qa@example.com'];
```

> [!WARNING]
> If `MAIL/dsn` is not empty, TYPO3 core ignores `MAIL/transport` and sends live through the DSN. Letterbox is bypassed and mails reach real recipients. Keep `dsn` empty.

The `transport_letterbox_*` keys cannot be set with `configuration:set` or in the Install Tool, because core only accepts known configuration paths. Set them in `additional.php`.

| Key | Required | Meaning |
|-----|----------|---------|
| `transport_mbox_file` | Always, also in mode `off` | Path of the mbox file. It must be writable and not match the file deny pattern. Keep it outside the web root (`Environment::getPublicPath()`), for example `/var/www/mbox/mails.mbox`: inside it, everyone can download every caught mail, and the temporary copy retention writes next to it as well. Reports shows an error and the transport logs a warning for every caught mail while the file is there. The TYPO3 var path counts as outside, because TYPO3 denies access to it in classic mode. |
| `transport_letterbox_mode` | No | `all`, `listed` or `off`. Default: `listed` in the exact root context `Production`, `all` otherwise (`Production/Staging` and `Production/Live` get `all`, Reports warns about it, see [delivery modes](delivery-modes.md#default-per-context)). |
| `transport_letterbox_catch` | In mode `listed` | Array or comma separated string of addresses and `*@domain` patterns. |
| `transport_letterbox_delivery` | When mails are delivered | Transport for non-caught recipients, for example `smtp`. It must not be the Letterbox transport. Its own `transport_*` settings are used as usual. A delivery that hands mails back to Letterbox (`mbox`, or `sendmail` running `letterbox:receive`, see [the detected loops](backend-module.md#release)) is refused: in modes `listed` and `off` every send that has a recipient to deliver throws, and Reports shows an error. |
| `transport_letterbox_release` | To release mails | Array or comma separated string of addresses and `*@domain` patterns that a caught mail may be [released](#release) to. Empty or unset switches release off. |

Details on the modes are in [delivery modes](delivery-modes.md).

## Release

`transport_letterbox_release` is the allowlist for [releasing a caught mail](backend-module.md#release) to a real address. It uses the same patterns as the catch list: full addresses and `*@domain`, case insensitive.

* Release is opt-in. An empty or missing list hides the button.
* One invalid entry switches release off for everybody, so a typo cannot widen the list.
* Release sends through `transport_letterbox_delivery`, so that key must be set, and must not lead back into Letterbox. See the [refusals](backend-module.md#release).
* Set `MAIL/defaultMailFromAddress`. Release uses it as the envelope sender, and falls back to the From of the mail only when it is empty.

## Extension settings

Set in Admin Tools > Settings > Extension Configuration.

| Setting | Type | Default | Effect |
|---------|------|---------|--------|
| `debugMode` | boolean | `0` | Registers the `letterbox:testmails:send` command. |
| `enableBackendModule` | boolean | `1` | Shows the backend module. |
| `mailsPerPage` | integer | `10` | Mails per page in the list. |
| `maxMessages` | integer | `500` | Keep at most this many mails, the oldest are removed after a new mail is caught. `0` for no limit. See [retention](operations.md#retention). |
| `maxAgeDays` | integer | `30` | Remove mails older than this many days after a new mail is caught. `0` for no limit. |
| `showToolbarItem` | boolean | `1` | Shows the [toolbar item](operations.md#toolbar-item) to administrators while Letterbox catches mails. |

Flush the caches after changing `debugMode` or `enableBackendModule`.

The environment variables for [external access](external-access.md#activation) are set outside TYPO3.
