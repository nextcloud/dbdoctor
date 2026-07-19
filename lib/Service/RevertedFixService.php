<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Service;

use Doctrine\DBAL\Connection;
use OCA\DBDoctor\Db\AuditEntryMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Detects runtime fixes that DB Doctor applied earlier but that no
 * longer hold on the live server.
 *
 * A `SET GLOBAL` change is lost when the server restarts, so a variable
 * DB Doctor once set can silently revert to its my.cnf / built-in
 * default.  We compare the last value we successfully wrote for each
 * variable (from the audit log) against the current live value, read
 * back through the same override-aware connection the apply used, and
 * report the ones that no longer match — the admin should make those
 * permanent in the config file.
 */
final class RevertedFixService {
	public function __construct(
		private AuditEntryMapper $audit,
		private DatabaseProbe $probe,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return list<array{ruleId:string, variable:string, appliedValue:string, liveValue:string, appliedAt:int}>
	 */
	public function detect(): array {
		$candidates = $this->audit->findLatestSuccessfulPerVariable();
		if ($candidates === []) {
			return [];
		}

		try {
			return $this->probe->withConnection(function (Connection|IDBConnection $conn, string $flavour) use ($candidates): array {
				$reverted = [];
				foreach ($candidates as $entry) {
					$applied = $entry->getNewValue();
					if ($applied === null) {
						continue;
					}
					$live = $this->readLiveValue($conn, $flavour, $entry->getVariable());
					if ($live === null) {
						continue;
					}
					if (!$this->equivalent($live, $applied)) {
						$reverted[] = [
							'ruleId' => $entry->getRuleId(),
							'variable' => $entry->getVariable(),
							'appliedValue' => $applied,
							'liveValue' => $live,
							'appliedAt' => $entry->getAppliedAt(),
						];
					}
				}
				return $reverted;
			});
		} catch (\Throwable $e) {
			$this->logger->warning(
				'DBDoctor: reverted-fix detection failed: {msg}',
				['msg' => $e->getMessage(), 'app' => 'dbdoctor'],
			);
			return [];
		}
	}

	private function readLiveValue(Connection|IDBConnection $conn, string $flavour, string $variable): ?string {
		// Variables come from our own audit table (written only for
		// allow-listed variables), but re-validate the shape before it
		// touches SQL — these reads can't use bind parameters.
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $variable)) {
			return null;
		}

		try {
			if ($flavour === Snapshot::FLAVOUR_PGSQL) {
				$value = $this->probe->scalar($conn, 'SHOW "' . $variable . '"');
				return $value === null ? null : (string)$value;
			}
			$row = $this->probe->row($conn, "SHOW GLOBAL VARIABLES LIKE '" . $variable . "'");
			if ($row === null) {
				return null;
			}
			$values = array_values($row);
			return isset($values[1]) ? (string)$values[1] : null;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Compares a live value against a previously-applied one.  Both were
	 * read straight from the server (the applied value is the post-change
	 * read stored at apply time), so a normalised string compare is
	 * enough; we only fold case and surrounding whitespace, and treat
	 * numerically-equal values as equal to absorb "1024" vs "1024 ".
	 */
	private function equivalent(string $live, string $applied): bool {
		$a = strtolower(trim($live));
		$b = strtolower(trim($applied));
		if ($a === $b) {
			return true;
		}
		if (is_numeric($a) && is_numeric($b)) {
			return (float)$a === (float)$b;
		}
		return false;
	}
}
