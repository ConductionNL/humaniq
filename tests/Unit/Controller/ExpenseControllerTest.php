<?php

/**
 * ExpenseController test
 *
 * `POST /api/expenses/extract-receipt` is a guarded endpoint with three
 * refusals ahead of any work — a blank id, an expense that does not resolve
 * under the caller's RBAC, and a caller who is neither admin nor the claim's
 * owner — and it had no test at all.
 *
 * The refusals are asserted against `$fake->findCalled` and
 * `$receiptExtractionService`'s call count rather than against status codes
 * alone: a 404 proves what the caller sees, and only the absence of the reach
 * proves the guard ran BEFORE the store was touched. A guard that refuses
 * after resolving is an IDOR that happens to return the right number.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
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
 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\ExpenseController;
use OCA\Humaniq\Service\ReceiptExtractionService;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The extract-receipt endpoint's guards.
 */
class ExpenseControllerTest extends TestCase {

	/**
	 * A representative Expense row.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function expense(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'exp-1',
				'userId' => 'employee-1',
				'receiptFile' => 'bon.pdf',
			],
			$overrides
		);
	}//end expense()

	/**
	 * A blank expenseId is refused 400 before any resolve.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-007
	 */
	public function testBlankExpenseIdReturns400BeforeAnyResolve(): void {
		[$controller, $fake, $service] = $this->buildController(isAdmin: true, uid: 'hr-admin', expenseRow: $this->expense());
		$service->expects($this->never())->method('extractForExpense');

		$response = $controller->extractReceipt('   ');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($fake->findCalled);

	}//end testBlankExpenseIdReturns400BeforeAnyResolve()

	/**
	 * An expense that does not resolve under the caller's RBAC is 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-007
	 */
	public function testUnresolvableExpenseReturns404(): void {
		[$controller, , $service] = $this->buildController(isAdmin: true, uid: 'hr-admin', expenseRow: null);
		$service->expects($this->never())->method('extractForExpense');

		$response = $controller->extractReceipt('exp-ghost');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUnresolvableExpenseReturns404()

	/**
	 * An instance without OpenRegister answers 404, not 500.
	 *
	 * ADR-083: a controller is the wrong place to turn a missing optional app
	 * into a server error — the caller of an HTTP endpoint cannot install
	 * anything, and "cannot be resolved" is already this endpoint's documented
	 * outcome. `$fake->findCalled` is asserted false so this proves the guard
	 * runs BEFORE the reach.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-007
	 */
	public function testMissingOpenRegisterReturns404WithoutReachingTheStore(): void {
		[$controller, $fake, $service] = $this->buildController(
			isAdmin: true,
			uid: 'hr-admin',
			expenseRow: $this->expense(),
			openRegisterAvailable: false
		);
		$service->expects($this->never())->method('extractForExpense');

		$response = $controller->extractReceipt('exp-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertFalse($fake->findCalled, 'the store was reached despite OpenRegister being unavailable');

	}//end testMissingOpenRegisterReturns404WithoutReachingTheStore()

	/**
	 * A caller who is neither admin nor the claim's owner is refused 403,
	 * AFTER the expense resolves — the 403/404 split is deliberate, and this
	 * asserts the resolve happened so the two cannot silently collapse.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-007
	 */
	public function testForeignClaimIsRefused403(): void {
		[$controller, $fake, $service] = $this->buildController(isAdmin: false, uid: 'someone-else', expenseRow: $this->expense());
		$service->expects($this->never())->method('extractForExpense');

		$response = $controller->extractReceipt('exp-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertTrue($fake->findCalled);

	}//end testForeignClaimIsRefused403()

	/**
	 * The claim's own submitter may extract it without being an admin.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receipt-ocr/specs/receipt-ocr/spec.md#REQ-RCPT-007
	 */
	public function testOwnerMayExtractTheirOwnClaim(): void {
		[$controller, , $service] = $this->buildController(isAdmin: false, uid: 'employee-1', expenseRow: $this->expense());

		$outcome = ['expenseId' => 'exp-1', 'status' => 'extracted'];
		$service->expects($this->once())
			->method('extractForExpense')
			->with('exp-1', 'employee-1')
			->willReturn($outcome);

		$response = $controller->extractReceipt('exp-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($outcome, $response->getData());

	}//end testOwnerMayExtractTheirOwnClaim()

	/**
	 * Build the controller over a fake ObjectService.
	 *
	 * @param bool $isAdmin Whether the caller is a Nextcloud admin.
	 * @param string $uid The caller's user id.
	 * @param array<string, mixed>|null $expenseRow The row `find()` should return.
	 * @param bool $openRegisterAvailable Whether OpenRegister is installed.
	 *
	 * @return array{0: ExpenseController, 1: object, 2: ReceiptExtractionService&\PHPUnit\Framework\MockObject\MockObject}
	 */
	private function buildController(bool $isAdmin, string $uid, ?array $expenseRow, bool $openRegisterAvailable = true): array {
		$request = $this->createMock(IRequest::class);

		$fake = new class($expenseRow) {

			/**
			 * @var array<string, mixed>|null
			 */
			public ?array $row;

			/**
			 * @var bool
			 */
			public bool $findCalled = false;

			/**
			 * @param array<string, mixed>|null $row The row find() should return.
			 */
			public function __construct(?array $row) {
				$this->row = $row;

			}//end __construct()

			/**
			 * @param string $id The object id.
			 * @param string|null $register Register slug (unused by the fake).
			 * @param string|null $schema Schema name (unused by the fake).
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, ?string $register = null, ?string $schema = null): ?array {
				$this->findCalled = true;
				return $this->row;
			}//end find()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($fake);

		$receiptExtractionService = $this->createMock(ReceiptExtractionService::class);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		// createMock() answers a bool method with false, so this has to be
		// stated even for the available case (ADR-083 guard).
		$settings->method('isOpenRegisterAvailable')->willReturn($openRegisterAvailable);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		$logger = $this->createMock(LoggerInterface::class);

		return [
			new ExpenseController($request, $container, $receiptExtractionService, $settings, $userSession, $groupManager, $logger),
			$fake,
			$receiptExtractionService,
		];

	}//end buildController()

}//end class
