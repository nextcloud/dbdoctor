<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Tests\Unit\Advisory;

use OCA\DBDoctor\Advisory\Rule;
use OCA\DBDoctor\Advisory\RuleResult;
use PHPUnit\Framework\TestCase;

/**
 * The expression evaluator documents division-by-zero → INF.  When a
 * rule's `formula` divides by a zero status counter, the resulting
 * RuleResult carries `value === INF`.  Without sanitisation, json_encode
 * returns false on that entire payload — the caller sees an empty
 * response body and no other diagnostic.  Lock the contract here:
 * jsonSerialize() must always emit JSON-encodable scalars.
 */
final class RuleResultTest extends TestCase {
	public function test_finite_value_passes_through(): void {
		$out = $this->makeResult(1.5)->jsonSerialize();
		$this->assertSame(1.5, $out['value']);
		$this->assertNotFalse(json_encode($out));
	}

	public function test_inf_value_is_coerced_to_null(): void {
		$out = $this->makeResult(INF)->jsonSerialize();
		$this->assertNull($out['value']);
		$this->assertNotFalse(json_encode($out));
	}

	public function test_negative_inf_value_is_coerced_to_null(): void {
		$out = $this->makeResult(-INF)->jsonSerialize();
		$this->assertNull($out['value']);
		$this->assertNotFalse(json_encode($out));
	}

	public function test_nan_value_is_coerced_to_null(): void {
		$out = $this->makeResult(NAN)->jsonSerialize();
		$this->assertNull($out['value']);
		$this->assertNotFalse(json_encode($out));
	}

	public function test_null_value_stays_null(): void {
		$out = $this->makeResult(null)->jsonSerialize();
		$this->assertNull($out['value']);
		$this->assertNotFalse(json_encode($out));
	}

	public function test_full_result_list_with_one_inf_rule_still_encodes(): void {
		// The bug surfaced as: one rule with INF made the *whole*
		// payload silently fail to encode.  Verify that doesn't happen
		// once a list of mixed-shape results is encoded.
		$payload = [
			$this->makeResult(0.5)->jsonSerialize(),
			$this->makeResult(INF)->jsonSerialize(),
			$this->makeResult(NAN)->jsonSerialize(),
			$this->makeResult(42.0)->jsonSerialize(),
		];
		$json = json_encode($payload);
		$this->assertNotFalse($json);
		$decoded = json_decode((string)$json, true);
		$this->assertCount(4, $decoded);
		$this->assertNull($decoded[1]['value']);
		$this->assertNull($decoded[2]['value']);
	}

	private function makeResult(float|null $value): RuleResult {
		$rule = new Rule(
			id: 'test',
			name: 'Test',
			category: 'Test',
			severity: Rule::SEVERITY_NOTICE,
			formula: '0',
			test: 'value > 0',
			issue: '',
			recommendation: '',
			justification: '',
			justificationFormula: '',
		);
		return new RuleResult($rule, RuleResult::STATUS_FAIL, value: $value);
	}
}
