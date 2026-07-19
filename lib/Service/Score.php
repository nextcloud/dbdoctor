<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Service;

use OCA\DBDoctor\Advisory\RuleResult;

/**
 * Score + letter-grade calculator.
 *
 * Formula (locked in spec, not configurable in v1):
 *
 *     score = max(0, 100 − 10·#alerts − 5·#warnings − 2·#notices)
 *     grade = score ≥ 90 ? 'A' : ≥ 80 ? 'B' : ≥ 70 ? 'C' : ≥ 60 ? 'D' : 'F'
 *
 * Only STATUS_FAIL results contribute deductions, weighted by severity.
 * STATUS_OK and STATUS_SKIPPED contribute zero — but they're counted
 * separately so the UI can distinguish "this rule passed" from "this
 * rule could not be evaluated".
 */
final class Score {
	public const GRADE_A = 'A';
	public const GRADE_B = 'B';
	public const GRADE_C = 'C';
	public const GRADE_D = 'D';
	public const GRADE_F = 'F';

	/**
	 * @param list<RuleResult> $results
	 * @return array{score:int,grade:string,counts:array{alert:int,warning:int,notice:int,ok:int,skipped:int}}
	 */
	public function compute(array $results): array {
		$counts = ['alert' => 0, 'warning' => 0, 'notice' => 0, 'ok' => 0, 'skipped' => 0];

		foreach ($results as $r) {
			if ($r->status === RuleResult::STATUS_SKIPPED) {
				$counts['skipped']++;
				continue;
			}
			if ($r->status === RuleResult::STATUS_OK) {
				$counts['ok']++;
				continue;
			}
			$counts[$r->rule->severity] = ($counts[$r->rule->severity] ?? 0) + 1;
		}

		$score = 100
			- 10 * $counts['alert']
			- 5 * $counts['warning']
			- 2 * $counts['notice'];

		$score = max(0, min(100, $score));

		return [
			'score' => $score,
			'grade' => $this->gradeFor($score),
			'counts' => $counts,
		];
	}

	public function gradeFor(int $score): string {
		return match (true) {
			$score >= 90 => self::GRADE_A,
			$score >= 80 => self::GRADE_B,
			$score >= 70 => self::GRADE_C,
			$score >= 60 => self::GRADE_D,
			default => self::GRADE_F,
		};
	}
}
