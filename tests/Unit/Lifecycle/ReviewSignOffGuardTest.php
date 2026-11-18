<?php

/**
 * Unit tests for ReviewSignOffGuard.
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
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/performance-reviews/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\ReviewSignOffGuard;
use PHPUnit\Framework\TestCase;

/**
 * Only the reviewed employee signs their own review.
 *
 * @covers \OCA\Humaniq\Lifecycle\ReviewSignOffGuard
 */
class ReviewSignOffGuardTest extends TestCase {

	/**
	 * A set review of Sanne's account.
	 *
	 * @var array<string, mixed>
	 */
	private const REVIEW = ['status' => 'vastgesteld', 'employeeId' => 'emp-sanne', 'userId' => 'sanne', 'reviewerUserId' => 'manager'];

	/**
	 * Sanne signs her own review.
	 *
	 * @return void
	 */
	public function testTheEmployeeSignsTheirOwnReview(): void {
		$this->assertTrue((new ReviewSignOffGuard())->check(self::REVIEW, 'ondertekenen', 'sanne')->isAllowed());
	}//end testTheEmployeeSignsTheirOwnReview()

	/**
	 * The reviewer, or anyone else, cannot sign as the employee.
	 *
	 * @return void
	 */
	public function testSomeoneElseCannotSignForTheEmployee(): void {
		$guard = new ReviewSignOffGuard();

		foreach (['manager', 'hr.user', 'sanne2'] as $uid) {
			$result = $guard->check(self::REVIEW, 'ondertekenen', $uid);
			$this->assertFalse($result->isAllowed(), $uid . ' signed for the employee');
			$this->assertSame('Alleen de medewerker zelf kan deze beoordeling ondertekenen.', $result->getMessage());
		}
	}//end testSomeoneElseCannotSignForTheEmployee()

	/**
	 * Fails closed: no caller, or a review without an employee account.
	 *
	 * @return void
	 */
	public function testItFailsClosed(): void {
		$guard = new ReviewSignOffGuard();

		$this->assertFalse($guard->check(self::REVIEW, 'ondertekenen', '  ')->isAllowed());
		$this->assertFalse($guard->check(['userId' => ''] + self::REVIEW, 'ondertekenen', '')->isAllowed());
		$noAccount = $guard->check(['userId' => null] + self::REVIEW, 'ondertekenen', 'sanne');
		$this->assertFalse($noAccount->isAllowed());
		$this->assertStringContainsString('zonder account', (string)$noAccount->getMessage());
	}//end testItFailsClosed()
}//end class
