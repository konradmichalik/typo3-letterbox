# External access

Back to [documentation overview](README.md).

Letterbox can answer outside the backend: a [JSON API](api.md) for automated tests and a read-only inbox for testers without a backend account. Both are off until you switch them on, and every refusal is an empty-looking `404`, so a scanner cannot tell the surface exists.

> [!WARNING]
> Everyone with the inbox link or the token sees every caught mail of that system, including password reset links. External access is meant for test systems without real data. It is always off in the root context `Production`.

## Activation

Tick "Enable external access" (`externalAccess`) in the [extension settings](configuration.md#extension-settings). It is off by default and only one of the conditions: the environment variables below and the other checks still have to pass.

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
| `setting_off` | "Enable external access" is not ticked in the extension settings | Tick it |
| `env_missing` | `LETTERBOX_EXTERNAL_ACCESS` is unset or empty | Set it to the current context |
| `env_context_mismatch` | The value differs from the context | Set it to exactly the context shown |
| `production` | The context is the root context `Production` | Nothing, external access is never on there. Use a test system with its own context such as `Production/Staging` |
| `transport_not_letterbox` | `MAIL/transport` is not the Letterbox transport | See [configuration](configuration.md#transport) |
| `token_missing` | `LETTERBOX_ACCESS_TOKEN` is unset or shorter than 32 characters | Set a random secret of at least 32 characters |
| `https_required` | The request came in over plain HTTP | Open the site over HTTPS |

## Tester inbox

Copy the tester link from the backend page "External access" (it has a copy button) and share it with your testers. It has the form `<site URL>_letterbox/inbox?t=<link secret>`. The link secret is `hash_hmac('sha256', 'tester-link', <token>)`, 64 hex characters. It opens the read-only inbox only: the API never accepts it, and the inbox never accepts the raw token in `?t=`. A leaked tester link therefore exposes the mails but cannot delete them or download sources.

To build the link without the backend:

```bash
php -r 'echo hash_hmac("sha256", "tester-link", getenv("LETTERBOX_ACCESS_TOKEN")), PHP_EOL;'
```

* Opening the link checks the link secret and answers with a `303` to the clean URL `<site URL>_letterbox/inbox`, so the link secret leaves the address bar. The redirect sets an `HttpOnly`, `Secure`, `SameSite=Lax` cookie for that path, valid for 7 days. `Lax` keeps the cookie on the redirect when the link is clicked in webmail, chat or a ticket.
* The cookie holds its issue time and an HMAC of that time keyed with the token, never the token itself. The server refuses a cookie older than 7 days or with a changed time, so a copied cookie stops working after a week, whatever its `Expires` says. Opening the tester link again starts a new week.
* Every request checks the cookie again, so changing `LETTERBOX_ACCESS_TOKEN` ends every session and invalidates the tester link at once. That is the only way to revoke access, and it revokes it for everyone.
* The inbox lists all caught mails with search and pagination. It shows subject, sender, recipients, date, an HTML view and a text view, the links in the mail, and a button for the first password reset link. It lists the attachments of a mail and lets the tester download them.
* It never shows headers, the source or `.eml` files and runs no JavaScript. The HTML view is a sandboxed `srcdoc` frame. Remote images are blocked. "Load remote images" reloads that one mail with `?images=1`: the page policy then allows `img-src https:` for that response only, never in the text view, the list or any other mail. Loading them tells the sender the tester's IP address and that the mail was opened, which the notice says.
* The "Mails are not delivered" badge in the header explains itself on hover and on keyboard focus (CSS only, the page runs no script). The inbox only answers GET and changes nothing. A tester cannot delete mails.
* It works without a configured site and in maintenance mode, and answers are never cached.

## Host header

TYPO3 checks the `Host` header before Letterbox runs. With a wrong host and `SYS/trustedHostsPattern` set, core throws an error, not a `404`. Set `trustedHostsPattern` on every system with external access.

## Backend password reset

Letterbox hides every mail with a link of the TYPO3 backend password reset (path `/typo3/login/password-reset/`) from the tester inbox and the API. Such a mail is missing from lists, counts, search and `latest`, and its detail, source and attachments answer `404`, as does deleting it. Reset links of frontend users (felogin) stay visible. The backend module still shows the mail. The check runs when the mail is indexed and looks at mails up to 1 MiB, which covers the mail TYPO3 sends.

The filter does not replace the settings: a mail that reaches the mbox file by another route and links elsewhere is not covered. Set `BE/passwordResetForAdmins` to `false` so administrators cannot request a reset mail, or `BE/passwordReset` to `false` to turn the reset off.

## DDEV

`.ddev/config.yaml` sets `LETTERBOX_EXTERNAL_ACCESS=Development` and a fixed test token, and `ddev install` ticks "Enable external access", so the tester inbox and the API work right after `ddev install`. The token is public in this repository, so it is for DDEV only: never reuse it, and do not run `ddev share` with it. To use your own token, put it in the git-ignored `.ddev/config.local.yaml` and run `ddev restart`. The later file wins:

```yaml
web_environment:
    - LETTERBOX_ACCESS_TOKEN=<at least 32 characters>
```

DDEV merges `web_environment` lists from `config.*.yaml` files with `config.yaml`, so the access line stays ([DDEV docs](https://ddev.readthedocs.io/en/stable/users/extend/customization-extendibility/#extending-configyaml-with-custom-configyaml-files)).
