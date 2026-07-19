<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Tests\Unit\BackgroundJob;

use OCA\DBDoctor\Advisory\RuleSet\Mysql;
use OCA\DBDoctor\Advisory\RuleSet\Postgres;
use OCA\DBDoctor\BackgroundJob\ScheduledCheck;
use OCA\DBDoctor\Db\HistoryEntry;
use OCA\DBDoctor\Service\Advisor;
use OCA\DBDoctor\Service\DatabaseProbe;
use OCA\DBDoctor\Service\HistoryService;
use OCA\DBDoctor\Service\Score;
use OCA\DBDoctor\Service\Snapshot;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The scheduled job's job is to (a) record a run and (b) notify admins
 * only when health has declined versus the previous run.  These tests
 * pin the notify/don't-notify decision.
 */
final class ScheduledCheckTest extends TestCase {
	private DatabaseProbe $probe;
	private Advisor $advisor;
	private HistoryService $history;
	private INotificationManager $notifications;
	private IGroupManager $groupManager;
	private IAppConfig $appConfig;

	protected function setUp(): void {
		$this->probe = $this->createMock(DatabaseProbe::class);
		$this->advisor = $this->createMock(Advisor::class);
		$this->history = $this->createMock(HistoryService::class);
		$this->notifications = $this->createMock(INotificationManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
	}

	/** Stub the probe/advisor so run() reaches the notify decision. */
	private function wireSupportedRun(): void {
		$this->probe->method('snapshot')
			->willReturn(new Snapshot(Snapshot::FLAVOUR_MYSQL, '8.0.0', [], [], []));
		$this->advisor->method('run')->willReturn([]);
	}

	private function historyEntry(string $grade, int $alerts): HistoryEntry {
		$e = new HistoryEntry();
		$e->setGrade($grade);
		$e->setScore(0);
		$e->setFailedAlerts($alerts);
		$e->setFailedWarnings(0);
		$e->setDbFlavour(Snapshot::FLAVOUR_MYSQL);
		$e->setId(7);
		return $e;
	}

	private function invokeJob(): void {
		$job = new ScheduledCheck(
			$this->createMock(ITimeFactory::class),
			$this->probe,
			$this->advisor,
			new Score(),
			$this->history,
			new Mysql(),
			new Postgres(),
			$this->notifications,
			$this->groupManager,
			$this->appConfig,
			$this->createMock(LoggerInterface::class),
		);
		// Protected members are reflection-accessible without setAccessible() since PHP 8.1.
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	private function expectAdmins(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$user]);
		$this->groupManager->method('get')->with('admin')->willReturn($group);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $m) {
			$notification->method($m)->willReturnSelf();
		}
		$this->notifications->method('createNotification')->willReturn($notification);
	}

	public function test_notifies_admins_when_grade_declines(): void {
		$this->wireSupportedRun();
		$this->appConfig->method('getValueString')->willReturn('yes');
		$this->history->method('latest')->willReturn($this->historyEntry('A', 0)); // previous
		$this->history->method('record')->willReturn($this->historyEntry('B', 2));  // current, worse
		$this->expectAdmins();

		$this->notifications->expects($this->once())->method('notify');

		$this->invokeJob();
	}

	public function test_does_not_notify_when_health_stable(): void {
		$this->wireSupportedRun();
		$this->appConfig->method('getValueString')->willReturn('yes');
		$this->history->method('latest')->willReturn($this->historyEntry('A', 0));
		$this->history->method('record')->willReturn($this->historyEntry('A', 0)); // unchanged

		$this->notifications->expects($this->never())->method('notify');

		$this->invokeJob();
	}

	public function test_disabled_via_app_config_skips_everything(): void {
		$this->appConfig->method('getValueString')->willReturn('no');
		$this->probe->expects($this->never())->method('snapshot');
		$this->notifications->expects($this->never())->method('notify');

		$this->invokeJob();
	}
}
