<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Tests\Unit\Service;

use OCA\DBDoctor\Service\ApplyService;
use OCA\DBDoctor\Service\DatabaseProbe;
use OCA\DBDoctor\Service\Snapshot;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ApplyService is the only class in DBDoctor that mutates server state,
 * which makes it the highest-leverage place for tests.  We assert:
 *
 *  - the allow-lists are honoured per flavour (no SQL is sent for
 *    variables outside the list);
 *  - validateValue rejects every shape we expect it to reject before
 *    any literal-rendering happens;
 *  - the SQL that ends up in executeStatement matches the literal-form
 *    the corresponding variable expects (ON/OFF unquoted, K/M/G byte
 *    suffixes expanded to plain bytes — SET GLOBAL doesn't accept
 *    suffix literals — enums + Postgres byte strings routed through
 *    DBAL's quote()).
 */
final class ApplyServiceTest extends TestCase {
	private DatabaseProbe $probe;
	private IDBConnection $conn;
	private LoggerInterface $logger;
	private ApplyService $svc;

	/** @var list<string> SQL captured from executeStatement calls during a test. */
	private array $executed = [];

	protected function setUp(): void {
		$this->probe = $this->createMock(DatabaseProbe::class);
		$this->conn = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// readGlobal() uses prepare()->execute()->fetch().  Tests don't
		// care about the audit-log's old/new values so wire a no-op
		// path that returns an empty fetch.
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('execute')->willReturn($result);
		$this->conn->method('prepare')->willReturn($stmt);

		// quote() mirrors the standard-conforming-strings form so the
		// rendered SQL is deterministic for assertion.  The real
		// connection's quote() may differ (NO_BACKSLASH_ESCAPES) but
		// that's not what we're testing — we're testing that ApplyService
		// delegates to quote() instead of building the literal itself.
		$this->conn->method('quote')
			->willReturnCallback(static fn ($v) => "'" . str_replace("'", "''", (string)$v) . "'");

		// Capture every SET / ALTER SYSTEM that runs during a test.
		$this->executed = [];
		$this->conn->method('executeStatement')
			->willReturnCallback(function (string $sql): int {
				$this->executed[] = $sql;
				return 0;
			});

		$this->svc = new ApplyService($this->probe, $this->logger);
	}

	/**
	 * ApplyService routes the whole operation through
	 * DatabaseProbe::withConnection() so a configured override
	 * connection is honoured.  Simulate that by invoking the callback
	 * with the mocked connection and the flavour under test.
	 */
	private function wireFlavour(string $flavour): void {
		$this->probe->method('withConnection')
			->willReturnCallback(fn (callable $cb) => $cb($this->conn, $flavour));
	}

	// ── Allow-list ─────────────────────────────────────────────────

	public function test_rejects_variable_not_on_mysql_allow_list(): void {
		$this->wireFlavour(Snapshot::FLAVOUR_MYSQL);
		$this->expectException(\InvalidArgumentException::class);
		try {
			$this->svc->apply('general_log_file', '/tmp/x.log');
		} finally {
			$this->assertSame([], $this->executed, 'No SQL must be issued for a rejected variable.');
		}
	}

	public function test_rejects_variable_not_on_pgsql_allow_list(): void {
		$this->wireFlavour(Snapshot::FLAVOUR_PGSQL);
		$this->expectException(\InvalidArgumentException::class);
		try {
			$this->svc->apply('superuser_reserved_connections', '5');
		} finally {
			$this->assertSame([], $this->executed);
		}
	}

	public function test_rejects_unsupported_flavour(): void {
		$this->wireFlavour(Snapshot::FLAVOUR_UNSUPPORTED);
		$this->expectException(\InvalidArgumentException::class);
		try {
			$this->svc->apply('max_connections', '100');
		} finally {
			$this->assertSame([], $this->executed);
		}
	}

	// ── Value validation ───────────────────────────────────────────

	/**
	 * @return list<array{string, string, string}> [flavour, variable, badValue]
	 */
	public static function rejectedValues(): array {
		return [
			'empty value'                 => [Snapshot::FLAVOUR_MYSQL,   'max_connections',     ''],
			'negative integer'            => [Snapshot::FLAVOUR_MYSQL,   'max_connections',     '-5'],
			'sql injection attempt'       => [Snapshot::FLAVOUR_MYSQL,   'max_connections',     "100; DROP TABLE oc_users"],
			'whitespace + suffix injection' => [Snapshot::FLAVOUR_MYSQL, 'innodb_buffer_pool_size', "128M' --"],
			'innodb_flush_method bogus'   => [Snapshot::FLAVOUR_MYSQL,   'innodb_flush_method', 'bogus'],
			'innodb_flush_method quoted'  => [Snapshot::FLAVOUR_MYSQL,   'innodb_flush_method', "fsync'"],
			'long_query_time non-numeric' => [Snapshot::FLAVOUR_MYSQL,   'long_query_time',     'fast'],
			'slow_query_log out of range' => [Snapshot::FLAVOUR_MYSQL,   'slow_query_log',      '2'],
			'work_mem with junk suffix'   => [Snapshot::FLAVOUR_PGSQL,   'work_mem',            "64MB' OR 1=1"],
			'random_page_cost negative'   => [Snapshot::FLAVOUR_PGSQL,   'random_page_cost',    '-1.5'],
			'byte-suffix int overflow'    => [Snapshot::FLAVOUR_MYSQL,   'innodb_buffer_pool_size', '99999999999999999G'],
		];
	}

	#[DataProvider('rejectedValues')]
	public function test_validate_value_rejects_bad_shapes(string $flavour, string $variable, string $value): void {
		$this->wireFlavour($flavour);
		$this->expectException(\InvalidArgumentException::class);
		try {
			$this->svc->apply($variable, $value);
		} finally {
			$this->assertSame([], $this->executed, 'No SQL must be issued for a rejected value.');
		}
	}

	// ── MySQL literal rendering ────────────────────────────────────

	/**
	 * @return list<array{string, string, string}> [variable, value, expectedSqlSuffix]
	 *         expectedSqlSuffix is the part after `SET GLOBAL \`var\` = `.
	 */
	public static function mysqlRendering(): array {
		return [
			// SET GLOBAL does not accept K/M/G suffix literals (those are
			// option-file syntax only), so suffixed values must arrive at
			// the server expanded to plain bytes.
			'plain int'                  => ['max_connections',         '250',     '250'],
			'byte-suffix M → bytes'      => ['innodb_buffer_pool_size', '128M',    '134217728'],
			'byte-suffix G → bytes'      => ['innodb_buffer_pool_size', '2G',      '2147483648'],
			'byte-suffix lowercase k'    => ['key_buffer_size',         '512k',    '524288'],
			'on/off keyword ON'          => ['slow_query_log',          'ON',      'ON'],
			'on/off keyword OFF'         => ['slow_query_log',          'OFF',     'OFF'],
			'on/off numeric → keyword'   => ['slow_query_log',          '1',       'ON'],
			'on/off numeric zero → OFF'  => ['slow_query_log',          '0',       'OFF'],
			'enum quoted via DBAL'       => ['innodb_flush_method',     'O_DIRECT', "'O_DIRECT'"],
			'float'                      => ['long_query_time',         '1.5',     '1.5'],
		];
	}

	#[DataProvider('mysqlRendering')]
	public function test_mysql_apply_produces_expected_sql(string $variable, string $value, string $expectedSuffix): void {
		$this->wireFlavour(Snapshot::FLAVOUR_MYSQL);
		$this->svc->apply($variable, $value);
		$this->assertSame(
			['SET GLOBAL `' . $variable . '` = ' . $expectedSuffix],
			$this->executed,
		);
	}

	// ── Postgres literal rendering ─────────────────────────────────

	/**
	 * @return list<array{string, string, string}>
	 */
	public static function pgsqlRendering(): array {
		return [
			'plain int'             => ['max_connections',  '300',    '300'],
			'byte-string MB'        => ['work_mem',         '64MB',   "'64MB'"],
			'byte-string kB'        => ['work_mem',         '16384kB', "'16384kB'"],
			'byte-string no suffix' => ['shared_buffers',   '8192',   '8192'],
			'float'                 => ['random_page_cost', '1.1',    '1.1'],
		];
	}

	#[DataProvider('pgsqlRendering')]
	public function test_pgsql_apply_produces_expected_sql(string $variable, string $value, string $expectedSuffix): void {
		$this->wireFlavour(Snapshot::FLAVOUR_PGSQL);
		$this->svc->apply($variable, $value);
		$this->assertSame(
			[
				'ALTER SYSTEM SET "' . $variable . '" = ' . $expectedSuffix,
				'SELECT pg_reload_conf()',
			],
			$this->executed,
		);
	}

	// ── Quote-delegation guard ─────────────────────────────────────

	public function test_string_shaped_values_go_through_dbal_quote(): void {
		// We mock quote() to return a sentinel and assert that exact
		// sentinel ends up in the rendered SQL — proving ApplyService
		// delegated escaping rather than concatenating its own quotes.
		$mockConn = $this->createMock(IDBConnection::class);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('execute')->willReturn($result);
		$mockConn->method('prepare')->willReturn($stmt);

		$mockConn->expects($this->atLeastOnce())
			->method('quote')
			->with('O_DIRECT')
			->willReturn('<<QUOTED>>');

		$captured = [];
		$mockConn->method('executeStatement')
			->willReturnCallback(function (string $sql) use (&$captured): int {
				$captured[] = $sql;
				return 0;
			});

		$probe = $this->createMock(DatabaseProbe::class);
		$probe->method('withConnection')
			->willReturnCallback(static fn (callable $cb) => $cb($mockConn, Snapshot::FLAVOUR_MYSQL));

		$svc = new ApplyService($probe, $this->logger);
		$svc->apply('innodb_flush_method', 'O_DIRECT');

		$this->assertSame(
			['SET GLOBAL `innodb_flush_method` = <<QUOTED>>'],
			$captured,
		);
	}
}
