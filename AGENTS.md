# AGENTS.md

TYPO3 extension `typo3_letterbox` (`konradmichalik/typo3-letterbox`): a mail transport that catches mails in an mbox file and a backend module to read them. TYPO3 13.4 and 14, PHP 8.2 to 8.5.

## Commands

* `ddev composer cgl lint`, `ddev composer cgl fix`, `ddev composer cgl sca`
* `ddev composer cgl migration:rector -- --dry-run`. Without `--dry-run` it rewrites files, check `git status` afterwards.
* `ddev composer test:unit`, `ddev composer test:functional`. Run on the host, set `XDEBUG_MODE=off`: the host Xdebug `max_nesting_level` breaks a deep-nesting test.
* `ddev composer bundle` bundles non-TYPO3 vendor libraries into `Resources/Private/Libs/` for TER. The folder is ignored by git and built in the release workflow.
* `ddev install 13 --classic` sets up a Composer-free TYPO3 to check the bundle.

## Conventions

* The version lives in `composer.json` (`extra.typo3/cms.version`) and `ext_emconf.php`, bumped by `version-bumper`.
* The backend JavaScript module is `Resources/Public/JavaScript/inbox.js`, registered in `Configuration/JavaScriptModules.php`. Fragment requests carry the header `X-Letterbox-Fragment`.
* Every UI change follows [docs/design/rules.md](docs/design/rules.md); target screens are in [docs/design/screens.md](docs/design/screens.md).
* Update the matching page in [docs/](docs/README.md) with every behaviour change.
