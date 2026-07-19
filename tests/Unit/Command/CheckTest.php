<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Tests\Unit\Command;

use OCA\DBDoctor\Advisory\Rule;
use OCA\DBDoctor\Advisory\RuleResult;
use OCA\DBDoctor\Advisory\RuleSet\Mysql;
use OCA\DBDoctor\Advisory\RuleSet\Postgres;
use OCA\DBDoctor\Command\Check;
use OCA\DBDoctor\Service\Advisor;
use OCA\DBDoctor\Service\DatabaseProbe;
use OCA\DBDoctor\Service\HistoryService;
use OCA\DBDoctor\Service\Score;
use OCA\DBDoctor\Service\Snapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command's Nagios-style exit codes are its monitoring contract, so
 * they're the thing worth pinning: 0 clean, 1 warnings, 2 alerts, 3
 * unsupported.
 */
final class CheckTest extends TestCase {
	private DatabaseProbe $probe;
	private Advisor $advisor;

	protected function setUp(): void {
		$this->probe = $this->createMock(DatabaseProbe::class);
		$this->advisor = $this->createMock(Advisor::class);
	}

	private function tester(): CommandTester {
		$command = new Check(
			$this->probe,
			$this->advisor,
			new Score(),
			$this->createMock(HistoryService::class),
			new Mysql(),
			new Postgres(),
		);
		return new CommandTester($command);
	}

	private function mysqlSnapshot(): Snapshot {
		return new Snapshot(Snapshot::FLAVOUR_MYSQL, '8.0.0', [], [], []);
	}

	private function failing(string $severity): RuleResult {
		$rule = new Rule(
			id: 'X_' . $severity,
			name: 'X',
			category: 'Test',
			severity: $severity,
			formula: '0',
			test: 'value >= 0',
			issue: 'issue',
			recommendation: 'fix it',
			justification: '',
			justificationFormula: '',
		);
		return new RuleResult($rule, RuleResult::STATUS_FAIL, justification: 'because');
	}

	public function test_exit_code_2_when_alerts_present(): void {
		$this->probe->method('snapshot')->willReturn($this->mysqlSnapshot());
		$this->advisor->method('run')->willReturn([$this->failing(Rule::SEVERITY_ALERT)]);

		$tester = $this->tester();
		$tester->execute(['--no-store' => true]);

		$this->assertSame(2, $tester->getStatusCode());
	}

	public function test_exit_code_1_when_only_warnings(): void {
		$this->probe->method('snapshot')->willReturn($this->mysqlSnapshot());
		$this->advisor->method('run')->willReturn([$this->failing(Rule::SEVERITY_WARNING)]);

		$tester = $this->tester();
		$tester->execute(['--no-store' => true]);

		$this->assertSame(1, $tester->getStatusCode());
	}

	public function test_exit_code_0_when_clean(): void {
		$this->probe->method('snapshot')->willReturn($this->mysqlSnapshot());
		$this->advisor->method('run')->willReturn([]);

		$tester = $this->tester();
		$tester->execute(['--no-store' => true]);

		$this->assertSame(0, $tester->getStatusCode());
	}

	public function test_exit_code_3_and_no_advisor_run_when_unsupported(): void {
		$this->probe->method('snapshot')
			->willReturn(new Snapshot(Snapshot::FLAVOUR_UNSUPPORTED, '', [], [], []));
		$this->advisor->expects($this->never())->method('run');

		$tester = $this->tester();
		$tester->execute(['--no-store' => true]);

		$this->assertSame(3, $tester->getStatusCode());
	}

	public function test_json_output_is_valid_and_carries_grade(): void {
		$this->probe->method('snapshot')->willReturn($this->mysqlSnapshot());
		$this->advisor->method('run')->willReturn([$this->failing(Rule::SEVERITY_ALERT)]);

		$tester = $this->tester();
		$tester->execute(['--output' => 'json', '--no-store' => true]);

		$decoded = json_decode(trim($tester->getDisplay()), true);
		$this->assertIsArray($decoded);
		$this->assertSame('mysql', $decoded['dbFlavour']);
		$this->assertArrayHasKey('grade', $decoded);
		$this->assertArrayHasKey('results', $decoded);
		$this->assertSame(1, $decoded['counts']['alert']);
	}

	public function test_invalid_output_format_is_rejected(): void {
		$tester = $this->tester();
		$tester->execute(['--output' => 'yaml', '--no-store' => true]);

		$this->assertSame(3, $tester->getStatusCode());
	}
}
