<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\SetupChecks;

use OCA\DBDoctor\AppInfo\Application;
use OCA\DBDoctor\Advisory\RuleSet\Mysql as MysqlRuleSet;
use OCA\DBDoctor\Advisory\RuleSet\Postgres as PostgresRuleSet;
use OCA\DBDoctor\Service\Advisor;
use OCA\DBDoctor\Service\DatabaseProbe;
use OCA\DBDoctor\Service\HistoryService;
use OCA\DBDoctor\Service\Score;
use OCA\DBDoctor\Service\Snapshot;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Surfaces the DB Doctor health grade in Settings → Overview, where
 * admins already look for problems.
 *
 * Uses the most recent stored run when it's less than a day old;
 * otherwise runs the advisor live (read-only, without recording) so
 * the result reflects the current server rather than stale history.
 */
class DatabaseHealth implements ISetupCheck {
	private const FRESH_SECONDS = 86400;

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private DatabaseProbe $probe,
		private Advisor $advisor,
		private Score $score,
		private HistoryService $history,
		private MysqlRuleSet $mysqlRules,
		private PostgresRuleSet $postgresRules,
	) {
	}

	public function getCategory(): string {
		return 'database';
	}

	public function getName(): string {
		return $this->l10n->t('Database health (DB Doctor)');
	}

	public function run(): SetupResult {
		try {
			[$grade, $score, $alerts, $warnings] = $this->currentHealth();
		} catch (\Throwable $e) {
			return SetupResult::info(
				$this->l10n->t('DB Doctor could not check the database: %s', [$e->getMessage()]),
			);
		}

		if ($grade === null) {
			return SetupResult::info(
				$this->l10n->t('DB Doctor only supports MySQL, MariaDB, and PostgreSQL.'),
			);
		}

		$settingsUrl = $this->urlGenerator->linkToRouteAbsolute(
			'settings.AdminSettings.index',
			['section' => Application::APP_ID],
		);

		if ($alerts > 0) {
			return SetupResult::warning(
				$this->l10n->n(
					'Database health grade %1$s (score %2$d/100) with %n alert. Review the findings in the DB Doctor admin settings: %3$s',
					'Database health grade %1$s (score %2$d/100) with %n alerts. Review the findings in the DB Doctor admin settings: %3$s',
					$alerts,
					[$grade, $score, $settingsUrl],
				),
			);
		}

		if ($warnings > 0) {
			return SetupResult::info(
				$this->l10n->n(
					'Database health grade %1$s (score %2$d/100) with %n warning. See the DB Doctor admin settings for tuning suggestions: %3$s',
					'Database health grade %1$s (score %2$d/100) with %n warnings. See the DB Doctor admin settings for tuning suggestions: %3$s',
					$warnings,
					[$grade, $score, $settingsUrl],
				),
			);
		}

		return SetupResult::success(
			$this->l10n->t('Database health grade %1$s (score %2$d/100).', [$grade, $score]),
		);
	}

	/**
	 * @return array{0: ?string, 1: int, 2: int, 3: int} [grade, score, alerts, warnings];
	 *                                                    grade is null for unsupported databases.
	 */
	private function currentHealth(): array {
		$latest = $this->history->latest();
		if ($latest !== null && (time() - $latest->getCreatedAt()) < self::FRESH_SECONDS) {
			return [
				$latest->getGrade(),
				$latest->getScore(),
				$latest->getFailedAlerts(),
				$latest->getFailedWarnings(),
			];
		}

		$snapshot = $this->probe->snapshot();
		if (!$snapshot->isSupported()) {
			return [null, 0, 0, 0];
		}
		$ruleSet = $snapshot->flavour === Snapshot::FLAVOUR_PGSQL ? $this->postgresRules : $this->mysqlRules;
		$results = $this->advisor->run($ruleSet, $snapshot);
		$score = $this->score->compute($results);

		return [
			$score['grade'],
			$score['score'],
			$score['counts']['alert'] ?? 0,
			$score['counts']['warning'] ?? 0,
		];
	}
}
