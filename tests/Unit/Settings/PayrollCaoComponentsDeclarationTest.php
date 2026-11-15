<?php

/**
 * The contract and payslip fields the CAO components rely on.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit
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
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The schemas declare the fields the resolver and the run read and write.
 */
class PayrollCaoComponentsDeclarationTest extends TestCase {

	/**
	 * The contract names its components and overrides them with a reason.
	 *
	 * @return void
	 */
	public function testTheContractDeclaresItsComponents(): void {
		$properties = RegisterSchemaValidator::schema('EmploymentContract')['properties'];
		foreach (['caoComponents', 'caoComponentOverrides', 'caoComponentOverrideReason'] as $field) {
			$this->assertArrayHasKey($field, $properties);
		}
	}//end testTheContractDeclaresItsComponents()

	/**
	 * The payslip lists each component with its basis, the total and what
	 * was not paid.
	 *
	 * @return void
	 */
	public function testThePayslipDeclaresItsComponentLines(): void {
		$properties = RegisterSchemaValidator::schema('Payslip')['properties'];
		$this->assertSame(['key', 'kind', 'basis', 'hours', 'pct', 'amount', 'source'], array_keys($properties['caoComponentLines']['items']['properties']));
		$this->assertArrayHasKey('caoComponentsTotal', $properties);
		$this->assertArrayHasKey('caoComponentsUnresolved', $properties);
	}//end testThePayslipDeclaresItsComponentLines()

}//end class
