# Operations

Back to [documentation overview](README.md).

How Letterbox keeps the mbox file small, the console commands, and the places outside the module where admins see that mails are being caught.

## Retention

Two limits keep the file from growing without end. Both are [extension settings](configuration.md#extension-settings), `0` switches a limit off:

| Setting | Default | Effect |
|---------|---------|--------|
| `maxMessages` | `500` | Keep the newest mails up to this number, remove the older ones. |
| `maxAgeDays` | `30` | Remove mails that arrived more than this many days ago. |

* The age comes from the arrival time in the mbox separator line (`From <sender> <date>`), which the transport writes in UTC. The `Date` header of the mail is not used, a sender can set it to anything.
* Separator lines written in local time instead of UTC shift the computed age by the server's UTC offset.
* A mail without a readable arrival time counts towards `maxMessages` but is never removed for its age.
* Pruning runs right after the transport has written a caught mail, and only when a limit is crossed. Mails that are not caught do not trigger it, and neither does opening the module. If pruning fails, the mail stays caught, the failure is logged as a warning and the send does not fail.
* After a caught mail the count may exceed `maxMessages` by a slack of 10 percent, at least 1 mail: with the default of 500 the file grows up to 550 mails, and the 551st cuts it back to exactly `maxMessages`. A full mailbox is therefore rewritten once per 51 mails instead of for every mail. The age limit has no slack, it removes all expired mails at once.
* [`letterbox:prune`](#letterboxprune) applies the limits exactly, without the slack, on demand and can run in the Scheduler, for example to apply a lowered limit or to age out a file that receives no new mails.

The meta line of the [backend module](backend-module.md) shows the active limits, for example "keeps 500 mails or 30 days". It shows nothing when both are `0`.

## Console commands

Run them with `vendor/bin/typo3` (in DDEV `ddev exec vendor/bin/typo3`). Every command exits with `1` and an error message on stderr when `MAIL/transport_mbox_file` is empty.

| Command | Purpose | Schedulable |
|---------|---------|-------------|
| `letterbox:list` | List the caught mails, newest first | No |
| `letterbox:show` | Print one part of a mail | No |
| `letterbox:clear` | Delete every caught mail | No |
| `letterbox:prune` | Remove mails beyond the retention limits | Yes |
| `letterbox:receive` | Store one mail from stdin, as a `sendmail_path` replacement, see [below](#letterboxreceive) | No |
| `letterbox:testmails:send` | Send test mails, only with `debugMode`, see [test mails](test-mails.md) | No |

Exit codes: `0` on success, `1` on invalid input, a missing mail or a missing configuration (`letterbox:receive` differs, see below). `letterbox:testmails:send` returns `2` for an invalid `--count`.

### `letterbox:list`

| Option | Default | Meaning |
|--------|---------|---------|
| `--limit`, `-l` | `20` | Number of mails, a whole number from 1 to 500. |
| `--to` | none | Only mails to this address. |
| `--subject` | none | Only mails whose subject contains this text. |

```bash
vendor/bin/typo3 letterbox:list --limit 2 --to anna@example.org
```

```
 ---------------------------------- --------------------- ------------------- ------------------ ---------------------------
  ID                                 Received              From                To                 Subject
 ---------------------------------- --------------------- ------------------- ------------------ ---------------------------
  3f9a1c0e5b7d4a2c8e6f1b0d9a7c5e3b   2026-10-28 09:14:02   shop@example.org    anna@example.org   Your order 1042
  a41d7e92c3b84f6d9e0a5c1b7f3d2e86   2026-10-28 08:51:47   shop@example.org    anna@example.org   Reset your password
 ---------------------------------- --------------------- ------------------- ------------------ ---------------------------
```

The time is shown in the PHP default timezone, `unknown` when the arrival time is unreadable. More than one recipient is shown as the first plus a count, such as `anna@example.org +2`. Long subjects are cut at 60 characters. Control characters in sender, recipient and subject are removed, so a mail cannot send escape sequences to the terminal.

### `letterbox:show`

| Argument or option | Default | Meaning |
|--------------------|---------|---------|
| `id` | required | The 32 character mail ID from `letterbox:list`. |
| `--part`, `-p` | `text` | `text`, `html`, `source` or `headers`. |
| `--raw` | off | Print control characters unchanged, also on a terminal. |

```bash
vendor/bin/typo3 letterbox:show 3f9a1c0e5b7d4a2c8e6f1b0d9a7c5e3b --part headers
```

The part is printed without formatting. Piped or redirected, every part is printed byte for byte. When the output is a terminal (checked with `stream_isatty()`, not with colour support, so `NO_COLOR`, `TERM=dumb` or `--no-ansi` change nothing), control characters other than tab and line feed are removed from every part, `source` included, so a mail cannot send escape sequences to your terminal. `--raw` turns that off. An unknown ID or part exits with `1`.

### `letterbox:clear`

| Option | Meaning |
|--------|---------|
| `--force`, `-f` | Clear without asking. Required in non-interactive runs, otherwise the command exits with `1`. |

```bash
vendor/bin/typo3 letterbox:clear --force
```

```
 [OK] Cleared 12 mails.
```

Answering "no" to the prompt deletes nothing and exits with `0`.

### `letterbox:prune`

| Option | Default | Meaning |
|--------|---------|---------|
| `--max-messages` | `maxMessages` setting | Keep at most this many mails, `0` for no limit. |
| `--max-age-days` | `maxAgeDays` setting | Remove mails older than this, `0` for no limit. |

```bash
vendor/bin/typo3 letterbox:prune --max-age-days 7
```

```
 [OK] Removed 3 mails.
```

If another process rewrites the file while the command prunes, it tries once more. When that also removes nothing, it prints a warning and exits with `1`. An option replaces its setting for this run only. A value that is not a whole number of 0 or more exits with `1`. To run it regularly, add the task "Execute console commands" in the Scheduler (EXT:scheduler) and select `letterbox:prune`.

### `letterbox:receive`

Stores one mail from stdin in the mailbox. It lets mails that do not go through the TYPO3 mailer land in Letterbox, for example PHP `mail()` calls from legacy code or other extensions. Point PHP's `sendmail_path` at it:

```ini
sendmail_path = "/usr/bin/php /var/www/html/vendor/bin/typo3 letterbox:receive -- -t -i"
```

Keep the `--` after the command name. Without it the console application reads sendmail flags starting with `-V` or `-h` (for example `-V envid` or `-h 5`) as its own `--version` or `--help`, prints that and exits with `0` without reading the mail, so the sender believes it was delivered. The `--` right after the command name is skipped and does not end the sendmail options.

* Use absolute paths and the PHP CLI binary (`php`, not `php-fpm` or `php-cgi`). The command runs in a fresh process without your shell's `PATH` or working directory.
* `sendmail_path` is `PHP_INI_SYSTEM`. Set it in `php.ini` or the FPM pool configuration (`php_admin_value[sendmail_path]`), not with `ini_set()` or `.user.ini`.
* The command boots TYPO3 for each mail, so it needs the same environment as the web request, above all `TYPO3_CONTEXT` and the database variables. PHP-FPM pools with `clear_env = yes` (the default) do not pass them on. Set `clear_env = no` or list the variables in the pool with `env[NAME] = ...`.
* One TYPO3 boot per mail. Fine for test systems, slow for mass mailing.
* It stores every mail regardless of `transport_letterbox_mode`. Using it is the explicit choice to catch.
* The [retention](#retention) limits are applied afterwards, as for a caught mail.

Flags are read from the raw command line, because TYPO3 would drop everything after the first flag it does not know:

| Flag | Effect |
|------|--------|
| `-t` | Take the recipients from the To, Cc and Bcc headers. |
| `-i` | Ignored. |
| `-f <sender>`, `-r <sender>` | Envelope sender, the value may be attached (`-fsender`). Otherwise the address of the From header, else none. |
| Addresses | Further arguments are recipients, also comma separated. |

Other sendmail flags (`-o...`, `-F name`, `-b...`, long options and unknown ones) are accepted and ignored, flags can be combined (`-ti`), and a later `--` ends the options. Without the leading `--`, Symfony's own options apply to the same command line: `-v` makes the output verbose, and `-q`, `-h`, `-V` and `-n` are read by the console application, not ignored. PHP's `mail()` does not pass them, other senders may.

Bcc recipients go into the `X-Letterbox-Envelope-To` header and the `Bcc` header itself is removed from the stored copy, as for caught mails. The message is limited to 25 MiB.

| Exit code | Meaning |
|-----------|---------|
| `0` | Stored. Nothing is printed. |
| `1` | Invalid input: empty message, over 25 MiB, unparsable, no recipients (neither arguments nor `-t` addresses), or an address with whitespace, control characters or `, < > "`. |
| `2` | The mailbox is not configured (`transport_mbox_file` empty or rejected by the file deny pattern) or not writable. |

Errors go to stderr as one line starting with `letterbox:receive:`.

> [!WARNING]
> Do not combine this with `transport_letterbox_delivery = sendmail` while `sendmail_path` runs `letterbox:receive`. Core's sendmail transport falls back to `sendmail_path`, so mails that should be delivered would be stored instead. Letterbox refuses this setup: release is off, sending throws in modes `listed` and `off`, and Reports shows an error.

## Toolbar item

Administrators see a Letterbox button in the backend toolbar while mails are being caught. It is how a tester learns why a mail did not arrive.

* Shown to admins only, when Letterbox is the configured transport and the mode is not `off`. It stays visible with invalid mail settings, because that is where the problem shows. `showToolbarItem` in the [extension settings](configuration.md#extension-settings) turns it off.
* The label is "Mail catcher active" in mode `all` and "Catching 3 addresses" in mode `listed`.
* The badge counts the mails the current backend user has not opened yet, see [unread state](backend-module.md). At 0 there is no badge. It is yellow in mode `all` and blue in mode `listed`.
* The dropdown explains what happens to mails, lists the subjects of the latest 5 mails with their arrival time (unread ones in bold), each linking to the mail, and has an "Open Letterbox" button. Without the backend module (`enableBackendModule` off) the subjects are plain text and the button is missing.
* After a [takeover](configuration.md#switch) the dropdown starts with "Letterbox took over the mail transport (extension setting)" or "(LETTERBOX_ENABLE)" and the original transport (type, or DSN scheme, host and port).
* Instead of the mails the dropdown names the problem: no mbox file configured, invalid mail settings (sending fails until they are fixed), or an mbox file that cannot be read, for example a directory or a file without read permission.

## Reports status

With EXT:reports installed (`composer require typo3/cms-reports`, listed under `suggest`), Admin Tools > Reports > Status has a "Letterbox" section. It names the transport, the delivery mode, whether [release](backend-module.md#release) works, the mbox file, the retention limits and whether external access is on. When another transport is configured it only says that Letterbox is not the mail transport. After a [takeover](configuration.md#switch) the transport line is Info and says "Letterbox took over the mail transport" with the source and the original transport, without credentials.

* Error: the mail settings are invalid.
* Warning: mode `all` in the exact root context `Production`, an mbox file or directory that is not writable, no mbox file configured, a release list that cannot work (invalid entry, no or looping delivery transport, or empty `MAIL/defaultMailFromAddress`, with the setting to change).
* Info: mode `listed` (the value shows how many addresses are caught), release off, external access on. It is never on in root `Production`.
* OK: the rest, including "Release to" with the allowed patterns, and "Keeps all mails" when both retention limits are `0`.

## Recent mails widget

With EXT:dashboard installed (`composer require typo3/cms-dashboard`, listed under `suggest`), the dashboard offers the widget "Recent mails" in the group "System Information".

* It lists the latest 6 mails: subject, recipient (the first one by name, or its address when it has none, plus `+N` for more) and arrival time, each subject linking to the mail, plus an "Open Letterbox" button.
* It is for administrators. On TYPO3 14 it is not offered to other users, on TYPO3 13 it renders empty for them.
* Without a configured mbox file it says so, and when Letterbox is not the active transport it adds a note.
