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
* Files written by EXT:mbox have local time in that line, but Letterbox reads it as UTC. For such mails the age can be off by the UTC offset of the server that wrote them.
* A mail without a readable arrival time counts towards `maxMessages` but is never removed for its age.
* Pruning runs right after the transport has written a caught mail, and only when a limit is crossed. Mails that are not caught do not trigger it, and neither does opening the module. If pruning fails, the mail stays caught, the failure is logged as a warning and the send does not fail.
* After a caught mail the count may exceed `maxMessages` by a slack of 10 percent, at least 1 mail (551 for the default of 500). Only the next mail beyond that cuts the file back to exactly `maxMessages`, so a full mailbox is rewritten once per 50 mails instead of for every mail. The age limit has no slack, it removes all expired mails at once.
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
| `letterbox:testmails:send` | Send test mails, only with `debugMode`, see [test mails](test-mails.md) | No |

Exit codes: `0` on success, `1` on invalid input, a missing mail or a missing configuration. `letterbox:testmails:send` returns `2` for an invalid `--count`.

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

The time is shown in the PHP default timezone, `unknown` when the arrival time is unreadable. More than one recipient is shown as the first plus a count, such as `anna@example.org +2`. Long subjects are cut at 60 characters.

### `letterbox:show`

| Argument or option | Default | Meaning |
|--------------------|---------|---------|
| `id` | required | The 32 character mail ID from `letterbox:list`. |
| `--part`, `-p` | `text` | `text`, `html`, `source` or `headers`. |

```bash
vendor/bin/typo3 letterbox:show 3f9a1c0e5b7d4a2c8e6f1b0d9a7c5e3b --part headers
```

The part is printed as it is, without formatting, so it can be piped or redirected. An unknown ID or part exits with `1`.

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

An option replaces its setting for this run only. A value that is not a whole number of 0 or more exits with `1`. To run it regularly, add the task "Execute console commands" in the Scheduler (EXT:scheduler) and select `letterbox:prune`.

## Toolbar item

Administrators see a Letterbox button in the backend toolbar while mails are being caught. It is how a tester learns why a mail did not arrive.

* Shown to admins only, when Letterbox is the configured transport and the mode is not `off`. It stays visible with invalid mail settings, because that is where the problem shows. `showToolbarItem` in the [extension settings](configuration.md#extension-settings) turns it off.
* The label is "Mail catcher active" in mode `all` and "Catching 3 addresses" in mode `listed`.
* The badge counts the mails the current backend user has not opened yet, see [unread state](backend-module.md). At 0 there is no badge. It is yellow in mode `all` and blue in mode `listed`.
* The dropdown explains what happens to mails, lists the subjects of the latest 5 mails with their arrival time (unread ones in bold), each linking to the mail, and has an "Open Letterbox" button. Without the backend module (`enableBackendModule` off) the subjects are plain text and the button is missing.

## Reports status

With EXT:reports installed (`composer require typo3/cms-reports`, listed under `suggest`), Admin Tools > Reports > Status has a "Letterbox" section. It names the transport, the delivery mode, the mbox file, the retention limits and whether external access is on. When another transport is configured it only says that Letterbox is not the mail transport.

* Error: the mail settings are invalid.
* Warning: mode `all` in the exact root context `Production`, an mbox file or directory that is not writable, no mbox file configured, external access on in root `Production`.
* Info: mode `listed` (the value shows how many addresses are caught), external access on in any other context.
* OK: the rest, including "Keeps all mails" when both retention limits are `0`.

## Recent mails widget

With EXT:dashboard installed (`composer require typo3/cms-dashboard`, listed under `suggest`), the dashboard offers the widget "Recent mails" in the group "System Information".

* It lists the latest 6 mails: subject, sender (name, or address when the mail has none) and arrival time, each subject linking to the mail, plus an "Open Letterbox" button.
* It is for administrators. On TYPO3 14 it is not offered to other users, on TYPO3 13 it renders empty for them.
* Without a configured mbox file it says so, and when Letterbox is not the active transport it adds a note.
