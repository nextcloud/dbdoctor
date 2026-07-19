<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\BackgroundJob;

use OCA\DBDoctor\AppInfo\Application;
use OCA\DBDoctor\Advisory\RuleSet\Mysql as MysqlRuleSet;
use OCA\DBDoctor\Advisory\RuleSet\Postgres as PostgresRuleSet;
use OCA\DBDoctor\Db\HistoryEntry;
use OCA\DBDoctor\Service\Advisor;
use OCA\DBDoctor\Service\DatabaseProbe;
use OCA\DBDoctor\Service\HistoryService;
use OCA\DBDoctor\Service\Score;
use OCA\DBDoctor\Service\Snapshot;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Weekly automatic check-up.
 *
 * Runs the advisor without anyone clicking "Run check now", so the
 * 30-day history/sparklines keep accumulating and problems surface on
 * installs where nobody remembers to open the page.  When the health
 * declines compared to the previous stored run — a worse letter grade
 * or more failing alerts — every member of the admin group gets a
 * Nextcloud notification.
 *
 * Opt-out: `occ config:app:set dbdoctor scheduled_checks --value=no`.
 */
class ScheduledCheck extends TimedJob {
	private const INTERVAL_SECONDS = 7 * 24 * 3600;
	private const GRADE_RANK = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'F' => 4];

	public function __construct(
		ITimeFactory $time,
		private DatabaseProbe $probe,
		private Advisor $advisor,
		private Score $score,
		private HistoryService $history,
		private MysqlRuleSet $mysqlRules,
		private PostgresRuleSet $postgresRules,
		private INotificationManager $notifications,
		private IGroupManager $groupManager,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
		// Health drift is a slow signal; running during the low-load
		// window is fine and keeps the check off peak hours.
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run(mixed $argument): void {
		if ($this->appConfig->getValueString(Application::APP_ID, 'scheduled_checks', 'yes') === 'no') {
			return;
		}

		try {
			$snapshot = $this->probe->snapshot();
			if (!$snapshot->isSupported()) {
				return;
			}

			$previous = $this->history->latest($snapshot->flavour);

			$ruleSet = $snapshot->flavour === Snapshot::FLAVOUR_PGSQL ? $this->postgresRules : $this->mysqlRules;
			$results = $this->advisor->run($ruleSet, $snapshot);
			$score = $this->score->compute($results);
			$entry = $this->history->record($snapshot, $results, $score);

			$this->logger->info(
				'DBDoctor: scheduled check completed with grade {grade} (score {score})',
				['grade' => $score['grade'], 'score' => $score['score'], 'app' => 'dbdoctor'],
			);

			if ($previous !== null && $this->declined($previous, $entry)) {
				$this->notifyAdmins($previous, $entry);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'DBDoctor: scheduled check failed: {msg}',
				['msg' => $e->getMessage(), 'app' => 'dbdoctor', 'exception' => $e],
			);
		}
	}

	private function declined(HistoryEntry $previous, HistoryEntry $current): bool {
		$prevRank = self::GRADE_RANK[$previous->getGrade()] ?? 0;
		$currRank = self::GRADE_RANK[$current->getGrade()] ?? 0;
		return $currRank > $prevRank
			|| $current->getFailedAlerts() > $previous->getFailedAlerts();
	}

	private function notifyAdmins(HistoryEntry $previous, HistoryEntry $current): void {
		$admins = $this->groupManager->get('admin')?->getUsers() ?? [];

		foreach ($admins as $admin) {
			$notification = $this->notifications->createNotification();
			$notification
				->setApp(Application::APP_ID)
				->setUser($admin->getUID())
				->setDateTime(new \DateTime())
				->setObject('check', (string)$current->getId())
				->setSubject('health_declined', [
					'oldGrade' => $previous->getGrade(),
					'newGrade' => $current->getGrade(),
					'score' => $current->getScore(),
					'alerts' => $current->getFailedAlerts(),
				]);
			$this->notifications->notify($notification);
		}
	}
}
