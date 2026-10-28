# AGENTS.md

TYPO3 extension `typo3_letterbox` (`konradmichalik/typo3-letterbox`): a mail transport that catches mails in an mbox file and a backend module to read them. TYPO3 13.4 and 14, PHP 8.2 to 8.5.

## Commands

* `ddev composer cgl lint`, `ddev composer cgl fix`, `ddev composer cgl sca`
* `ddev composer cgl migration:rector -- --dry-run`. Without `--dry-run` it rewrites files, check `git status` afterwards.
* `ddev composer test:unit`, `ddev composer test:functional`. Run on the host, set `XDEBUG_MODE=off`: the host Xdebug `max_nesting_level` breaks a deep-nesting test.
* `ddev composer bundle` bundles non-TYPO3 vendor libraries into `Resources/Private/Libs/` for TER. The folder is ignored by git and built in the release workflow.
* Console commands: `letterbox:list|show|clear|prune` (`ddev exec vendor/bin/typo3 ...`). `list`, `show` and `clear` are tagged by hand in `Configuration/Services.php` with `schedulable: false`, because TYPO3 13 has no `#[AsNonSchedulableCommand]`. `prune` is autoconfigured and schedulable.
* `ddev install 13 --classic` sets up a Composer-free TYPO3 to check the bundle.

## Conventions

* The version lives in `composer.json` (`extra.typo3/cms.version`) and `ext_emconf.php`, bumped by `version-bumper`.
* The backend JavaScript module is `Resources/Public/JavaScript/inbox.js`, registered in `Configuration/JavaScriptModules.php`. Fragment requests carry the header `X-Letterbox-Fragment`.
* Every UI change follows [docs/design/rules.md](docs/design/rules.md); target screens are in [docs/design/screens.md](docs/design/screens.md).
* Update the matching page in [docs/](docs/README.md) with every behaviour change.
* External access runs in two frontend middlewares, `konradmichalik/typo3-letterbox/api` and `.../tester-inbox` (`Configuration/RequestMiddlewares.php`, before `typo3/cms-frontend/site`). After changing them flush the caches. `ExternalAccessGuard` decides whether they answer, see [docs/external-access.md](docs/external-access.md). Tester links live in `tx_typo3letterbox_tester_link` (`ext_tables.sql`).
* External access environment variables: `LETTERBOX_EXTERNAL_ACCESS` (exact context), `LETTERBOX_EXTERNAL_ALLOWED_IPS`, `LETTERBOX_API_TOKEN`. In DDEV `.ddev/config.yaml` sets `LETTERBOX_EXTERNAL_ACCESS=Development`. Put the API token only in the git-ignored `.ddev/config.local.yaml` (`web_environment` lists are merged with `config.yaml`).
* DDEV installs set up before `ext_tables.sql` existed need that file linked into `.Build/{13,14}/packages/typo3_letterbox/`, then `ddev 13|14 typo3 database:updateschema safe,destructive` (a `*` argument gets globbed by the DDEV wrapper).
* EXT:reports and EXT:dashboard are only suggested. `Classes/Report/` and `Classes/Widgets/` are excluded from autoconfiguration in `Services.yaml` and registered in `Configuration/Services.php` behind `hasDefinition(StatusRegistry::class)` and `hasDefinition(WidgetRegistry::class)`. Never autoconfigure them.
