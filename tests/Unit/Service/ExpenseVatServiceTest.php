<?php

/**
 * Unit tests for ExpenseVatService and ExpenseVatController.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/receipt-ocr/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Humaniq\Controller\ExpenseVatController;
use OCA\Humaniq\Service\AnalyticsAccess;
use OCA\Humaniq\Service\AnalyticsService;
use OCA\Humaniq\Service\ExpenseVatService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Finance reads the VAT to reclaim per month (REQ-RCPT-008).
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/receipt-ocr/spec.md#requirement-a-claim-shall-record-its-vat-and-finance-shall-see-the-vat-to-reclaim-per-period-req-rcpt-008
 */
#[CoversClass(ExpenseVatService::class)]
#[CoversClass(ExpenseVatController::class)]
final class ExpenseVatServiceTest extends TestCase {

	/**
	 * The service for a caller who is a full reader or not.
	 *
	 * @param bool $finance Whether the caller is HR or an accountant.
	 *
	 * @return ExpenseVatService
	 */
	private function service(bool $finance = true): ExpenseVatService {
		$analytics = $this->createMock(AnalyticsService::class);
		$analytics->method('rowsFor')->with('Expense', 'adm-1')->willReturn(
			[
				['id' => 'hotel', 'title' => 'Hotel', 'amount' => 121.0, 'vatAmount' => 21.0, 'paidInPeriod' => '2026-05', 'expenseDate' => '2026-05-02'],
				['id' => 'lunch', 'title' => 'Lunch', 'amount' => 109.0, 'vatAmount' => 9.0, 'reimbursedAt' => '2026-05-20T10:00:00Z', 'expenseDate' => '2026-05-01'],
				['id' => 'train', 'title' => 'Train', 'amount' => 30.0, 'vatAmount' => null, 'paidInPeriod' => '2026-05'],
				['id' => 'april', 'title' => 'April', 'amount' => 50.0, 'vatAmount' => 5.0, 'paidInPeriod' => '2026-04'],
				['id' => 'paid-june', 'title' => 'June', 'amount' => 50.0, 'vatAmount' => 5.0, 'paidInPeriod' => '2026-06', 'reimbursedAt' => '2026-05-31'],
			]
		);

		$access = $this->createMock(AnalyticsAccess::class);
		$access->method('fullReaderAdministration')->willReturn($finance === true ? 'adm-1' : null);

		return new ExpenseVatService($analytics, $access);
	}//end service()

	/**
	 * Scenario: finance reads the VAT to reclaim for May.
	 *
	 * @return void
	 */
	public function testFinanceReadsTheVatToReclaimForMay(): void {
		$overview = $this->service()->overview('finance', '2026-05');

		self::assertSame(['lunch', 'hotel'], array_column($overview['claims'], 'id'));
		self::assertSame(30.0, $overview['totalVat']);
		self::assertSame(2, $overview['count']);
		self::assertSame(21.0, $overview['claims'][1]['vatAmount']);
	}//end testFinanceReadsTheVatToReclaimForMay()

	/**
	 * Others are refused; a bad period throws.
	 *
	 * @return void
	 */
	public function testOthersAreRefused(): void {
		self::assertNull($this->service(false)->overview('employee', '2026-05'));

		$this->expectException(InvalidArgumentException::class);
		$this->service()->overview('finance', 'May');
	}//end testOthersAreRefused()

	/**
	 * The controller's answers.
	 *
	 * @return void
	 */
	public function testTheControllerAnswers(): void {
		$controller = function (?string $uid, array $params, bool $finance = true): ExpenseVatController {
			$request = $this->createMock(IRequest::class);
			$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
			$session = $this->createMock(IUserSession::class);
			$user = null;
			if ($uid !== null) {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
			}

			$session->method('getUser')->willReturn($user);

			return new ExpenseVatController($request, $this->service($finance), $session, $this->createMock(LoggerInterface::class));
		};

		self::assertSame(401, $controller(null, [])->overview()->getStatus());
		self::assertSame(400, $controller('finance', ['period' => 'May'])->overview()->getStatus());
		self::assertSame(403, $controller('employee', ['period' => '2026-05'], false)->overview()->getStatus());
		self::assertSame(30.0, $controller('finance', ['period' => '2026-05'])->overview()->getData()['totalVat']);
	}//end testTheControllerAnswers()
}//end class
