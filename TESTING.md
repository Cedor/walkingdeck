# Automated testing

The test suite is designed to give fast feedback on every pushed commit while
keeping Board Game Arena framework dependencies outside the unit-test runtime.

## Local prerequisites

- PHP 7.4 or PHP 8.2 with Composer
- Node.js 22 with npm (the tests use Node's built-in test runner)
- MariaDB 10.11 for the optional database checks
- Xdebug when generating PHP coverage

Install the dependencies once:

```bash
composer install
npm install
```

## Fast checks

Run these before every commit:

```bash
composer lint
composer test
npm run lint
npm test
```

`composer lint` intentionally excludes `_ide_helper.php`. That BGA-generated
IDE helper uses newer PHP syntax and is not application runtime code.

## Database checks

Create an empty database, then run:

```bash
mysql walkingdeck_test < dbmodel.sql
mysql walkingdeck_test < tests/sql/assert-schema.sql
```

These checks validate fixture counts, JSON consequences, card display-text
documents and foreign-key relationships. Card display documents may only use
the `black`, `white`, `grey`, and `special` zones, whose supported properties
are `text` and `args`. An argument used as an inline icon is an object with
`type: "icon"` and the sprite name in `name`. GitHub Actions creates a
disposable MariaDB service for these checks.

## Coverage

Generate and enforce the initial 60% statement threshold for the isolated PHP
services:

```bash
mkdir -p build
composer test:coverage
php scripts/check-coverage.php build/coverage.xml 60
```

The initial coverage scope contains `TWDDeck`, `TWDDisaster`, `TWDEventStack`,
and `TWDRessources`. `Game` is tested for its pure rules, but it is omitted from
the threshold until more BGA framework interactions have adapters.

## Continuous integration

`.github/workflows/ci.yml` runs on every push, pull request, and manual
dispatch. It contains independent jobs for:

1. PHP and JavaScript syntax plus JSON validation.
2. PHPUnit on PHP 7.4 and PHP 8.2.
3. PHP coverage with a 60% minimum.
4. Native Node.js client tests.
5. MariaDB schema and reference-data assertions.

Configure the repository's default branch protection so all five jobs are
required before merging.

## Test expansion protocol

Every bug fix should start with a failing regression test. New game rules
should cover their successful path, rejected inputs, boundary values, state
transition, and emitted notification.

Keep pure rules in small PHP or JavaScript functions. Interactions with BGA
decks, state storage, notifications, and the state machine should cross a thin
adapter that can be replaced by a test fake.

Browser-level tests require a deployable BGA test table, which GitHub-hosted
runners do not currently have. Keep a manual release checklist for protagonist
selection, normal and special draws, playing cards to each destination,
resource consumption, disaster draws, story checks, loss, and page reload.
