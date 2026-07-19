<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<p align="center">
  <img src="img/app-color.svg" alt="DBDoctor" width="96" height="96">
</p>

<h1 align="center">DBDoctor</h1>

<p align="center"><em>Your database, but with a regular check-up.</em></p>

DBDoctor audits the database powering your Nextcloud against best-practice rules ported from **phpMyAdmin's advisor** — plus a curated set of PostgreSQL checks — and presents the results as a friendly check-up: a health grade for every rule. Apply safe runtime fixes with one click, or copy a `my.cnf` / `postgresql.conf` snippet for permanent changes.

It is **read-only by default**, **admin-only**, and adds no overhead to your users.

## Highlights

- 70+ MySQL/MariaDB rules ported from phpMyAdmin's advisor
- 6 PostgreSQL checks (cache hit, dead tuples, replication lag, connection saturation, long-running queries, index usage)
- Health grade A–F
- One-click runtime fixes for whitelisted variables, with downloadable config snippet for the rest

## Requirements

- Nextcloud 33
- PHP 8.1 or newer
- MySQL 5.7+ / MariaDB 10.4+ / PostgreSQL 13+

## Building from source

```bash
composer install --no-dev --prefer-dist
npm ci
npm run build
```

Then enable the app:

```bash
sudo -u www-data php /var/www/nextcloud/occ app:enable dbdoctor
```

## Translations

Strings are translated on [Transifex](https://www.transifex.com/nextcloud/nextcloud/) via the
standard Nextcloud translation sync (`.tx/config` + `translationfiles/`). To contribute a
translation, join the Nextcloud Transifex team — compiled `l10n/*.js` / `l10n/*.json` files land
here automatically once the `dbdoctor` resource is activated for the sync bot.

## Credits

The MySQL/MariaDB rule set is ported from the **phpMyAdmin Advisor** , with each rule's upstream `id` preserved so future syncs can be tracked.

- Source: https://github.com/phpmyadmin/phpmyadmin/tree/master/src/Advisory

## License

DBDoctor is licensed under **AGPL-3.0-or-later**. The bundled phpMyAdmin advisor data is GPL-2.0-or-later, retained intact and tracked via [REUSE.toml](REUSE.toml).
