<?php

/**
 * Unit tests for DecisionReasonGuard and the proposer rule it chains.
 *
 * A refusal needs a reason, and nobody refuses or approves a pay change
 * they proposed themselves or that is their own.
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
 * @spec openspec/changes/comp-collective-raise-and-step-increase/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\DecisionReasonGuard;
use OCA\Humaniq\Lifecycle\NoSelfApprovalGuard;
use PHPUnit\Framework\TestCase;

/**
 * Refusal with a reason, never by the proposer.
 *
 * @covers \OCA\Humaniq\Lifecycle\DecisionReasonGuard
 * @covers \OCA\Humaniq\Lifecycle\NoSelfApprovalGuard
 */
class DecisionReasonGuardTest extends TestCase {

	/**
	 * A proposed step increase for Jansen, proposed by the HR adviser.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function adjustment(array $overrides = []): array {
		return array_merge(
			[
				'employeeId' => '0127394a-be27-48b4-a592-b6a41774b221',
				'employeeUserId' => 'sjansen',
				'proposedBy' => 'hr-adviseur',
				'status' => 'refused',
				'decisionReason' => 'Beoordeling onvoldoende, zie gesprek 12 mei',
			],
			$overrides
		);
	}//end adjustment()

	/**
	 * The guard under test, with the real proposer rule behind it.
	 *
	 * @return DecisionReasonGuard
	 */
	private function guard(): DecisionReasonGuard {
		return new DecisionReasonGuard(new NoSelfApprovalGuard());
	}//end guard()

	/**
	 * A manager refuses a step without a reason: the refusal is denied.
	 *
	 * @return void
	 */
	public function testARefusalWithoutAReasonIsDenied(): void {
		foreach (['', '   ', null] as $reason) {
			$result = $this->guard()->check($this->adjustment(['decisionReason' => $reason]), 'refuse', 'manager');
			self::assertFalse($result->isAllowed(), 'An empty reason must not refuse a proposal.');
			self::assertStringContainsString('reden', (string)$result->getMessage());
		}
	}//end testARefusalWithoutAReasonIsDenied()

	/**
	 * A manager refuses with a reason: allowed.
	 *
	 * @return void
	 */
	public function testARefusalWithAReasonByAnotherPersonIsAllowed(): void {
		self::assertTrue($this->guard()->check($this->adjustment(), 'refuse', 'manager')->isAllowed());
	}//end testARefusalWithAReasonByAnotherPersonIsAllowed()

	/**
	 * The proposer cannot refuse their own proposal, reason or not.
	 *
	 * @return void
	 */
	public function testTheProposerCannotRefuse(): void {
		$result = $this->guard()->check($this->adjustment(), 'refuse', 'hr-adviseur');
		self::assertFalse($result->isAllowed(), 'The proposer must not refuse their own proposal.');
	}//end testTheProposerCannotRefuse()

	/**
	 * The proposer cannot approve their own proposal: the rule the schema
	 * already promised on CompAdjustment, which compared only `userId` and so
	 * never fired there.
	 *
	 * @return void
	 */
	public function testTheProposerCannotApprove(): void {
		$result = (new NoSelfApprovalGuard())->check($this->adjustment(['status' => 'approved']), 'approve', 'hr-adviseur');
		self::assertFalse($result->isAllowed(), 'The proposer must not approve their own proposal.');
	}//end testTheProposerCannotApprove()

	/**
	 * An employee cannot approve their own raise.
	 *
	 * @return void
	 */
	public function testTheEmployeeCannotApproveTheirOwnRaise(): void {
		$result = (new NoSelfApprovalGuard())->check($this->adjustment(['status' => 'approved']), 'approve', 'sjansen');
		self::assertFalse($result->isAllowed(), 'An employee must not approve their own pay change.');
	}//end testTheEmployeeCannotApproveTheirOwnRaise()

	/**
	 * A second person approves: allowed.
	 *
	 * @return void
	 */
	public function testASecondPersonApproves(): void {
		$result = (new NoSelfApprovalGuard())->check($this->adjustment(['status' => 'approved']), 'approve', 'salarisadministrateur');
		self::assertTrue($result->isAllowed());
	}//end testASecondPersonApproves()

}//end class
