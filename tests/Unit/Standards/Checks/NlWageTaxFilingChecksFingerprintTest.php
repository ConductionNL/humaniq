<?php

/**
 * Characterisation test for the NlWageTaxFilingChecks predicates.
 *
 * Pins every predicate's allowed and refused outcomes over a generated grid of
 * objects (the seed samples, an empty object and each single-field variation)
 * as a per-rule hash, measured on the code before the predicates were split
 * into named methods. The time-dependent rules (nl-loonaangifte-deadline-alert) are left out: their outcome moves with the clock.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
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

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlWageTaxFilingChecks;
use PHPUnit\Framework\TestCase;

/**
 * Every NlWageTaxFilingChecks predicate keeps its outcomes.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */
class NlWageTaxFilingChecksFingerprintTest extends TestCase {

	/**
	 * The fields the predicates read.
	 *
	 * @var list<string>
	 */
	private const FIELDS = [
		'deadline',
		'electronicallyFiled',
		'filingType',
		'jurisdiction',
		'period',
		'retainedUntil',
		'status',
		'submittedDate',
		'tijdvak',
		'tijdvakcode',
	];

	/**
	 * Field values that select a branch, applied to each seed before the grid.
	 *
	 * @var array<string, list<mixed>>
	 */
	private const SELECTORS = [
		'filingType' => ['loonaangifte', 'dsn'],
		'jurisdiction' => ['NL', 'DE'],
		'tijdvak' => ['maand', 'jaar', 'vierweken', 'kwartaal'],
	];

	/**
	 * Rules left out because their outcome depends on the current time.
	 *
	 * @var list<string>
	 */
	private const TIME_DEPENDENT = ['nl-loonaangifte-deadline-alert'];

	/**
	 * Per object type and rule: the count of passing objects and the outcome hash.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const EXPECTED = [
		'LoonaangifteFiling' => [
			'nl-loonaangifte-tijdvak' => '9635:57f7ab21beac8c1fd33055bdc79bee13',
			'nl-loonaangifte-termijn' => '9739:78b1f26685bd82701355eda11deb7aea',
			'nl-loonadministratie-bewaarplicht' => '9691:3563b1e9e3fe3130d273c67a62de9e58',
			'nl-loonaangifte-tijdvakcode' => '10487:63ae0e319c4cd7cc86b37155d4bdd9e4',
			'nl-loonaangifte-deadline-derivation' => '10549:18da9e111c638925a3ac6162c08ac778',
			'nl-loonaangifte-message-drift' => '11178:2e8fe1304e540780bd2d1a9e8cab2c94',
		],
	];

	/**
	 * @return void
	 */
	public function testEveryPredicateKeepsItsOutcomes(): void {
		$seeds  = NlWageTaxFilingChecks::seedObjects();
		$actual = [];
		foreach (NlWageTaxFilingChecks::checks() as $type => $rules) {
			$grid = PredicateFingerprint::grid(PredicateFingerprint::rebase($seeds[$type] ?? [], self::SELECTORS), self::FIELDS);
			$actual[$type] = [];
			foreach ($rules as $ruleId => $predicate) {
				if (in_array($ruleId, self::TIME_DEPENDENT, true) === false) {
					$actual[$type][$ruleId] = PredicateFingerprint::of($predicate, $grid);
				}
			}
		}

		$this->assertSame(self::EXPECTED, $actual);

	}//end testEveryPredicateKeepsItsOutcomes()
}//end class
