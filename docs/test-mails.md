# Console command `letterbox:testmails:send`

Back to [documentation overview](README.md).

Sends test mails through the configured mail transport, so you can try Letterbox without a real mail source. The command is registered only when `debugMode` is enabled in the [extension settings](configuration.md#extension-settings). Flush the caches after enabling it.

It cycles through five mails from `sender@example.org` to `recipient@example.com`:

1. Plain text
2. HTML with an inline image
3. HTML with an attachment
4. Cc and Bcc recipients
5. A body line starting with `From`, which must survive the mbox escaping

## `--count`

Number of mails to send, default `5`. Values below 1 send one mail.

```bash
vendor/bin/typo3 letterbox:testmails:send --count 10
ddev exec vendor/bin/typo3 letterbox:testmails:send --count 10
```

The mails are delivered according to the [delivery mode](delivery-modes.md), so they only appear in the module when they are caught.
