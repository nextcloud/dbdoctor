<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Tests\Unit\Advisory;

use OCA\DBDoctor\Advisory\ExpressionEvaluator;
use OCA\DBDoctor\Advisory\Rule;
use OCA\DBDoctor\Advisory\RuleSet;
use OCA\DBDoctor\Advisory\RuleSet\Mysql;
use OCA\DBDoctor\Advisory\RuleSet\Postgres;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Catches typos in hand-typed rule data.
 *
 * Each rule carries three expression-shaped strings — `formula`, `test`,
 * and the comma-separated `justificationFormula`.  The Advisor evaluates
 * them at runtime, and a parser-level error there only surfaces as a
 * warning log on a live admin's instance.  This test runs every
 * expression through {@see ExpressionEvaluator} against a context
 * pre-populated with every identifier the rule references, so any
 * lexical / parse failure (unbalanced parens, unknown operators, stray
 * characters) fails loudly at CI time instead.
 *
 * We deliberately seed every referenced identifier with `1.0` rather
 * than realistic values: this is a *parse* smoke test, not a semantic
 * one.  We only verify that the parser can walk the expression to
 * completion.
 */
final class RuleSetSmokeTest extends TestCase {
	private ExpressionEvaluator $eval;

	protected function setUp(): void {
		$this->eval = new ExpressionEvaluator();
	}

	/**
	 * @return list<array{string, RuleSet}>
	 */
	public static function ruleSets(): array {
		return [
			['mysql', new Mysql()],
			['postgres', new Postgres()],
		];
	}

	#[DataProvider('ruleSets')]
	public function test_every_rule_in_set_parses_cleanly(string $label, RuleSet $set): void {
		$rules = $set->rules();
		$this->assertNotEmpty($rules, "Rule set '$label' is empty.");

		// Build a global context once: every identifier referenced by
		// any rule in this set, mapped to a benign 1.0.  This way a
		// single rule whose `formula` references a key not listed in
		// its own `requires` still parses, mirroring what happens at
		// runtime when the snapshot happens to expose that key.
		$ctx = $this->collectIdentifiers($rules);

		$ids = array_map(static fn ($r) => $r->id, $rules);
		$this->assertSame(
			array_values(array_unique($ids)),
			array_values($ids),
			"Rule set '$label' has duplicate ids.",
		);

		foreach ($rules as $rule) {
			$this->assertNotSame('', $rule->id, "A rule in '$label' has an empty id.");
			$this->assertContains(
				$rule->severity,
				[Rule::SEVERITY_ALERT, Rule::SEVERITY_WARNING, Rule::SEVERITY_NOTICE],
				"Rule {$rule->id} has unknown severity '{$rule->severity}'.",
			);

			// formula → produces value
			$value = $this->safeEvaluate($rule->formula, $ctx, "$label::{$rule->id} formula");

			// test → boolean
			$testCtx = $ctx;
			$testCtx['value'] = is_bool($value) ? ($value ? 1.0 : 0.0) : (float)$value;
			$this->safeEvaluate($rule->test, $testCtx, "$label::{$rule->id} test");

			// justificationFormula → comma-separated list of expressions.
			// Mirror Advisor::interpolate's top-level-comma split.
			if ($rule->justificationFormula !== '') {
				foreach ($this->splitTopLevelCommas($rule->justificationFormula) as $part) {
					$this->safeEvaluate($part, $testCtx, "$label::{$rule->id} justificationFormula segment '$part'");
				}
			}
		}
	}

	/**
	 * @param list<Rule> $rules
	 * @return array<string, float>
	 */
	private function collectIdentifiers(array $rules): array {
		$ctx = ['value' => 1.0];
		foreach ($rules as $rule) {
			$exprs = [$rule->formula, $rule->test, $rule->justificationFormula];
			foreach ($exprs as $expr) {
				if ($expr === '') {
					continue;
				}
				preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $expr, $m);
				foreach ($m[0] as $id) {
					$ctx[$id] = 1.0;
				}
			}
			foreach ($rule->requires as $req) {
				$ctx[$req] = 1.0;
			}
		}
		return $ctx;
	}

	/**
	 * @param array<string, float> $ctx
	 */
	private function safeEvaluate(string $expression, array $ctx, string $label): float|bool {
		try {
			return $this->eval->evaluate($expression, $ctx);
		} catch (\Throwable $e) {
			$this->fail("Expression failed to parse for $label: {$e->getMessage()} | expression=$expression");
		}
	}

	/**
	 * @return list<string>
	 */
	private function splitTopLevelCommas(string $formula): array {
		$parts = [];
		$buf = '';
		$depth = 0;
		foreach (str_split($formula) as $ch) {
			if ($ch === '(') {
				$depth++;
				$buf .= $ch;
			} elseif ($ch === ')') {
				$depth--;
				$buf .= $ch;
			} elseif ($ch === ',' && $depth === 0) {
				$parts[] = trim($buf);
				$buf = '';
			} else {
				$buf .= $ch;
			}
		}
		if ($buf !== '') {
			$parts[] = trim($buf);
		}
		return $parts;
	}
}
