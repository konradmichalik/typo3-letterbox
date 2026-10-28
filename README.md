<div align="center">

![Extension icon](Resources/Public/Icons/Extension.svg)

# TYPO3 extension `typo3_letterbox`

[![Latest Stable Version](https://typo3-badges.dev/badge/typo3_letterbox/version/shields.svg)](https://extensions.typo3.org/extension/typo3_letterbox)
[![Supported TYPO3 versions](https://typo3-badges.dev/badge/typo3_letterbox/typo3/shields.svg)](https://extensions.typo3.org/extension/typo3_letterbox)
[![Supported PHP Versions](https://img.shields.io/packagist/dependency-v/konradmichalik/typo3-letterbox/php?logo=php)](https://packagist.org/packages/konradmichalik/typo3-letterbox)
[![Coverage](https://coveralls.io/repos/github/konradmichalik/typo3-letterbox/badge.svg?branch=main)](https://coveralls.io/github/konradmichalik/typo3-letterbox)
[![CGL](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-letterbox/cgl.yml?label=cgl&logo=github)](https://github.com/konradmichalik/typo3-letterbox/actions/workflows/cgl.yml)
[![Tests](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-letterbox/tests.yml?label=tests&logo=github)](https://github.com/konradmichalik/typo3-letterbox/actions/workflows/tests.yml)
[![License](https://poser.pugx.org/konradmichalik/typo3-letterbox/license)](LICENSE.md)

</div>

Catches TYPO3 mails in an mbox file and shows them in the backend, for local, stage and test systems.

> [!NOTE]
> Test systems must not send real mails to real people, but testers need to see what a mail looks like. Letterbox catches outgoing mails in a local mbox file and shows them in the backend, so nothing leaves the system. It is a rewrite of [`t3/mbox`](https://github.com/a-r-m-i-n/mbox) by Armin Vieweg for TYPO3 13 and 14.

![Letterbox backend module with mail list and HTML preview](docs/images/backend-module.png)

## ✨ Features

* [Mail transport](docs/delivery-modes.md) that appends every caught mail to a local mbox file (RFC 4155)
* Three [delivery modes](docs/delivery-modes.md): `all` catches everything, `listed` catches only chosen recipients and delivers the rest, `off` delivers everything
* [Backend module](docs/backend-module.md) under System › Letterbox with search, quick filters, per-user unread state, detail tabs (HTML, text, headers, source, links, attachments) and delete
* Sandboxed HTML preview with a width switch (desktop, tablet, mobile) that blocks remote images until you load them per mail
* Collapsible mail list and navigation without page reload, with a full-page fallback when JavaScript is off
* [External access](docs/external-access.md), off by default: a token-protected [JSON API](docs/api.md) for end-to-end tests and a read-only tester inbox opened through revocable links
* [Retention](docs/operations.md#retention) that keeps the file small (500 mails or 30 days by default), and [console commands](docs/operations.md#console-commands) to list, show, clear and prune mails
* [Admin signals](docs/operations.md#toolbar-item): a toolbar item with the unread count, a Reports status and a dashboard widget (the last two need `typo3/cms-reports` and `typo3/cms-dashboard`)
* [Test mail command](docs/test-mails.md) for development (`letterbox:testmails:send`, needs `debugMode`)
* Works from Composer and from TER (parser libraries are bundled), see [configuration](docs/configuration.md)

## 🔥 Installation

### Requirements

* TYPO3 13.4 LTS or 14.x
* PHP 8.2, 8.3, 8.4, or 8.5

### Composer

[![Packagist](https://img.shields.io/packagist/v/konradmichalik/typo3-letterbox?label=version&logo=packagist)](https://packagist.org/packages/konradmichalik/typo3-letterbox)
[![Packagist Downloads](https://img.shields.io/packagist/dt/konradmichalik/typo3-letterbox?color=brightgreen)](https://packagist.org/packages/konradmichalik/typo3-letterbox)

```bash
composer require konradmichalik/typo3-letterbox
```

### TER

[![TER version](https://typo3-badges.dev/badge/typo3_letterbox/version/shields.svg)](https://extensions.typo3.org/extension/typo3_letterbox)
[![TER downloads](https://typo3-badges.dev/badge/typo3_letterbox/downloads/shields.svg)](https://extensions.typo3.org/extension/typo3_letterbox)

Download the zip file from [TYPO3 extension repository (TER)](https://extensions.typo3.org/extension/typo3_letterbox).

## 🚀 Quick start

Set the transport in `config/system/additional.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['dsn'] = '';
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = \KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport::class;
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = '/var/www/mbox/mails.mbox';
```

> [!WARNING]
> If `MAIL/dsn` is not empty, TYPO3 core ignores `MAIL/transport` and sends live through the DSN. Letterbox is bypassed. Keep `dsn` empty.

Then open System › Letterbox as an administrator. Outside the exact root context `Production` all mails are caught.

## 📚 Documentation

| Page | What's inside |
|------|---------------|
| [Configuration](docs/configuration.md) | Transport settings and extension settings |
| [Delivery modes](docs/delivery-modes.md) | `all`, `listed` and `off`, defaults per context, personal data |
| [Backend module](docs/backend-module.md) | Search, filters, tabs, preview, navigation, delete and clear |
| [Operations](docs/operations.md) | Retention, console commands, toolbar item, Reports status and dashboard widget |
| [External access](docs/external-access.md) | Activation, guard reasons, IP allowlist, throttle, tester links and the tester inbox |
| [JSON API](docs/api.md) | Endpoints, authentication, response shape, Playwright example |
| [Test mails](docs/test-mails.md) | Console command `letterbox:testmails:send` |
| [Migration from EXT:mbox](docs/migration-from-mbox.md) | Switching from `t3/mbox` to Letterbox |
| [Design rules](docs/design/rules.md) | Binding rules for every UI change |
| [Screen designs](docs/design/screens.md) | Target screens for the backend module |

## 🧑‍💻 Contributing

Please have a look at [`CONTRIBUTING.md`](CONTRIBUTING.md).

## 💎 Credits

Letterbox is forked from [`t3/mbox`](https://github.com/a-r-m-i-n/mbox) by Armin Vieweg. The mbox parser approach comes from [`armin/mbox-parser`](https://github.com/a-r-m-i-n/mbox-parser).

The illustration for the empty and unselected states is "Empty mailbox" by Katerina Limpitsouni from [unDraw](https://undraw.co/), recoloured. It is used under the [unDraw license](https://undraw.co/license) and is not covered by the GPL of this extension.

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
