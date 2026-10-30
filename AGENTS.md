# AGENTS.md

TYPO3 extension `typo3_letterbox` (`konradmichalik/typo3-letterbox`): a mail transport that catches mails in an mbox file and a backend module to read them. TYPO3 13.4 and 14, PHP 8.2 to 8.5.

## Commands

* `ddev composer cgl lint`, `ddev composer cgl fix`, `ddev composer cgl sca`
* `ddev composer cgl migration:rector -- --dry-run`. Without `--dry-run` it rewrites files, check `git status` afterwards.
* Tests run on the host, not in DDEV: `XDEBUG_MODE=off composer test:unit`, `XDEBUG_MODE=off composer test:functional`. With Xdebug on, its `max_nesting_level` breaks a deep-nesting test.
* `ddev composer bundle` bundles non-TYPO3 vendor libraries into `Resources/Private/Libs/` for TER. The folder is ignored by git and built in the release workflow.
* Console commands: `letterbox:list|show|clear|prune|receive` (`ddev exec vendor/bin/typo3 ...`). `list`, `show`, `clear` and `receive` are tagged by hand in `Configuration/Services.php` with `schedulable: false`, because TYPO3 13 has no `#[AsNonSchedulableCommand]`. `prune` is autoconfigured and schedulable.
* `letterbox:receive` is a sendmail replacement (`sendmail_path`). It parses the sendmail flags from `$_SERVER['argv']` in `SendmailArguments`, because TYPO3 binds the input before the command runs and loses every token after the first unknown flag. Smoke test: `printf 'To: b@example.org\nSubject: x\n\nbody\n' | ddev 14 typo3 letterbox:receive -- -t -i`. The leading `--` keeps Symfony from reading sendmail's `-V`/`-h` as `--version`/`--help`; `afterCommand()` skips it.
* Checks tab: `Classes/Check/` (`MailChecker` with one private method per rule, `Finding` holds an XLIFF key below `check.` plus arguments, `SiteHosts`). A new rule needs the key, a unit test and a row in the table in [docs/backend-module.md](docs/backend-module.md#checks).
* Switch: extension setting `enable` and env `LETTERBOX_ENABLE` (`1|true|on`, `0|false|off`, else the setting). `ext_localconf.php` calls `TransportTakeover::apply()`, which makes Letterbox the transport, clears `MAIL/dsn` and keeps the original transport and DSN at runtime in `MAIL/transport_letterbox_takeover`, only to name them in the backend. `TransportSummary` names a transport for the backend (type, or DSN scheme, host and port, never credentials). The switch cannot disable an explicit `additional.php` setup. Letterbox catches every mail and delivers none: there are no modes, catch list, delivery transport or release.
* Module state "not the mail transport": `InactiveScreen` builds its variables, `?browse=1` (carried by `InboxQuery`) opens the list of an earlier mbox file read-only, without "Clear all".
* Flash messages are shown as toasts: `FlashToasts` takes them out of the queue before the module template renders, `InboxController::page()` hands them over as inline setting `Letterbox.toasts`, `inbox.js` shows them with `@typo3/backend/notification.js`.
* `RetentionService` applies the retention limits after the transport and `letterbox:receive` have written a mail.
* `ddev install 13 --classic` sets up a Composer-free TYPO3 to check the bundle.

## Conventions

* The version lives in `composer.json` (`extra.typo3/cms.version`) and `ext_emconf.php`, bumped by `version-bumper`.
* The backend JavaScript module is `Resources/Public/JavaScript/inbox.js`, registered in `Configuration/JavaScriptModules.php`. Fragment requests carry the header `X-Letterbox-Fragment`.
* Every UI change follows [docs/design/rules.md](docs/design/rules.md); target screens are in [docs/design/screens.md](docs/design/screens.md).
* Update the matching page in [docs/](docs/README.md) with every behaviour change.
* External access runs in two frontend middlewares, `konradmichalik/typo3-letterbox/api` and `.../tester-inbox` (`Configuration/RequestMiddlewares.php`, before `typo3/cms-frontend/site`). After changing them flush the caches. `ExternalAccessGuard` decides whether they answer and holds the shared token, see [docs/external-access.md](docs/external-access.md). Root `Production` is always off.
* External access needs the extension setting `externalAccess` (default off, read from `$GLOBALS` in `ExternalAccessGuard`) and the environment variables. `ext_conf_template.txt` groups the settings in tabs with `customcategory`/`customsubcategory`, `basic` holds only `enable`. The `ddev install` hook `.ddev/.setup/hooks/post-typo3-setup.sh` ticks `externalAccess` and `debugMode`.
* External access environment variables: `LETTERBOX_EXTERNAL_ACCESS` (exact context) and `LETTERBOX_ACCESS_TOKEN` (at least 32 characters, shared by the API and the tester inbox). In DDEV `.ddev/config.yaml` sets `LETTERBOX_EXTERNAL_ACCESS=Development`. In DDEV `.ddev/config.yaml` also sets a fixed, publicly known test token, so the tester inbox and the API work out of the box, and it must never be used outside DDEV. A different token goes only in the git-ignored `.ddev/config.local.yaml` (`web_environment` lists are merged with `config.yaml`, the later file wins), then `ddev restart`. Never commit a real token.
* EXT:reports and EXT:dashboard are only suggested. `Classes/Report/` and `Classes/Widgets/` are excluded from autoconfiguration in `Services.yaml` and registered in `Configuration/Services.php` behind `hasDefinition(StatusRegistry::class)` and `hasDefinition(WidgetRegistry::class)`. Never autoconfigure them.
