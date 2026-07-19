<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Service;

use Doctrine\DBAL\Connection;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Executes Apply requests for runtime-tunable database variables.
 *
 * Defence in depth:
 *   1. Caller is checked for admin in the controller.
 *   2. The variable name is validated against {@see self::ALLOW_LIST_*}
 *      hard-coded here, not derived from the request.  This is the
 *      security-critical step: even if a stale UI tries to apply a
 *      variable the server doesn't currently recommend, we refuse.
 *   3. The new value is validated to match the variable's expected
 *      shape (numeric, byte-size, on/off, enum) before being passed
 *      into the SQL.
 *   4. Variable name is interpolated only after passing through the
 *      allow-list check, and only into a backtick-quoted SET GLOBAL
 *      statement.  Values are bound as parameters where supported, or
 *      stringently re-validated for SET GLOBAL where bind-parameters
 *      aren't accepted.
 *
 * No direct user input is concatenated into a SQL string anywhere.
 *
 * The whole operation — flavour detection, the pre/post value reads,
 * and the SET GLOBAL / ALTER SYSTEM itself — runs on a single
 * connection obtained via {@see DatabaseProbe::withConnection()}, so a
 * configured override connection (typically a higher-privilege user
 * that actually holds SUPER / SYSTEM_VARIABLES_ADMIN / superuser) is
 * honoured, and the allow-list chosen for a flavour is guaranteed to
 * be applied to the same server that flavour was detected on.
 */
final class ApplyService {
	/**
	 * MySQL / MariaDB variables that may be modified at runtime via SET GLOBAL.
	 *
	 * This list intentionally errs on the side of caution.  Adding
	 * a variable here is a deliberate decision — never let it
	 * grow automatically from rule data.
	 */
	public const ALLOW_LIST_MYSQL = [
		// InnoDB
		'innodb_buffer_pool_size',
		'innodb_log_buffer_size',
		'innodb_flush_log_at_trx_commit',
		'innodb_flush_method',
		'innodb_io_capacity',
		'innodb_io_capacity_max',
		// Caches & buffers
		'key_buffer_size',
		'table_open_cache',
		'table_definition_cache',
		'thread_cache_size',
		'tmp_table_size',
		'max_heap_table_size',
		'sort_buffer_size',
		'join_buffer_size',
		'read_buffer_size',
		'read_rnd_buffer_size',
		// Connections
		'max_connections',
		'max_allowed_packet',
		'wait_timeout',
		'interactive_timeout',
		// Logging
		'slow_query_log',
		'long_query_time',
		'log_slow_admin_statements',
		'log_queries_not_using_indexes',
		// Binlog
		'sync_binlog',
		'binlog_cache_size',
		'expire_logs_days',
	];

	/**
	 * PostgreSQL GUCs we'll set via ALTER SYSTEM.  Postgres `SET` is
	 * per-session, so for a permanent-feeling "Apply now" we use
	 * ALTER SYSTEM and call pg_reload_conf().  Some settings still
	 * need a server restart — that's communicated by the rule's
	 * ApplyDescriptor::$note.
	 */
	public const ALLOW_LIST_PGSQL = [
		'max_connections',
		'work_mem',
		'maintenance_work_mem',
		'effective_cache_size',
		'shared_buffers',
		'checkpoint_completion_target',
		'wal_buffers',
		'autovacuum_naptime',
		'autovacuum_vacuum_scale_factor',
		'random_page_cost',
	];

