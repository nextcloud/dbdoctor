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
 * Rules can opt into a name list from the snapshot's details map via
 * `detailsKey` (e.g. the table names behind the seq-scan count).  These
 * tests pin the pass-through: attached on failure, absent on pass,
 * absent when the snapshot has nothing under the key, and serialized.
 */
final class AdvisorDetailsTest extends TestCase {
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
				return Snapshot::FLAVOUR_PGSQL;
			}
		};
	}

	private function seqScanRule(): Rule {
		return new Rule(
			id: 'pg.tables_without_index_use',
			name: 'Tables scanning sequentially',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'tables_without_index_use',
			test: 'value > 0',
			issue: '',
			recommendation: '',
			justification: '%s table(s) affected.',
			justificationFormula: 'value',
			requires: ['tables_without_index_use'],
			detailsKey: 'tables_without_index_use',
		);
	}

	private function snapshot(int $count, array $details = []): Snapshot {
		return new Snapshot(
			Snapshot::FLAVOUR_PGSQL,
			'16.2',
			['tables_without_index_use' => $count],
			[],
			[],
			$details,
		);
	}

	public function test_details_attached_when_rule_fails(): void {
		$snapshot = $this->snapshot(2, [
			'tables_without_index_use' => ['oc_bigtable', 'oc_othertable'],
		]);

		[$result] = $this->advisor->run($this->ruleSet($this->seqScanRule()), $snapshot);

		$this->assertSame(RuleResult::STATUS_FAIL, $result->status);
		$this->assertSame(['oc_bigtable', 'oc_othertable'], $result->details);
		$this->assertSame(['oc_bigtable', 'oc_othertable'], $result->jsonSerialize()['details']);
	}

	public function test_no_details_when_rule_passes(): void {
		$snapshot = $this->snapshot(0, [
			// A stale list must not leak onto a passing result.
			'tables_without_index_use' => ['oc_bigtable'],
		]);

		[$result] = $this->advisor->run($this->ruleSet($this->seqScanRule()), $snapshot);

		$this->assertSame(RuleResult::STATUS_OK, $result->status);
		$this->assertNull($result->details);
	}

	public function test_missing_details_key_yields_null_not_empty_list(): void {
		// A failing rule whose snapshot carries no list (e.g. a probe
		// that predates the details map) must degrade to the old
		// count-only behaviour.
		[$result] = $this->advisor->run($this->ruleSet($this->seqScanRule()), $this->snapshot(3));

		$this->assertSame(RuleResult::STATUS_FAIL, $result->status);
		$this->assertNull($result->details);
	}

	public function test_rule_without_details_key_ignores_snapshot_details(): void {
		$rule = new Rule(
			id: 'pg.some_other_rule',
			name: 'Other rule',
			category: 'Performance',
			severity: Rule::SEVERITY_NOTICE,
			formula: 'tables_without_index_use',
			test: 'value > 0',
			issue: '',
			recommendation: '',
			justification: '',
			justificationFormula: '',
			requires: ['tables_without_index_use'],
		);
		$snapshot = $this->snapshot(1, [
			'tables_without_index_use' => ['oc_bigtable'],
		]);

		[$result] = $this->advisor->run($this->ruleSet($rule), $snapshot);

		$this->assertSame(RuleResult::STATUS_FAIL, $result->status);
		$this->assertNull($result->details);
	}
}
