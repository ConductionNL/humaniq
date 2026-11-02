<?php

/**
 * @category Test
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
 * @spec openspec/changes/hr-reopens-an-approved-claim/specs/humaniq-expenses/spec.md#Requirement:-HR-SHALL-be-able-to-reopen-an-approved-expense-claim-that-no-payroll-run-holds
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Expense lifecycle declares the HR reopen.
 *
 * @coversNothing
 */
class ExpenseReopenLifecycleTest extends TestCase {

	/**
	 * reopen goes from approved to submitted, guarded by HrReopenGuard, and
	 * only from approved.
	 *
	 * @return void
	 */
	public function testTheReopenTransitionIsDeclared(): void {
		$data = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/hr-expense.json'), true);
		$transitions = $data['components']['schemas']['Expense']['configuration']['x-openregister-lifecycle']['transitions'];

		self::assertSame(['approved'], $transitions['reopen']['from']);
		self::assertSame('submitted', $transitions['reopen']['to']);
		self::assertSame('OCA\\Humaniq\\Lifecycle\\HrReopenGuard', $transitions['reopen']['requires']);
		self::assertTrue(class_exists($transitions['reopen']['requires']));
		self::assertSame(['draft', 'rejected'], $transitions['submit']['from']);
	}//end testTheReopenTransitionIsDeclared()

}//end class