	public function __construct(
		private DatabaseProbe $probe,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Apply `$variable = $value` to the live server.
	 *
	 * @return array{oldValue: ?string, newValue: ?string} The actual values read from the server
	 *                                                      before and after the change.
	 * @throws \InvalidArgumentException When the variable isn't on the allow-list,
	 *                                   or the value fails shape validation.
	 * @throws \RuntimeException When the SQL fails on the server.
	 */
	public function apply(string $variable, string $value): array {
		return $this->probe->withConnection(
			function (Connection|IDBConnection $conn, string $flavour) use ($variable, $value): array {
				$allowList = match ($flavour) {
					Snapshot::FLAVOUR_PGSQL => self::ALLOW_LIST_PGSQL,
					Snapshot::FLAVOUR_MYSQL,
					Snapshot::FLAVOUR_MARIADB => self::ALLOW_LIST_MYSQL,
					default => [],
				};

				if (!in_array($variable, $allowList, true)) {
					throw new \InvalidArgumentException(
						"Variable '$variable' is not on DB Doctor's allow-list for $flavour and cannot be applied.",
					);
				}

				$this->validateValue($variable, $value);

				// Read pre-change value for the audit log.
				$oldValue = $this->readGlobal($conn, $variable, $flavour);

				try {
					if ($flavour === Snapshot::FLAVOUR_PGSQL) {
						$this->applyPostgres($conn, $variable, $value);
					} else {
						$this->applyMysql($conn, $variable, $value);
					}
				} catch (\Throwable $e) {
					$this->logger->error(
						'DBDoctor: apply failed for {var}={val}: {msg}',
						['var' => $variable, 'val' => $value, 'msg' => $e->getMessage(), 'app' => 'dbdoctor'],
					);
					throw new \RuntimeException($this->explainFailure($e, $flavour, $variable), 0, $e);
				}

				// Read post-change value.  May differ from `value` if the
				// server normalises (e.g. `1024K` → `1048576`).
				$newValue = $this->readGlobal($conn, $variable, $flavour);

				return ['oldValue' => $oldValue, 'newValue' => $newValue];
			},
		);
	}

	/**
	 * Turn a raw driver failure into an actionable message.  The most
	 * common cause by far is that the connecting database user lacks the
	 * privilege to change a global — the normal situation for Nextcloud's
	 * own DB account — so we detect that and point the admin at the two
	 * ways forward: the config snippet or a higher-privilege override.
	 */
	private function explainFailure(\Throwable $e, string $flavour, string $variable): string {
		$msg = $e->getMessage();
		$lower = strtolower($msg);
		$privilegeDenied = str_contains($lower, 'access denied')
			|| str_contains($lower, 'super')
			|| str_contains($lower, 'system_variables_admin')
			|| str_contains($lower, 'permission denied')
			|| str_contains($lower, 'must be superuser')
			|| str_contains($lower, 'privilege');

		if ($privilegeDenied) {
			$need = $flavour === Snapshot::FLAVOUR_PGSQL
				? 'The database user needs superuser rights to run ALTER SYSTEM'
				: 'The database user needs the SUPER or SYSTEM_VARIABLES_ADMIN privilege to run SET GLOBAL';
			return sprintf(
				"Could not apply '%s': %s. %s. Use the config-snippet button for a permanent my.cnf / postgresql.conf change instead, or configure a higher-privilege override connection in DB Doctor settings.",
				$variable,
				$msg,
				$need,
			);
		}

		return sprintf("Could not apply '%s': %s", $variable, $msg);
	}

	private function applyMysql(Connection|IDBConnection $conn, string $variable, string $value): void {
		// SET GLOBAL doesn't accept bound parameters in MySQL/MariaDB,
		// so the value is rendered into the statement.  Safety relies on
		// (a) the variable name being allow-listed, (b) the value passing
		// validateValue(), and (c) literal() routing every string-shaped
		// value through DBAL's quote().
		$conn->executeStatement(
			'SET GLOBAL `' . $variable . '` = ' . $this->literal($conn, $variable, $value),
		);
	}

	private function applyPostgres(Connection|IDBConnection $conn, string $variable, string $value): void {
		// ALTER SYSTEM doesn't accept bind parameters.  We quote the
		// value as a SQL string literal, having already validated it.
		$conn->executeStatement('ALTER SYSTEM SET "' . $variable . '" = ' . $this->literal($conn, $variable, $value));
		// Apply without restart for settings that allow it; the rule's
		// note tells the user when a restart is needed regardless.
		$conn->executeStatement('SELECT pg_reload_conf()');
	}

	private function readGlobal(Connection|IDBConnection $conn, string $variable, string $flavour): ?string {
		try {
			if ($flavour === Snapshot::FLAVOUR_PGSQL) {
				$value = $this->probe->scalar($conn, 'SHOW "' . $variable . '"');
				return $value === null ? null : (string)$value;
			}
			if ($conn instanceof Connection) {
				$data = $conn->fetchAssociative('SHOW GLOBAL VARIABLES LIKE ?', [$variable]);
			} else {
				$result = $conn->prepare('SHOW GLOBAL VARIABLES LIKE ?')->execute([$variable]);
				$data = $result->fetch();
				$result->closeCursor();
			}
			if ($data === false || $data === null) {
				return null;
			}
			$values = array_values($data);
			return isset($values[1]) ? (string)$values[1] : null;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Validates the *shape* of `$value` for `$variable`.
	 *
	 * Categories accepted:
	 *  - integers (e.g. `max_connections`)
	 *  - byte-size strings (`128M`, `1G`)
	 *  - floats (`long_query_time`, `random_page_cost`)
	 *  - on/off booleans (`slow_query_log`)
	 *  - enums (`innodb_flush_method`)
	 *
	 * Anything that doesn't match throws — there's no fallback path.
	 */
	private function validateValue(string $variable, string $value): void {
		$value = trim($value);
		if ($value === '') {
			throw new \InvalidArgumentException("Empty value for '$variable'.");
		}

		if (in_array($variable, ['slow_query_log', 'log_slow_admin_statements', 'log_queries_not_using_indexes'], true)) {
			if (!in_array(strtoupper($value), ['ON', 'OFF', '0', '1'], true)) {
				throw new \InvalidArgumentException("'$variable' must be ON, OFF, 0, or 1.");
			}
			return;
		}

		if ($variable === 'innodb_flush_method') {
			if (!in_array($value, ['fsync', 'O_DIRECT', 'O_DSYNC', 'littlesync', 'nosync'], true)) {
				throw new \InvalidArgumentException("Invalid innodb_flush_method value '$value'.");
			}
			return;
		}

		if (in_array($variable, ['long_query_time', 'random_page_cost', 'checkpoint_completion_target', 'autovacuum_vacuum_scale_factor'], true)) {
			if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
				throw new \InvalidArgumentException("'$variable' must be a non-negative number.");
			}
			return;
		}

		if (in_array($variable, ['work_mem', 'maintenance_work_mem', 'effective_cache_size', 'shared_buffers', 'wal_buffers'], true)) {
			// Postgres units: "64MB", "2GB", "16384kB" (lowercase 'k', uppercase others).
			if (!preg_match('/^\d+\s*(kB|MB|GB|TB)?$/', $value)) {
				throw new \InvalidArgumentException("'$variable' must be an integer optionally suffixed with kB/MB/GB/TB.");
			}
			return;
		}

		// MySQL byte-size strings: "1024", "256K", "512M", "8G".
		if (preg_match('/^\d+[KMG]?$/i', $value)) {
			return;
		}

		// Plain non-negative integer.
		if (preg_match('/^\d+$/', $value)) {
			return;
		}

		throw new \InvalidArgumentException("Value '$value' is not a recognized format for '$variable'.");
	}

	/**
	 * Render a previously-validated value as a SQL literal for inclusion
	 * in a SET GLOBAL / ALTER SYSTEM statement.
	 *
	 * Strategy:
	 *   - on/off keywords: emit the bare keyword (ON / OFF) — neither MySQL
	 *     nor Postgres accepts these as quoted strings in this context.
	 *   - everything that is not a pure number (or MySQL byte-suffix
	 *     numeric literal): delegate to DBAL's quote(), which honours the
	 *     server's NO_BACKSLASH_ESCAPES / standard_conforming_strings mode.
	 *   - pure numeric / numeric-with-K|M|G suffix: emit as-is, having
	 *     already passed an anchored regex in validateValue().
	 *
	 * No raw single-quote concatenation lives in this method any more.
	 */
	private function literal(Connection|IDBConnection $conn, string $variable, string $value): string {
		$value = trim($value);

		if (in_array($variable, ['slow_query_log', 'log_slow_admin_statements', 'log_queries_not_using_indexes'], true)) {
			$upper = strtoupper($value);
			return $upper === '1' ? 'ON' : ($upper === '0' ? 'OFF' : $upper);
		}

		// MySQL accepts byte-suffix numeric literals (e.g. 128M) directly
		// in SET GLOBAL syntax — quoting them would force a string→int
		// coercion path on the server.  These shapes are tightly validated
		// upstream so emitting them as-is is safe.
		if (preg_match('/^\d+[KMG]?$/i', $value) || preg_match('/^\d+(\.\d+)?$/', $value)) {
			return $value;
		}

		// Everything else — enums, postgres unit-suffixed sizes (64MB,
		// 16384kB), etc. — gets sent through DBAL's connection-level
		// string quoting.  This is the layer that knows about the server's
		// quoting mode; we don't reimplement it.  Quoting happens on the
		// same connection the statement will run on.
		return (string)$conn->quote($value);
	}
}
