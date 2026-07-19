<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\DBDoctor\Tests\Unit\Advisory;

use OCA\DBDoctor\Advisory\ExpressionEvaluator;
use OCA\DBDoctor\Advisory\ExpressionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the security-critical evaluator.  Rejecting bad input is as
 * important as accepting valid input, so every shape of malformed
 * expression is asserted to throw.
 */
final class ExpressionEvaluatorTest extends TestCase {
	private ExpressionEvaluator $eval;

	protected function setUp(): void {
		$this->eval = new ExpressionEvaluator();
	}

	/**
	 * @return list<array{string, array<string,scalar|null>, mixed}>
	 */
	public static function validExpressions(): array {
		return [
			// Pure arithmetic
			['1 + 2',          [], 3.0],
			['10 - 4',         [], 6.0],
			['2 * 3',          [], 6.0],
			['10 / 4',         [], 2.5],
			['10 % 3',         [], 1.0],
			['2 ** 8',         [], 256.0],
			['(1 + 2) * 3',    [], 9.0],

			// Identifiers
			['a + b',          ['a' => 2, 'b' => 3], 5.0],
			['Slow_queries / Questions * 100', ['Slow_queries' => 5, 'Questions' => 1000], 0.5],

			// String-numeric coercion (MySQL returns numeric values as strings)
			['v + 1',          ['v' => '99'], 100.0],

			// Booleans
			['1 < 2',          [], true],
			['2 > 1',          [], true],
			['1 == 1',         [], true],
			['1 != 1',         [], false],
			['1 <= 1 && 1 >= 1', [], true],
			['0 || 1',         [], true],
			['1 OR 0',         [], true],
			['1 AND 1',        [], true],
			['!0',             [], true],
			['!1',             [], false],

			// Functions
			['pow(2, 8)',      [], 256.0],
			['power(2, 8)',    [], 256.0],
			['min(3, 5, 1)',   [], 1.0],
			['max(3, 5, 1)',   [], 5.0],
			['abs(-5)',        [], 5.0],
			['ceil(1.2)',      [], 2.0],
			['floor(1.8)',     [], 1.0],
			['round(1.5)',     [], 2.0],

			// Ternary
			['1 > 0 ? 7 : 9',  [], 7.0],
			['a > 0 ? a : 0',  ['a' => -3], 0.0],

			// Divide by zero → INF (matches phpMyAdmin semantics)
			['1 / 0',          [], INF],

			// Unary
			['-3 + 5',         [], 2.0],
			['+(-3)',          [], -3.0],

			// TRUE / FALSE keywords
			['TRUE',           [], 1.0],
			['FALSE',          [], 0.0],
		];
	}

	#[DataProvider('validExpressions')]
	public function test_valid(string $expr, array $ctx, float|bool $expected): void {
		$result = $this->eval->evaluate($expr, $ctx);
		if (is_bool($expected)) {
			$this->assertSame($expected, $result, "expr: $expr");
		} else {
			$this->assertEqualsWithDelta($expected, (float)$result, 1e-9, "expr: $expr");
		}
	}

	/** @return list<array{string, array<string,scalar|null>}> */
	public static function invalidExpressions(): array {
		return [
			['1 +',                       []],            // dangling operator
			['(1 + 2',                    []],            // unbalanced paren
			['1 + 2)',                    []],            // unbalanced paren
			['unknownFunc(1)',            []],            // unknown function
			['unknownVar + 1',            []],            // unknown identifier
			['',                          []],            // empty
			['system("rm -rf /")',        []],            // unknown function (security)
			['$x',                        []],            // illegal char
			['1 + ;',                     []],            // junk token
			['a',                         ['a' => null]], // null identifier
			['a',                         ['a' => 'foo']],// non-numeric string
		];
	}

	#[DataProvider('invalidExpressions')]
	public function test_invalid_throws(string $expr, array $ctx): void {
		$this->expectException(ExpressionException::class);
		$this->eval->evaluate($expr, $ctx);
	}

	public function test_string_with_quotes_rejected(): void {
		// We never tokenise string literals, so any quoted text is
		// either a parse error or an unknown identifier — both throw.
		$this->expectException(ExpressionException::class);
		$this->eval->evaluate('"hello"', []);
	}

	public function test_identifier_case_sensitive(): void {
		$this->assertSame(5.0, $this->eval->evaluate('Foo', ['Foo' => 5]));
		$this->expectException(ExpressionException::class);
		$this->eval->evaluate('Foo', ['foo' => 5]);
	}
}
