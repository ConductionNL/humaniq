<?php

/**
 * The employee is told about an approved or refused pay change.
 *
 * Walks the CompAdjustment schema in the register fragment: the `approve` and
 * `refuse` transitions each carry one declared nc-notification rule, addressed
 * to the employee's account field and carrying the decision reason. The rule
 * shape itself was validated with OpenRegister's
 * NotificationAnnotationValidator (0 errors) when it was written; this pins
 * that it stays wired to the right transitions, field and reason.
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
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Declared decision notifications on CompAdjustment.
 */
class CompDecisionNotificationTest extends TestCase {

	/**
	 * Each decision transition has one rule to the employee with the reason.
	 *
	 * @return void
	 */
	public function testApprovalAndRefusalReachTheEmployeeWithTheReason(): void {
		$schema = RegisterSchemaValidator::schema('CompAdjustment');
		$rules = ($schema['configuration']['x-openregister-notifications'] ?? []);
		$transitions = ($schema['configuration']['x-openregister-lifecycle']['transitions'] ?? []);

		$byAction = [];
		foreach ($rules as $rule) {
			$byAction[(string)($rule['trigger']['action'] ?? '')] = $rule;
		}

		foreach (['approve', 'refuse'] as $action) {
			self::assertArrayHasKey($action, $transitions, 'CompAdjustment declares the ' . $action . ' transition.');
			self::assertArrayHasKey($action, $byAction, 'A rule fires on ' . $action . '.');
			$rule = $byAction[$action];
			self::assertSame('transition', $rule['trigger']['type']);
			self::assertSame([['kind' => 'field', 'field' => 'employeeUserId']], $rule['recipients']);
			self::assertArrayHasKey('employeeUserId', $schema['properties']);
			self::assertStringContainsString('{{decisionReason}}', $rule['message']['nl']);
			self::assertStringContainsString('{{decisionReason}}', $rule['message']['en']);
		}

		self::assertSame('refused', $transitions['refuse']['to']);
		self::assertSame('OCA\Humaniq\Lifecycle\DecisionReasonGuard', $transitions['refuse']['requires']);
		self::assertContains(['field' => 'decisionReason', 'required' => true], $transitions['refuse']['inputs']);
	}//end testApprovalAndRefusalReachTheEmployeeWithTheReason()

}//end class
