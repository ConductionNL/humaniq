<?php

/**
 * Shipped expense claims are not payable
 *
 * Every Expense humaniq ships is imported on every tenant: the register as
 * RegisterConfigReader assembles it from humaniq_register.json and every
 * register.d fragment, and the mock register. None of them may be paid by a
 * payroll run, in any period.
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
 * @spec openspec/changes/approved-claim-keeps-its-amounts/specs/humaniq-expenses/spec.md#Requirement:-An-approved-expense-claim-SHALL-keep-the-amounts-it-was-approved-for
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\PayrollExpenseFoldService;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\RegisterConfigReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * No shipped claim is payable.
 *
 * @coversNothing
 * @uses \OCA\Humaniq\Service\RegisterConfigReader
 */
class ShippedClaimsAreNotPayableTest extends TestCase {

	/**
	 * Every shipped Expense, keyed by where it ships and its slug: the
	 * assembled register and the mock register.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function shippedClaims(): array {
		$read = (new RegisterConfigReader(new NullLogger()))->read();
		$sources = [
			'register' => ($read['data'] ?? []),
			'humaniq_mock_register.json' => json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/humaniq_mock_register.json'), true),
		];

		$claims = [];
		foreach ($sources as $source => $data) {
			foreach (($data['components']['objects'] ?? []) as $index => $object) {
				if (strtolower((string)($object['@self']['schema'] ?? '')) === 'expense') {
					$claims[$source . '#' . ($object['@self']['slug'] ?? $index)] = $object;
				}
			}
		}

		return $claims;
	}//end shippedClaims()

	/**
	 * The assembled register carries the Expense of every fragment, the
	 * lunch receipt of hr-expense.json among them.
	 *
	 * @return void
	 */
	public function testEveryFragmentIsRead(): void {
		self::assertArrayHasKey('register#expense-devries-lunch-receipt', self::shippedClaims());
		self::assertArrayHasKey('register#expense-devries-train', self::shippedClaims());
	}//end testEveryFragmentIsRead()

	/**
	 * No shipped claim is paid by a payroll run in any month from 2024 to
	 * 2030, by a new run or by a run of its own.
	 *
	 * @return void
	 */
	public function testNoShippedClaimIsPayable(): void {
		$claims = self::shippedClaims();
		self::assertGreaterThanOrEqual(8, count($claims));

		$fold = new PayrollExpenseFoldService($this->createMock(RecordStoreInterface::class), new InternalWriteMarker());
		foreach ($claims as $key => $claim) {
			$employeeId = (string)($claim['employeeId'] ?? '');
			foreach (range(2024, 2030) as $year) {
				foreach (range(1, 12) as $month) {
					$period = sprintf('%04d-%02d', $year, $month);
					foreach (['run-new', (string)($claim['payrollRunId'] ?? '')] as $runId) {
						$paid = $fold->foldFor(['expenses' => [$claim], 'allowances' => [], 'arrangementsById' => []], $employeeId, $period, $runId)['claimCents'];
						self::assertSame(0, $paid, $key . ' is payable in ' . $period);
					}
				}
			}
		}
	}//end testNoShippedClaimIsPayable()

}//end class
