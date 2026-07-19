<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Tests\Unit\Service;

use OCA\DBDoctor\Db\AuditEntry;
use OCA\DBDoctor\Db\AuditEntryMapper;
use OCA\DBDoctor\Service\DatabaseProbe;
use OCA\DBDoctor\Service\RevertedFixService;
use OCA\DBDoctor\Service\Snapshot;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RevertedFixServiceTest extends TestCase {
	private AuditEntryMapper $audit;
	private DatabaseProbe $probe;
	private RevertedFixService $svc;

	protected function setUp(): void {
		$this->audit = $this->createMock(AuditEntryMapper::class);
		$this->probe = $this->createMock(DatabaseProbe::class);
		$this->svc = new RevertedFixService($this->audit, $this->probe, $this->createMock(LoggerInterface::class));
	}

	private function entry(string $variable, string $newValue, string $ruleId = 'R'): AuditEntry {
		$e = new AuditEntry();
		$e->setVariable($variable);
		$e->setNewValue($newValue);
		$e->setRuleId($ruleId);
		$e->setAppliedAt(1000);
		return $e;
	}

	/**
	 * Wire the probe so withConnection() runs the callback with a mocked
	 * connection + MySQL flavour, and each variable reads back the value
	 * from $liveValues via probe->row().
	 *
	 * @param array<string, string> $liveValues variable → live SHOW GLOBAL value
	 */
	private function wireLive(array $liveValues): void {
		$conn = $this->createMock(IDBConnection::class);
		$this->probe->method('withConnection')
			->willReturnCallback(fn (callable $cb) => $cb($conn, Snapshot::FLAVOUR_MYSQL));
		$this->probe->method('row')
			->willReturnCallback(function ($c, string $sql) use ($liveValues): ?array {
				foreach ($liveValues as $var => $val) {
					if (str_contains($sql, "'" . $var . "'")) {
						return ['Variable_name' => $var, 'Value' => $val];
					}
				}
				return null;
			});
	}

	public function test_flags_variable_whose_live_value_differs(): void {
		$this->audit->method('findLatestSuccessfulPerVariable')
			->willReturn([$this->entry('max_connections', '250')]);
		$this->wireLive(['max_connections' => '151']);

		$reverted = $this->svc->detect();

		$this->assertCount(1, $reverted);
		$this->assertSame('max_connections', $reverted[0]['variable']);
		$this->assertSame('250', $reverted[0]['appliedValue']);
		$this->assertSame('151', $reverted[0]['liveValue']);
	}

	public function test_ignores_variable_still_holding_applied_value(): void {
		$this->audit->method('findLatestSuccessfulPerVariable')
			->willReturn([$this->entry('max_connections', '250')]);
		$this->wireLive(['max_connections' => '250']);

		$this->assertSame([], $this->svc->detect());
	}

	public function test_numeric_equivalence_is_not_flagged(): void {
		// Stored "250" vs live "250 " (whitespace) / "250.0" must not count as reverted.
		$this->audit->method('findLatestSuccessfulPerVariable')
			->willReturn([$this->entry('long_query_time', '2')]);
		$this->wireLive(['long_query_time' => '2.0']);

		$this->assertSame([], $this->svc->detect());
	}

	public function test_no_audit_history_short_circuits_without_touching_db(): void {
		$this->audit->method('findLatestSuccessfulPerVariable')->willReturn([]);
		$this->probe->expects($this->never())->method('withConnection');

		$this->assertSame([], $this->svc->detect());
	}
}
