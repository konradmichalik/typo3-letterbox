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
> Test systems must not send real mails to real people, but testers need to see what a mail looks like. Letterbox catches outgoing TYPO3 mails in a local mbox file and shows them in a backend module, so nothing leaves the system. It is a rewrite of [`t3/mbox`](https://github.com/a-r-m-i-n/mbox) by Armin Vieweg for TYPO3 13 and 14.

## ✨ Features

* Mail transport that appends every caught mail to a local mbox file (RFC 4155)
* Three delivery modes: `all` catches everything, `listed` catches only chosen recipients and delivers the rest, `off` delivers everything
* Backend module under System › Letterbox with list, detail view, source, attachment download and delete
* Sandboxed HTML preview that blocks remote content
* Test mail command for development (`letterbox:testmails:send`, needs `debugMode`)
* Works from Composer and from TER (parser libraries are bundled)

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

Then open System › Letterbox as an administrator. Outside the `Production` context all mails are caught. Other modes, recipient lists and the settings in `additional.php` are covered in [`docs/`](docs/README.md).

## 📚 Documentation

* [Configuration](docs/configuration.md)
* [Delivery modes](docs/delivery-modes.md)
* [Migration from EXT:mbox](docs/migration-from-mbox.md)
* [Design rules](docs/design/rules.md) and [screens](docs/design/screens.md)

## 🧑‍💻 Contributing

Please have a look at [`CONTRIBUTING.md`](CONTRIBUTING.md).

## 🙏 Credits

Letterbox is forked from [`t3/mbox`](https://github.com/a-r-m-i-n/mbox) by Armin Vieweg. The mbox parser approach comes from [`armin/mbox-parser`](https://github.com/a-r-m-i-n/mbox-parser).

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
