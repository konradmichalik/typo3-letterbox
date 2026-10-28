# External access

Back to [documentation overview](README.md).

Letterbox can answer outside the backend: a [JSON API](api.md) for automated tests and a read-only inbox for testers without a backend account. Both are off until you switch them on, and every refusal is an empty-looking `404`, so a scanner cannot tell the surface exists.

> [!WARNING]
> Everyone with the inbox link or the token sees every caught mail of that system, including password reset links. External access is meant for test systems without real data. It is always off in the root context `Production`.

## Activation

Set these in the process environment of the web server or PHP, not in `additional.php`.

| Variable | Meaning |
|----------|---------|
| `LETTERBOX_EXTERNAL_ACCESS` | Must equal the exact application context, for example `Production/Staging`. Any other value, including a context that only starts the same, keeps access off. |
| `LETTERBOX_ACCESS_TOKEN` | The shared secret of the API and the tester inbox, at least 32 characters. Without it, or when it is shorter, both stay off. |

```bash
LETTERBOX_EXTERNAL_ACCESS=Production/Staging
LETTERBOX_ACCESS_TOKEN=$(openssl rand -hex 32)
```

Access also needs the Letterbox transport in `MAIL/transport` and HTTPS. If anything is missing, the page "External access" in the backend names each reason and what to change. The keys below are the reasons it shows.

| Reason | Cause | What to do |
|--------|-------|------------|
| `env_missing` | `LETTERBOX_EXTERNAL_ACCESS` is unset or empty | Set it to the current context |
| `env_context_mismatch` | The value differs from the context | Set it to exactly the context shown |
| `production` | The context is the root context `Production` | Nothing, external access is never on there. Use a test system with its own context such as `Production/Staging` |
| `transport_not_letterbox` | `MAIL/transport` is not the Letterbox transport | See [configuration](configuration.md#transport) |
| `token_missing` | `LETTERBOX_ACCESS_TOKEN` is unset or shorter than 32 characters | Set a random secret of at least 32 characters |
| `https_required` | The request came in over plain HTTP | Open the site over HTTPS |

## Tester inbox

Copy the tester link from the backend page "External access" and share it with your testers. It has the form `<site URL>_letterbox/inbox?t=<link secret>`. The link secret is `hash_hmac('sha256', 'tester-link', <token>)`, 64 hex characters. It opens the read-only inbox only: the API never accepts it, and the inbox never accepts the raw token in `?t=`. A leaked tester link therefore exposes the mails but cannot delete them or download sources.

To build the link without the backend:

```bash
php -r 'echo hash_hmac("sha256", "tester-link", getenv("LETTERBOX_ACCESS_TOKEN")), PHP_EOL;'
```

* Opening the link checks the link secret and answers with a `303` to the clean URL `<site URL>_letterbox/inbox`, so the token leaves the address bar. The redirect sets an `HttpOnly`, `Secure`, `SameSite=Lax` cookie for that path, valid for 7 days. `Lax` keeps the cookie on the redirect when the link is clicked in webmail, chat or a ticket.
* The cookie holds an HMAC derived from the token, never the token itself. Every request compares it again, so changing `LETTERBOX_ACCESS_TOKEN` ends every session and invalidates the tester link at once. That is the only way to revoke access, and it revokes it for everyone.
* The inbox lists all caught mails with search and pagination. It shows subject, sender, recipients, date, an HTML view and a text view, the links in the mail, and a button for the first password reset link. It lists the attachments of a mail and lets the tester download them.
* It never shows headers, the source or `.eml` files, loads no remote images and runs no JavaScript. The HTML view is a sandboxed `srcdoc` frame. The inbox only answers GET and changes nothing. A tester cannot delete mails.
* It works without a configured site and in maintenance mode, and answers are never cached.

## Host header

TYPO3 checks the `Host` header before Letterbox runs. With a wrong host and `SYS/trustedHostsPattern` set, core throws an error, not a `404`. Set `trustedHostsPattern` on every system with external access.

## Backend password reset

Everyone with the link can read the reset link of a backend user. Set `BE/passwordResetForAdmins` to `false` so administrators cannot request a reset mail, or `BE/passwordReset` to `false` to turn the reset off.

## Upgrading

Earlier versions issued tester links per recipient, kept them in the table `tx_typo3letterbox_tester_link` and read the API token from `LETTERBOX_API_TOKEN`. Rename the variable to `LETTERBOX_ACCESS_TOKEN`. Old tester links and cookies stop working, share the new inbox link instead. The database analyzer in Admin Tools, or `typo3 database:updateschema destructive`, offers to drop the old table. Flush the caches afterwards.

## DDEV

`.ddev/config.yaml` sets `LETTERBOX_EXTERNAL_ACCESS=Development`. Put the token only in the git-ignored `.ddev/config.local.yaml` and run `ddev restart`:

```yaml
web_environment:
    - LETTERBOX_ACCESS_TOKEN=<at least 32 characters>
```

DDEV merges `web_environment` lists from `config.*.yaml` files with `config.yaml`, so the access line stays ([DDEV docs](https://ddev.readthedocs.io/en/stable/users/extend/customization-extendibility/#extending-configyaml-with-custom-configyaml-files)).
