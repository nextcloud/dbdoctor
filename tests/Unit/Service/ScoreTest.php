<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Tests\Unit\Service;

use OCA\DBDoctor\Advisory\Rule;
use OCA\DBDoctor\Advisory\RuleResult;
use OCA\DBDoctor\Service\Score;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScoreTest extends TestCase {
	private Score $score;

	protected function setUp(): void {
		$this->score = new Score();
	}

	/**
	 * @return list<array{int, string}>
	 */
	public static function gradeBoundaries(): array {
		return [
			[100, 'A'],
			[90, 'A'],
			[89, 'B'],
			[80, 'B'],
			[79, 'C'],
			[70, 'C'],
			[69, 'D'],
			[60, 'D'],
			[59, 'F'],
			[0, 'F'],
		];
	}

	#[DataProvider('gradeBoundaries')]
	public function test_grade_boundaries(int $score, string $expected): void {
		$this->assertSame($expected, $this->score->gradeFor($score));
	}

	public function test_perfect_score_when_all_ok(): void {
		$results = [
			$this->makeResult('a', Rule::SEVERITY_NOTICE, RuleResult::STATUS_OK),
			$this->makeResult('b', Rule::SEVERITY_WARNING, RuleResult::STATUS_OK),
		];
		$out = $this->score->compute($results);
		$this->assertSame(100, $out['score']);
		$this->assertSame('A', $out['grade']);
	}

	public function test_deductions_apply_in_order(): void {
		$results = [
			$this->makeResult('alert1', Rule::SEVERITY_ALERT, RuleResult::STATUS_FAIL),    // -10
			$this->makeResult('alert2', Rule::SEVERITY_ALERT, RuleResult::STATUS_FAIL),    // -10
			$this->makeResult('warn1', Rule::SEVERITY_WARNING, RuleResult::STATUS_FAIL),   //  -5
			$this->makeResult('notice1', Rule::SEVERITY_NOTICE, RuleResult::STATUS_FAIL),  //  -2
			$this->makeResult('ok', Rule::SEVERITY_NOTICE, RuleResult::STATUS_OK),
		];
		$out = $this->score->compute($results);
		// 100 - 20 - 5 - 2 = 73
		$this->assertSame(73, $out['score']);
		$this->assertSame('C', $out['grade']);
	}

	public function test_skipped_rules_dont_count(): void {
		$results = [
			$this->makeResult('s1', Rule::SEVERITY_ALERT, RuleResult::STATUS_SKIPPED),
			$this->makeResult('s2', Rule::SEVERITY_WARNING, RuleResult::STATUS_SKIPPED),
			$this->makeResult('ok', Rule::SEVERITY_NOTICE, RuleResult::STATUS_OK),
		];
		$out = $this->score->compute($results);
		$this->assertSame(100, $out['score']);
		$this->assertSame(2, $out['counts']['skipped']);
	}

	public function test_score_floor_at_zero(): void {
		$results = [];
		// 12 alerts → -120 → clamped to 0
		for ($i = 0; $i < 12; $i++) {
			$results[] = $this->makeResult("a$i", Rule::SEVERITY_ALERT, RuleResult::STATUS_FAIL);
		}
		$out = $this->score->compute($results);
		$this->assertSame(0, $out['score']);
		$this->assertSame('F', $out['grade']);
	}

	public function test_counts_separated_by_severity(): void {
		$results = [
			$this->makeResult('a', Rule::SEVERITY_ALERT, RuleResult::STATUS_FAIL),
			$this->makeResult('b', Rule::SEVERITY_ALERT, RuleResult::STATUS_FAIL),
			$this->makeResult('c', Rule::SEVERITY_WARNING, RuleResult::STATUS_FAIL),
			$this->makeResult('d', Rule::SEVERITY_NOTICE, RuleResult::STATUS_FAIL),
			$this->makeResult('e', Rule::SEVERITY_NOTICE, RuleResult::STATUS_FAIL),
			$this->makeResult('f', Rule::SEVERITY_NOTICE, RuleResult::STATUS_OK),
		];
		$out = $this->score->compute($results);
		$this->assertSame(2, $out['counts']['alert']);
		$this->assertSame(1, $out['counts']['warning']);
		$this->assertSame(2, $out['counts']['notice']);
		$this->assertSame(1, $out['counts']['ok']);
	}

	private function makeResult(string $id, string $severity, string $status): RuleResult {
		$rule = new Rule(
			id: $id,
			name: $id,
			category: 'Test',
			severity: $severity,
			formula: '0',
			test: 'value > 0',
			issue: '',
			recommendation: '',
			justification: '',
			justificationFormula: '',
		);
		return new RuleResult($rule, $status);
	}
}
