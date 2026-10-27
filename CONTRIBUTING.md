# Contributing

Thank you for considering contributing to this project! Every contribution is welcome and helps improve the quality of the project. To ensure a smooth process and maintain high code quality, please follow the steps below.

## Requirements

- [DDEV](https://ddev.readthedocs.io/en/stable/)

## Preparation

```bash
# Clone repository
git clone https://github.com/konradmichalik/typo3-letterbox.git
cd typo3-letterbox

# Start the project with DDEV
ddev start

# Install dependencies
ddev composer install
```

If a linter immediately reports `Command "normalize" is not defined`, the isolated `Tests/CGL` toolchain was not installed yet. Run `ddev composer install` a second time (observed on the very first install of a fresh checkout).

## Run linters

```bash
# All linters
ddev composer cgl lint

# Specific linters
ddev composer cgl lint:composer
ddev composer cgl lint:editorconfig
ddev composer cgl lint:language
ddev composer cgl lint:php

# Fix all CGL issues
ddev composer cgl fix

# Fix specific CGL issues
ddev composer cgl fix:composer
ddev composer cgl fix:editorconfig
ddev composer cgl fix:php
```

## Run static code analysis

```bash
# All static code analyzers
ddev composer cgl sca

# Specific static code analyzers
ddev composer cgl sca:php

# Composer dependency analysis
ddev composer cgl analyze
```

## Run tests

```bash
# Unit tests
ddev composer test:unit

# Functional tests
ddev composer test:functional

# Full suite with code coverage
ddev exec "XDEBUG_MODE=coverage composer test:coverage"
```

`ddev composer` always runs with `XDEBUG_MODE=off` to keep dependency resolution fast, which also disables coverage for anything it calls internally. Use `ddev exec` with `XDEBUG_MODE=coverage` set explicitly instead.

Coverage is measured on `Classes/` only. On a freshly scaffolded extension `Classes/` is still empty, so `test:coverage` fails until the first real class exists.

## TYPO3 Setup

For testing the extension, you need to set up the TYPO3 instances.

```bash
# Install all TYPO3 versions, which are supported by the extension
ddev install all

# Or install specific TYPO3 versions
ddev install 13
ddev install 14

# Open the overview page
ddev launch

# Run TYPO3 specific commands
ddev 14 typo3 cache:flush
ddev all typo3 database:updateschema
```

## Submit a pull request

After completing your work, **open a pull request** and provide a description of your changes. Ideally, your PR should reference an issue that explains the problem you are addressing.

All mentioned code quality tools will run automatically on every pull request. For more details, see the relevant [workflows][1].

[1]: .github/workflows
