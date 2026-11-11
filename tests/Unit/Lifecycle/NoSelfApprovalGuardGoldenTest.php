<?php

/**
 * Golden test for NoSelfApprovalGuard: every decision allowed or refused, and why.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Lifecycle
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
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\NoSelfApprovalGuard;
use PHPUnit\Framework\TestCase;

/**
 * Every decision allowed or refused, and why.
 */
class NoSelfApprovalGuardGoldenTest extends TestCase {

	/**
	 * Every object against every caller.
	 *
	 * @return void
	 */
	public function testEveryDecisionAllowedOrRefused(): void {
		$objects = [
			'timesheet' => ['employeeId' => 'emp-1', 'userId' => ' pieter '],
			'no-account' => ['employeeId' => 'emp-1', 'userId' => ''],
			'unknown-claimant' => ['userId' => 'pieter'],
			'pay-change' => ['employeeId' => 'emp-2', 'employeeUserId' => 'anouk', 'proposedBy' => 'hr.one'],
			'pay-change-all-same' => ['employeeId' => 'emp-2', 'userId' => 'anouk', 'employeeUserId' => 'anouk', 'proposedBy' => 'anouk'],
			'padded-proposer' => ['employeeId' => 'emp-2', 'proposedBy' => ' hr.one '],
		];
		$got = [];
		$guard = new NoSelfApprovalGuard();
		foreach ($objects as $name => $object) {
			foreach (['pieter', 'anouk', 'hr.one', 'manager', ''] as $userId) {
				$result = $guard->check($object, 'approve', $userId);
				$got[$name . ':' . $userId] = [$result->isAllowed(), $result->getMessage()];
			}
		}

		$this->assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/no-self-approval.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end testEveryDecisionAllowedOrRefused()
}//end class
