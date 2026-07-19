<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Service;

use OCA\DBDoctor\Advisory\RuleResult;
use OCA\DBDoctor\Db\HistoryEntry;
use OCA\DBDoctor\Db\HistoryEntryMapper;

/**
 * Persists check-run snapshots and serves history for sparklines.
 *
 * Rate-limited to one row per (flavour, hour) — quick re-clicks of
 * "Run check now" overwrite the most recent row instead of inserting,
 * keeping the table size manageable for the sparkline view.
 */
class HistoryService {
	private const RATE_LIMIT_SECONDS = 3600;
	private const COMPRESSED_PREFIX = 'gz:';
	// Above this size, compress before persisting.  TEXT can hold up
	// to 64 KiB on MySQL by default, so we leave room and compress
	// well below that threshold.
	private const COMPRESS_THRESHOLD_BYTES = 32768;

	public function __construct(private HistoryEntryMapper $mapper) {
	}

	/**
	 * @param list<RuleResult> $results
	 * @param array{score:int,grade:string,counts:array{alert:int,warning:int,notice:int,ok:int,skipped:int}} $score
	 */
	public function record(Snapshot $snapshot, array $results, array $score): HistoryEntry {
		$now = time();
		$encoded = $this->encodeResults($results);

		$existing = $this->mapper->findLatest($snapshot->flavour);
		$reuseRow = $existing !== null && ($now - $existing->getCreatedAt()) < self::RATE_LIMIT_SECONDS;

		$entry = $reuseRow ? $existing : new HistoryEntry();
		$entry->setCreatedAt($now);
		$entry->setDbFlavour($snapshot->flavour);
		$entry->setDbVersion($snapshot->version);
		$entry->setScore($score['score']);
		$entry->setGrade($score['grade']);
		$entry->setTotalRules(count($results));
		$entry->setFailedAlerts($score['counts']['alert'] ?? 0);
		$entry->setFailedWarnings($score['counts']['warning'] ?? 0);
		$entry->setFailedNotices($score['counts']['notice'] ?? 0);
		$entry->setResultsJson($encoded);

		return $reuseRow ? $this->mapper->update($entry) : $this->mapper->insert($entry);
	}

	public function latest(?string $flavour = null): ?HistoryEntry {
		return $this->mapper->findLatest($flavour);
	}

	/**
	 * Build a `[ts => status]` series for one rule across recent history.
	 *
	 * Used to drive the per-rule sparkline.  Returns up to one entry
	 * per stored snapshot in the requested window, ordered chronologically.
	 *
	 * @return list<array{ts:int,status:string}>
	 */
	public function seriesForRule(string $ruleId, int $days = 30): array {
		$now = time();
		$since = $now - ($days * 86400);
		// Restrict the series to the flavour of the most recent run.
		// If the operator repointed DBDoctor at a different database
		// (e.g. MySQL → PostgreSQL), the stored history holds rows for
		// both; a single rule's sparkline must not interleave them.
		$latest = $this->mapper->findLatest();
		$flavour = $latest?->getDbFlavour();
		$rows = $this->mapper->findRange($since, $now, $flavour);

		$series = [];
		foreach ($rows as $row) {
			$results = $this->decodeResults($row->getResultsJson());
			foreach ($results as $r) {
				if (($r['id'] ?? '') === $ruleId) {
					$series[] = ['ts' => $row->getCreatedAt(), 'status' => (string)($r['status'] ?? 'skipped')];
					break;
				}
			}
		}
		return $series;
	}

	/**
	 * Overall score trend across recent runs, restricted to the flavour
	 * of the most recent run (same reasoning as {@see seriesForRule}).
	 *
	 * @return list<array{ts:int, score:int, grade:string}>
	 */
	public function scoreSeries(int $days = 30): array {
		$flavour = $this->mapper->findLatest()?->getDbFlavour();
		return $this->mapper->findScoreSeries(time() - $days * 86400, $flavour);
	}

	/**
	 * @param list<RuleResult> $results
	 */
	private function encodeResults(array $results): string {
		$json = json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			return '[]';
		}
		if (strlen($json) >= self::COMPRESS_THRESHOLD_BYTES) {
			$gz = gzencode($json, 6);
			if ($gz !== false) {
				return self::COMPRESSED_PREFIX . base64_encode($gz);
			}
		}
		return $json;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function decodeResults(string $blob): array {
		if (str_starts_with($blob, self::COMPRESSED_PREFIX)) {
			$payload = base64_decode(substr($blob, strlen(self::COMPRESSED_PREFIX)), true);
			if ($payload === false) {
				return [];
			}
			$json = gzdecode($payload);
			if ($json === false) {
				return [];
			}
			$blob = $json;
		}
		$decoded = json_decode($blob, true);
		return is_array($decoded) ? array_values($decoded) : [];
	}
}
