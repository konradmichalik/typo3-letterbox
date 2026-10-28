# AGENTS.md

TYPO3 extension `typo3_letterbox` (`konradmichalik/typo3-letterbox`): a mail transport that catches mails in an mbox file and a backend module to read them. TYPO3 13.4 and 14, PHP 8.2 to 8.5.

## Commands

* `ddev composer cgl lint`, `ddev composer cgl fix`, `ddev composer cgl sca`
* `ddev composer cgl migration:rector -- --dry-run`. Without `--dry-run` it rewrites files, check `git status` afterwards.
* Tests run on the host, not in DDEV: `XDEBUG_MODE=off composer test:unit`, `XDEBUG_MODE=off composer test:functional`. With Xdebug on, its `max_nesting_level` breaks a deep-nesting test.
* `ddev composer bundle` bundles non-TYPO3 vendor libraries into `Resources/Private/Libs/` for TER. The folder is ignored by git and built in the release workflow.
* Console commands: `letterbox:list|show|clear|prune|receive` (`ddev exec vendor/bin/typo3 ...`). `list`, `show`, `clear` and `receive` are tagged by hand in `Configuration/Services.php` with `schedulable: false`, because TYPO3 13 has no `#[AsNonSchedulableCommand]`. `prune` is autoconfigured and schedulable.
* `letterbox:receive` is a sendmail replacement (`sendmail_path`). It parses the sendmail flags from `$_SERVER['argv']` in `SendmailArguments`, because TYPO3 binds the input before the command runs and loses every token after the first unknown flag. Smoke test: `printf 'To: b@example.org\nSubject: x\n\nbody\n' | ddev 14 typo3 letterbox:receive -- -t -i`. The leading `--` keeps Symfony from reading sendmail's `-V`/`-h` as `--version`/`--help`; `afterCommand()` skips it.
* Release of a caught mail: `ReleaseSettings` (allowlist `MAIL/transport_letterbox_release`, refusals `release_off|release_invalid|delivery_missing|delivery_loops`, plus `MailRelease::SENDER_MISSING` (`sender_missing`) per mail), `MailRelease` (sends through the delivery transport built by core's `TransportFactory`, never through `Mailer`) and `ReleaseController` (POST route `system_letterbox.release`, own controller to stay under the PHPStan complexity limit). UI in `Resources/Public/JavaScript/release.js`. Loop detection lives in `DeliveryLoopGuard`, shared by release and `LetterboxTransport` (which throws in modes `listed`/`off`), and must grow with every new way a delivery can reach `letterbox:receive`.
* Checks tab: `Classes/Check/` (`MailChecker` with one private method per rule, `Finding` holds an XLIFF key below `check.` plus arguments, `SiteHosts`). A new rule needs the key, a unit test and a row in the table in [docs/backend-module.md](docs/backend-module.md#checks).
* `RetentionService` applies the retention limits after the transport and `letterbox:receive` have written a mail.
* `ddev install 13 --classic` sets up a Composer-free TYPO3 to check the bundle.

## Conventions

* The version lives in `composer.json` (`extra.typo3/cms.version`) and `ext_emconf.php`, bumped by `version-bumper`.
* The backend JavaScript module is `Resources/Public/JavaScript/inbox.js`, registered in `Configuration/JavaScriptModules.php`. Fragment requests carry the header `X-Letterbox-Fragment`.
* Every UI change follows [docs/design/rules.md](docs/design/rules.md); target screens are in [docs/design/screens.md](docs/design/screens.md).
* Update the matching page in [docs/](docs/README.md) with every behaviour change.
* External access runs in two frontend middlewares, `konradmichalik/typo3-letterbox/api` and `.../tester-inbox` (`Configuration/RequestMiddlewares.php`, before `typo3/cms-frontend/site`). After changing them flush the caches. `ExternalAccessGuard` decides whether they answer and holds the shared token, see [docs/external-access.md](docs/external-access.md). Root `Production` is always off.
* External access environment variables: `LETTERBOX_EXTERNAL_ACCESS` (exact context) and `LETTERBOX_ACCESS_TOKEN` (at least 32 characters, shared by the API and the tester inbox). In DDEV `.ddev/config.yaml` sets `LETTERBOX_EXTERNAL_ACCESS=Development`. Put the token only in the git-ignored `.ddev/config.local.yaml` (`web_environment` lists are merged with `config.yaml`), then `ddev restart`. Never commit a token.
* EXT:reports and EXT:dashboard are only suggested. `Classes/Report/` and `Classes/Widgets/` are excluded from autoconfiguration in `Services.yaml` and registered in `Configuration/Services.php` behind `hasDefinition(StatusRegistry::class)` and `hasDefinition(WidgetRegistry::class)`. Never autoconfigure them.
