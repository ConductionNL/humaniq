<?php

/**
 * Characterisation test for the NlLeaveChecks table-check derivations.
 *
 * Pins what every `derive` step of NlLeaveChecks::tables() makes of a generated
 * grid of LeaveBalance objects (each single-field variation over a fixed value
 * list, and the empty object), as a per-rule hash measured on the code before
 * the derivations were split into named methods. The gate tables themselves
 * are compared whole.
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

use OCA\Humaniq\Standards\Checks\NlLeaveChecks;
use PHPUnit\Framework\TestCase;

/**
 * Every NlLeaveChecks derivation keeps its output.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */
class NlLeaveChecksTablesFingerprintTest extends TestCase {

	/**
	 * The fields the derivations read.
	 *
	 * @var list<string>
	 */
	private const FIELDS = ['contractHoursPerWeek', 'entitledHours', 'usedHours', 'bovenwettelijkHours', 'year', 'expiryDate'];

	/**
	 * @return void
	 */
	public function testEveryDerivationKeepsItsOutput(): void {
		$seed = ['contractHoursPerWeek' => 36, 'entitledHours' => 144, 'usedHours' => 40, 'bovenwettelijkHours' => 10, 'year' => 2026, 'expiryDate' => '2027-07-01'];
		$grid = PredicateFingerprint::grid([$seed], self::FIELDS);

		$actual = [];
		foreach (NlLeaveChecks::tables()['LeaveBalance'] as $ruleId => $check) {
			$outputs = '';
			foreach ($grid as $object) {
				$outputs .= json_encode($check['derive']($object)).';';
			}

			$actual[$ruleId] = [md5($outputs), md5(json_encode($check['table']))];
		}

		$this->assertSame(
			[
				'nl-verlof-wettelijk-minimum' => ['4c33ba58fc3bc8eaf3ccf6b10924ed25', '46a2ada7d1276ede36401703a5811167'],
				'nl-verlof-saldo-niet-negatief' => ['9ea16125685b1a8096f13ec1a14c080a', '69e73eb5a92f7eb9a021b209ac52f4f7'],
				'nl-verlof-vervaltermijn' => ['9cfdc98024b9e353b7cbb789aac281e4', '1dcd2c487e8ed97481f0353c7da0b4c7'],
				'nl-verlof-bovenwettelijk-niet-negatief' => ['19a21d4374d314ca7e6b976a9a940302', 'dde4b24cc4681844036e1ca837850d36'],
			],
			$actual
		);

	}//end testEveryDerivationKeepsItsOutput()
}//end class
