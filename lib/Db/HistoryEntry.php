<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method string getDbFlavour()
 * @method void setDbFlavour(string $dbFlavour)
 * @method string getDbVersion()
 * @method void setDbVersion(string $dbVersion)
 * @method int getScore()
 * @method void setScore(int $score)
 * @method string getGrade()
 * @method void setGrade(string $grade)
 * @method int getTotalRules()
 * @method void setTotalRules(int $totalRules)
 * @method int getFailedAlerts()
 * @method void setFailedAlerts(int $failedAlerts)
 * @method int getFailedWarnings()
 * @method void setFailedWarnings(int $failedWarnings)
 * @method int getFailedNotices()
 * @method void setFailedNotices(int $failedNotices)
 * @method string getResultsJson()
 * @method void setResultsJson(string $resultsJson)
 */
class HistoryEntry extends Entity {
	// Defaults must NOT equal any real value we ever assign — Entity's
	// setter short-circuits when the new value matches the existing one,
	// which then omits the column from INSERT and trips MySQL strict
	// mode for NOT NULL columns without a SQL default.  See Entity.php:95.
	protected int $createdAt = -1;
	protected string $dbFlavour = '';
	protected string $dbVersion = '';
	protected int $score = -1;
	protected string $grade = '';
	protected int $totalRules = -1;
	protected int $failedAlerts = -1;
	protected int $failedWarnings = -1;
	protected int $failedNotices = -1;
	protected string $resultsJson = '';

	public function __construct() {
		$this->addType('createdAt', 'integer');
		$this->addType('score', 'integer');
		$this->addType('totalRules', 'integer');
		$this->addType('failedAlerts', 'integer');
		$this->addType('failedWarnings', 'integer');
		$this->addType('failedNotices', 'integer');
	}
}
