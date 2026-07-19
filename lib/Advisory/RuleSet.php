<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Advisory;

/**
 * A bundle of rules keyed to a database flavour.
 *
 * Each flavour ships its own implementation:
 *  - {@see RuleSet\Mysql}    — ports phpMyAdmin's advisor (covers MariaDB too)
 *  - {@see RuleSet\Postgres} — original DBDoctor rules
 */
interface RuleSet {
	/**
	 * @return list<Rule>
	 */
	public function rules(): array;

	/**
	 * Flavour identifier the rule set targets ('mysql' | 'mariadb' | 'pgsql').
	 * Matched against {@see \OCA\DBDoctor\Service\Snapshot::$flavour}.
	 */
	public function flavour(): string;
}
