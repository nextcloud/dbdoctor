<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Tests\Unit\Service;

use OCA\DBDoctor\Advisory\ExpressionEvaluator;
use OCA\DBDoctor\Advisory\Rule;
use OCA\DBDoctor\Advisory\RuleResult;
use OCA\DBDoctor\Advisory\RuleSet;
use OCA\DBDoctor\Service\Advisor;
use OCA\DBDoctor\Service\Snapshot;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Advisor skips counter-derived rules (ratios, rates) while the
 * server's uptime is too low for those cumulative counters to mean
 * anything — otherwise every ratio looks catastrophic right after a
 * restart.  These tests pin that gate.
 */
final class AdvisorUptimeTest extends TestCase {
	private Advisor $advisor;

	protected function setUp(): void {
		$this->advisor = new Advisor(new ExpressionEvaluator(), $this->createMock(LoggerInterface::class));
	}

	private function ruleSet(Rule ...$rules): RuleSet {
		return new class(array_values($rules)) implements RuleSet {
			/** @param list<Rule> $rules */
			public function __construct(private array $rules) {
			}
			public function rules(): array {
				return $this->rules;
			}
			public function flavour(): string {
				return Snapshot::FLAVOUR_MYSQL;
			}
		};
	}

	/** A counter-sensitive rule (id is on the Advisor's gate list) that always fails when evaluated. */
	private function counterRule(): Rule {
		return new Rule(
			id: 'Slow_query_ratio',
			name: 'Slow query ratio',
			category: 'Performance',
			severity: Rule::SEVERITY_WARNING,
			formula: 'ratio',
			test: 'value > 0',
			issue: '',
			recommendation: '',
			justification: '',
			justificationFormula: '',
			requires: ['ratio'],
		);
	}

	private function snapshot(float $uptimeHours): Snapshot {
		return new Snapshot(
			Snapshot::FLAVOUR_MYSQL,
			'8.0.0',
			[],
			[],
			['ratio' => 1.0, 'Uptime_hours' => $uptimeHours],
		);
	}

	public function test_counter_rule_is_skipped_below_uptime_threshold(): void {
		$results = $this->advisor->run($this->ruleSet($this->counterRule()), $this->snapshot(2.0));

		$this->assertCount(1, $results);
		$this->assertSame(RuleResult::STATUS_SKIPPED, $results[0]->status);
		$this->assertStringContainsString('counter-based', (string)$results[0]->skipReason);
	}

	public function test_counter_rule_evaluates_above_uptime_threshold(): void {
		$results = $this->advisor->run($this->ruleSet($this->counterRule()), $this->snapshot(48.0));

		$this->assertSame(RuleResult::STATUS_FAIL, $results[0]->status);
	}

	public function test_non_counter_rule_is_unaffected_by_low_uptime(): void {
		$configRule = new Rule(
			id: 'Slow_query_log_off',
			name: 'Slow query log',
			category: 'Logging',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'flag',
			test: 'value == 0',
			issue: '',
			recommendation: '',
			justification: '',
			justificationFormula: '',
			requires: ['flag'],
		);
		$snapshot = new Snapshot(
			Snapshot::FLAVOUR_MYSQL,
			'8.0.0',
			[],
			[],
			['flag' => 0.0, 'Uptime_hours' => 1.0],
		);

		$results = $this->advisor->run($this->ruleSet($configRule), $snapshot);

		// Not on the gate list → evaluated normally even at 1h uptime.
		$this->assertSame(RuleResult::STATUS_FAIL, $results[0]->status);
	}
}
