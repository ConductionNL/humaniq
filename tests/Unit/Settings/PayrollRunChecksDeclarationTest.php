<?php

/**
 * The PayrollRunFinding schema, its acknowledgement and the run's counts.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Settings
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
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The fields and the lifecycle the run check relies on.
 */
class PayrollRunChecksDeclarationTest extends TestCase {

	/**
	 * A finding is acknowledged through a declared transition, and an
	 * acknowledged finding with a note is valid.
	 *
	 * @return void
	 */
	public function testAFindingIsAcknowledgedThroughATransition(): void {
		$schema = RegisterSchemaValidator::schema('PayrollRunFinding');
		$lifecycle = $schema['configuration']['x-openregister-lifecycle'];
		$this->assertSame('open', $lifecycle['initial']);
		$this->assertSame(['open'], $lifecycle['transitions']['acknowledge']['from']);
		$this->assertSame('acknowledged', $lifecycle['transitions']['acknowledge']['to']);
		$this->assertSame(['skipped', 'unpaid-input', 'rule-violation', 'deviation'], $schema['properties']['kind']['enum']);
		$this->assertSame(['blocking', 'warning', 'info'], $schema['properties']['severity']['enum']);

		$finding = ['payrollRunId' => 'run-5', 'employeeId' => 'emp-1', 'kind' => 'deviation', 'severity' => 'warning', 'message' => 'Netto wijkt af.', 'component' => 'nettoPay', 'currentValue' => 5600.0, 'baselineValue' => 2800.0, 'explanation' => null, 'ruleId' => null, 'status' => 'acknowledged', 'acknowledgementNote' => 'Eenmalige bonus', 'acknowledgedBy' => 'hr-demo', 'checkedAt' => '2026-05-25T10:00:00Z'];
		$this->assertSame([], RegisterSchemaValidator::errors('PayrollRunFinding', $finding));
	}//end testAFindingIsAcknowledgedThroughATransition()

	/**
	 * The run carries when it was checked and its counts.
	 *
	 * @return void
	 */
	public function testTheRunCarriesItsCounts(): void {
		$run = RegisterSchemaValidator::schema('PayrollRun');
		$fields = ['checkedAt' => '2026-05-25T10:00:00Z', 'blockingFindings' => 2, 'warningFindings' => 0];
		$this->assertSame([], RegisterSchemaValidator::errorsAgainst(['type' => 'object', 'properties' => array_intersect_key($run['properties'], $fields)], $fields));
		$this->assertCount(3, array_intersect_key($run['properties'], $fields));
	}//end testTheRunCarriesItsCounts()

}//end class
