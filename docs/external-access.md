# External access

Back to [documentation overview](README.md).

Letterbox can answer outside the backend: a [JSON API](api.md) for automated tests and a read-only inbox for testers without a backend account. Both are off until you switch them on, and every refusal is an empty-looking `404`, so a scanner cannot tell the surface exists.

## Activation

Set these in the process environment of the web server or PHP, not in `additional.php`.

| Variable | Required | Meaning |
|----------|----------|---------|
| `LETTERBOX_EXTERNAL_ACCESS` | Yes | Must equal the exact application context, for example `Production/Staging`. Any other value, including a context that only starts the same, keeps access off. |
| `LETTERBOX_EXTERNAL_ALLOWED_IPS` | No | Comma separated client IPs, CIDR ranges and octet wildcards such as `10.0.*.*`. Unset allows every address. |
| `LETTERBOX_API_TOKEN` | For the API | At least 32 characters. Without it the API stays off, the tester inbox is unaffected. |

```bash
LETTERBOX_EXTERNAL_ACCESS=Production/Staging
LETTERBOX_EXTERNAL_ALLOWED_IPS=203.0.113.0/24,198.51.100.7
```

Access also needs the Letterbox transport in `MAIL/transport`, HTTPS, and valid settings. If anything is missing, the page "External access" in the backend names each reason and what to change. The keys below are the reasons it shows.

| Reason | Cause | What to do |
|--------|-------|------------|
| `env_missing` | `LETTERBOX_EXTERNAL_ACCESS` is unset or empty | Set it to the current context |
| `env_context_mismatch` | The value differs from the context | Set it to exactly the context shown |
| `transport_not_letterbox` | `MAIL/transport` is not the Letterbox transport | See [configuration](configuration.md#transport) |
| `mail_settings_invalid` | The delivery mode or catch list is invalid | Fix the `transport_letterbox_*` keys |
| `production_requires_listed` | Root `Production` without delivery mode `listed` | Set `transport_letterbox_delivery` as described in [Production](#production) |
| `catch_list_empty` | Root `Production`, mode `listed`, empty catch list | Add the addresses to catch |
| `exclusions_invalid` | `externalExcludedRecipients` holds an invalid entry | Fix the [setting](#excluded-recipients) |
| `ip_not_allowed` | The allowlist holds a catch-all entry, is empty after trimming, or does not contain the client address | List the real ranges, see [IP allowlist](#ip-allowlist) |
| `https_required` | The request came in over plain HTTP | Open the site over HTTPS |

## Production

In the exact root context `Production`, external access is limited to the catch list. It only works with delivery mode `listed`, and only mails to caught addresses are visible. An empty catch list keeps access off, because there would be nothing safe to show. Other contexts, including `Production/Staging`, see everything the transport caught.

## IP allowlist

`LETTERBOX_EXTERNAL_ALLOWED_IPS` is matched against the client address from `NormalizedParams`. Behind a reverse proxy or load balancer set `SYS/reverseProxyIP`, otherwise the proxy address is what gets compared.

A catch-all entry (`*`, `*.*.*.*`, anything ending in `/0`) switches the whole surface off instead of allowing everyone. Octet wildcards such as `10.0.*.*` are fine.

## Excluded recipients

The extension setting `externalExcludedRecipients` (see [configuration](configuration.md#extension-settings)) hides mails from both surfaces. It takes comma separated addresses or `*@domain` patterns. A mail is hidden when any recipient matches, Bcc included. Use it for staff addresses, so a test system never shows them to testers. One invalid entry switches external access off.

```text
admin@example.org, *@staff.example.org
```

## Failed attempts

Ten failed attempts from one IP within 15 minutes block that IP. After that everything from it answers `404` until the window has passed. The API and the tester inbox share the counter. Successful requests never count. These count as a failed attempt:

* API: a missing or wrong token, or a token in the query string
* Tester inbox: an invalid link token, or a cookie whose link is unknown, revoked or expired

A request without a cookie does not count.

## Host header

TYPO3 checks the `Host` header before Letterbox runs. With a wrong host and `SYS/trustedHostsPattern` set, core throws an error, not a `404`. Set `trustedHostsPattern` on every system with external access.

## Backend password reset

A tester who can read a mailbox could read the reset link of a backend user. Set `BE/passwordResetForAdmins` to `false` so administrators cannot request a reset mail, or `BE/passwordReset` to `false` to turn the reset off. Other backend users stay resettable, so add staff addresses to `externalExcludedRecipients`.

## Tester inbox

Open System › Letterbox › External access to issue a link. The form takes a label and at least one recipient pattern (one per line). Every link is read-only and valid for 7 days.

* The link (`<site URL>_letterbox/inbox?t=<token>`) is shown once. Letterbox keeps only a hash, so a lost link cannot be shown again. Revoke it and issue a new one.
* Opening the link exchanges the token for a `Strict`, `HttpOnly`, `Secure` cookie and redirects to the clean URL. Revoking a link takes effect on the next request.
* A pattern is an exact address or `*@domain`. A mail is visible when one of its recipients (To, Cc or envelope) matches the scope. Display names never match, and a Bcc header never widens the scope. In root `Production`, one recipient must match both the link and the catch list.
* The tester sees only the matching recipients and "and N more" for the rest. Addresses written in the mail body are not masked.
* The inbox shows subject, sender, date, an HTML view and a text view, the links in the mail, and a button for the first password reset link. It never shows headers, the source or `.eml` files, loads no remote images and runs no JavaScript. It lists the attachments of a mail and lets the tester download them. A tester cannot delete mails.
* It works without a configured site and in maintenance mode, and answers are never cached.

The status card on the page shows whether access is on and, if not, each reason. It also shows whether the API token is configured. The token itself is never displayed.

## Upgrading

The tester links live in the table `tx_typo3letterbox_tester_link`. After updating, run the database analyzer in Admin Tools, or `typo3 extension:setup`. Flush the caches, so the new middlewares are registered.

## DDEV

`.ddev/config.yaml` sets `LETTERBOX_EXTERNAL_ACCESS=Development`. For the API, put the token only in the git-ignored `.ddev/config.local.yaml`:

```yaml
web_environment:
    - LETTERBOX_API_TOKEN=<at least 32 characters>
```

DDEV merges `web_environment` lists from `config.*.yaml` files with `config.yaml`, so the access line stays ([DDEV docs](https://ddev.readthedocs.io/en/stable/users/extend/customization-extendibility/#extending-configyaml-with-custom-configyaml-files)).

Installs that linked the extension file by file before `ext_tables.sql` existed need the file linked into `.Build/{13,14}/packages/typo3_letterbox/`, then `typo3 database:updateschema`.
