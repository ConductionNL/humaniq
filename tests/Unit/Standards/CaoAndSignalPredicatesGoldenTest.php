<?php

/**
 * Golden test for two rule-engine predicates.
 *
 * CaoRegistry::isWellFormed() over decoded CAO files that miss a required
 * key, carry a blank or non-string id, or a leaf group that is not an array,
 * lacks value, source or verified, or has a non-boolean verified; and
 * NlSignalChecks::hasSuccessor() over a contract and sibling contracts that
 * are itself, start earlier, the same day or later, are open-ended, end
 * earlier or later, or carry unparseable dates. Every answer is compared with
 * tests/fixtures/golden/cao-and-signal-predicates.json. Set RECORD_GOLDEN=1
 * to rewrite that file.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\CaoRegistry;
use OCA\Humaniq\Standards\Checks\NlSignalChecks;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Golden cases for the CAO file shape check and the contract-successor rule.
 *
 * @covers \OCA\Humaniq\Standards\CaoRegistry
 * @covers \OCA\Humaniq\Standards\Checks\NlSignalChecks
 */
class CaoAndSignalPredicatesGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/cao-and-signal-predicates.json';

	/**
	 * Every answer, compared with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = ['cao' => [], 'successor' => []];
		$wellFormed = new ReflectionMethod(CaoRegistry::class, 'isWellFormed');
		foreach (self::caoFiles() as $name => $decoded) {
			$actual['cao'][$name] = $wellFormed->invoke(null, $decoded);
		}

		$successor = new ReflectionMethod(NlSignalChecks::class, 'hasSuccessor');
		foreach (self::contracts() as $ownName => $own) {
			foreach (self::siblingSets() as $setName => $siblings) {
				$context = ['signals' => ['contractsByEmployeeId' => ['emp-1' => $siblings, 'emp-2' => [['id' => 'x', 'startDate' => '2030-01-01']]]]];
				$actual['successor'][$ownName . ' | ' . $setName] = $successor->invoke(null, $own, $context);
			}
		}

		$actual['successor']['no index'] = $successor->invoke(null, self::contracts()['plain'], []);
		$actual['successor']['index not an array'] = $successor->invoke(null, self::contracts()['plain'], ['signals' => ['contractsByEmployeeId' => 'x']]);

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);

	}//end testGolden()

	/**
	 * Decoded CAO files, by name.
	 *
	 * @return array<string, mixed>
	 */
	private static function caoFiles(): array {
		$leaf = ['value' => 1, 'source' => 'cao', 'verified' => true];
		$ok = ['id' => 'cao-1', 'name' => 'CAO', 'sector' => 'x', 'version' => '1', 'effectiveDate' => '2026-01-01', 'payScales' => $leaf, 'allowances' => $leaf, 'leaveEntitlement' => $leaf, 'workingTime' => $leaf];
		$files = ['well formed' => $ok, 'not an array' => 'cao', 'null' => null, 'empty' => [], 'blank id' => (['id' => '  '] + $ok), 'numeric id' => (['id' => 7] + $ok), 'null key present' => (['name' => null] + $ok)];
		foreach (array_keys($ok) as $key) {
			$missing = $ok;
			unset($missing[$key]);
			$files['missing ' . $key] = $missing;
		}

		foreach (['payScales', 'allowances', 'leaveEntitlement', 'workingTime'] as $group) {
			$files[$group . ' not an array'] = ([$group => 'x'] + $ok);
			$files[$group . ' verified as text'] = ([$group => ['value' => 1, 'source' => 'cao', 'verified' => 'yes']] + $ok);
			$files[$group . ' unverified'] = ([$group => ['value' => null, 'source' => null, 'verified' => false]] + $ok);
			foreach (['value', 'source', 'verified'] as $field) {
				$broken = $leaf;
				unset($broken[$field]);
				$files[$group . ' without ' . $field] = ([$group => $broken] + $ok);
			}
		}

		return $files;

	}//end caoFiles()

	/**
	 * The contract under test, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function contracts(): array {
		return [
			'plain' => ['id' => 'own', 'employeeId' => 'emp-1', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31'],
			'self id' => ['@self' => ['id' => 'own'], 'employeeId' => 'emp-1', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31'],
			'no id' => ['employeeId' => 'emp-1', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31'],
			'open ended' => ['id' => 'own', 'employeeId' => 'emp-1', 'startDate' => '2026-01-01'],
			'bad start' => ['id' => 'own', 'employeeId' => 'emp-1', 'startDate' => 'soon', 'endDate' => '2026-12-31'],
			'other employee' => ['id' => 'own', 'employeeId' => 'emp-2', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31'],
			'unknown employee' => ['id' => 'own', 'employeeId' => 'emp-9', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31'],
		];

	}//end contracts()

	/**
	 * Sibling contract lists, by name.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function siblingSets(): array {
		$s = static fn (string $id, string $start, ?string $end = null): array => array_filter(['id' => $id, 'startDate' => $start, 'endDate' => $end], static fn ($v) => $v !== null);
		return [
			'none' => [],
			'itself, later' => [$s('own', '2027-01-01')],
			'earlier, open' => [$s('a', '2025-01-01')],
			'same day, open' => [$s('a', '2026-01-01')],
			'later, open' => [$s('a', '2026-06-01')],
			'later, blank end' => [$s('a', '2026-06-01', '  ')],
			'later, ends earlier' => [$s('a', '2026-06-01', '2026-11-30')],
			'later, ends same day' => [$s('a', '2026-06-01', '2026-12-31')],
			'later, ends later' => [$s('a', '2026-06-01', '2027-06-30')],
			'later, bad end' => [$s('a', '2026-06-01', 'never')],
			'bad start' => [$s('a', 'later', '2027-06-30')],
			'no id, later, ends later' => [['startDate' => '2026-06-01', 'endDate' => '2027-06-30']],
			'mixed' => [$s('own', '2027-01-01'), $s('b', '2025-01-01', '2027-01-01'), $s('c', '2026-03-01', '2026-10-01'), $s('d', '2026-04-01', '2027-03-01')],
		];

	}//end siblingSets()

}//end class
