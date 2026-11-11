<?php

/**
 * Golden test for PersonPart: the person part and findings of every shape of employee.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll\Loonaangifte
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Loonaangifte;

use OCA\Humaniq\Payroll\Loonaangifte\LineFacts;
use OCA\Humaniq\Payroll\Loonaangifte\PersonPart;
use PHPUnit\Framework\TestCase;

/**
 * The person part and findings of every shape of employee.
 */
class PersonPartGoldenTest extends TestCase {

	/**
	 * Every shape of employee.
	 *
	 * @return void
	 */
	public function testThePersonPartOfEveryShapeOfEmployee(): void {
		$employee = ['id' => 'emp-1', 'firstName' => 'anna maria', 'lastName' => 'de Vries', 'dateOfBirth' => '1990-04-01', 'bsn' => '111222333', 'straat' => 'Een heel lange straatnaam die te lang is', 'huisnummer' => '12a-bis', 'postcode' => '1234 ab', 'woonplaats' => 'Utrecht', 'land' => 'nl'];
		$cases = [
			'complete' => [$employee, []],
			'short-bsn' => [array_merge($employee, ['bsn' => '11222333']), []],
			'bad-bsn' => [array_merge($employee, ['bsn' => '123456789']), []],
			'no-bsn' => [array_merge($employee, ['bsn' => ' ']), []],
			'no-bsn-anonymous' => [array_merge($employee, ['bsn' => null]), ['anoniementariefApplied' => true]],
			'no-name-no-birth' => [array_merge($employee, ['lastName' => '', 'dateOfBirth' => null]), []],
			'plain-number' => [array_merge($employee, ['huisnummer' => '7']), []],
			'number-and-space' => [array_merge($employee, ['huisnummer' => ' 007 B ']), []],
			'no-number' => [array_merge($employee, ['huisnummer' => 'zonder']), []],
			'zero-number' => [array_merge($employee, ['huisnummer' => '0 a']), []],
			'bad-postcode' => [array_merge($employee, ['postcode' => '0123AB']), []],
			'abroad' => [array_merge($employee, ['land' => 'BE']), []],
			'no-country' => [array_diff_key($employee, ['land' => 1]), []],
			'country-written-out' => [array_merge($employee, ['land' => 'Nederland']), []],
			'no-street' => [array_merge($employee, ['straat' => ' ']), []],
			'no-place' => [array_merge($employee, ['woonplaats' => null]), []],
		];
		$got = [];
		foreach ($cases as $name => [$person, $payslip]) {
			$facts = new LineFacts(employee: $person, contract: [], payslip: $payslip, result: null, period: []);
			$got[$name] = ['part' => PersonPart::make($facts), 'findings' => $facts->findings()];
		}

		$this->assertSame((string)file_get_contents(dirname(__DIR__, 3) . '/fixtures/golden/loonaangifte-person-part.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end testThePersonPartOfEveryShapeOfEmployee()
}//end class
