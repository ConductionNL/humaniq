<?php

/**
 * Leave expiry and carry-over declaration tests.
 *
 * @category Tests
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
 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Service\LeaveAllocationCalculator;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The balance carries the buckets and the lapse, the leave type the carry-over
 * rule, and the warning is declared in the canonical dialect.
 */
class LeaveExpiryDeclarationTest extends TestCase {

	/**
	 * What the projection writes validates against the real LeaveBalance schema.
	 *
	 * @return void
	 */
	public function testTheWrittenAllocationFitsTheBalanceSchema(): void {
		$balance = [
			'id' => 'b-2025',
			'employeeId' => '0b9b1f0e-2d3c-4a5b-8c6d-7e8f9a0b1c2d',
			'year' => 2025,
			'leaveType' => 'holiday',
			'entitledHours' => 160.0,
			'bovenwettelijkHours' => 20.0,
			'usedHours' => 0.0,
			'expiryDate' => '2026-07-01',
		];
		$figures = (new LeaveAllocationCalculator())->allocate(
			[$balance],
			[['date' => '2025-08-04', 'year' => 2025, 'hours' => 150.0]],
			['code' => 'holiday', 'carryOverRule' => 'capped', 'carryOverCapHours' => 16],
			'2026-07-02'
		)['b-2025'];

		$payload = array_merge($balance, $figures);
		unset($payload['id']);

		self::assertSame([], RegisterSchemaValidator::errors('LeaveBalance', $payload));
		self::assertSame(14.0, $payload['expiredHours']);
	}

	/**
	 * The warning fires once, 60 days before the lapse, to the employee and HR.
	 *
	 * @return void
	 */
	public function testTheWarningIsDeclaredForTheEmployeeAndHr(): void {
		$schema = RegisterSchemaValidator::schema('LeaveBalance');
		$rule = $schema['configuration']['x-openregister-notifications']['statutory-leave-lapses-soon'];

		self::assertSame(
			['type' => 'calculatedChange', 'field' => 'daysUntilStatutoryLapse', 'condition' => ['lte' => 60], 'previously' => ['gt' => 60]],
			$rule['trigger']
		);
		self::assertSame([['kind' => 'field', 'field' => 'userId'], ['kind' => 'groups', 'groups' => ['humaniq-hr']]], $rule['recipients']);
		self::assertTrue($schema['configuration']['x-openregister-calculations']['daysUntilStatutoryLapse']['materialise']);
		self::assertStringContainsString('{{expiryDate}}', $rule['subject']['en']);
	}

	/**
	 * The leave type states its carry-over rule.
	 *
	 * @return void
	 */
	public function testTheLeaveTypeCarriesTheCarryOverRule(): void {
		$type = RegisterSchemaValidator::schema('LeaveType');

		self::assertSame(['all', 'capped', 'none'], $type['properties']['carryOverRule']['enum']);
		self::assertSame(5, $type['properties']['bovenwettelijkExpiryYears']['default']);
		self::assertSame([], RegisterSchemaValidator::errors('LeaveType', ['code' => 'holiday', 'label' => 'Vakantie', 'carryOverRule' => 'capped', 'carryOverCapHours' => 40]));
	}
}
