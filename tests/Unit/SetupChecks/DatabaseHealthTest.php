<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Tests\Unit\SetupChecks;

use OCA\DBDoctor\Advisory\RuleSet\Mysql;
use OCA\DBDoctor\Advisory\RuleSet\Postgres;
use OCA\DBDoctor\Db\HistoryEntry;
use OCA\DBDoctor\Service\Advisor;
use OCA\DBDoctor\Service\DatabaseProbe;
use OCA\DBDoctor\Service\HistoryService;
use OCA\DBDoctor\Service\Score;
use OCA\DBDoctor\Service\Snapshot;
use OCA\DBDoctor\SetupChecks\DatabaseHealth;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;

/**
 * The setup check surfaces the health grade in Settings → Overview and
 * maps failing alerts / warnings to the right result severity.  When a
 * recent stored run exists it must be reused rather than re-probing.
 */
final class DatabaseHealthTest extends TestCase {
	private HistoryService $history;
	private DatabaseProbe $probe;
	private DatabaseHealth $check;

	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('n')->willReturnCallback(
			static fn (string $s, string $p, int $count) => $count === 1 ? $s : $p,
		);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://cloud.example/settings');

		$this->history = $this->createMock(HistoryService::class);
		$this->probe = $this->createMock(DatabaseProbe::class);

		$this->check = new DatabaseHealth(
			$l10n,
			$url,
			$this->probe,
			$this->createMock(Advisor::class),
			new Score(),
			$this->history,
			new Mysql(),
			new Postgres(),
		);
	}

	private function freshEntry(string $grade, int $alerts, int $warnings): HistoryEntry {
		$e = new HistoryEntry();
		$e->setGrade($grade);
		$e->setScore(88);
		$e->setFailedAlerts($alerts);
		$e->setFailedWarnings($warnings);
		$e->setCreatedAt(time()); // fresh → reused, no live probe
		return $e;
	}

	public function test_alerts_map_to_warning_result(): void {
		$this->history->method('latest')->willReturn($this->freshEntry('D', 3, 1));
		$this->probe->expects($this->never())->method('snapshot');

		$this->assertSame(SetupResult::WARNING, $this->check->run()->getSeverity());
	}

	public function test_warnings_only_map_to_info_result(): void {
		$this->history->method('latest')->willReturn($this->freshEntry('B', 0, 2));

		$this->assertSame(SetupResult::INFO, $this->check->run()->getSeverity());
	}

	public function test_clean_maps_to_success_result(): void {
		$this->history->method('latest')->willReturn($this->freshEntry('A', 0, 0));

		$this->assertSame(SetupResult::SUCCESS, $this->check->run()->getSeverity());
	}

	public function test_unsupported_database_maps_to_info(): void {
		// No stored run → probe live; unsupported flavour → info, not error.
		$this->history->method('latest')->willReturn(null);
		$this->probe->method('snapshot')
			->willReturn(new Snapshot(Snapshot::FLAVOUR_UNSUPPORTED, '', [], [], []));

		$this->assertSame(SetupResult::INFO, $this->check->run()->getSeverity());
	}
}
