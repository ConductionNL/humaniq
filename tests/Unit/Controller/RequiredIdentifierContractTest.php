<?php

/**
 * Contract tests for the required-identifier guard on comp and expense writes.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/comp-cycles/spec.md
 * @spec openspec/specs/humaniq-expenses/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\CompController;
use OCA\Humaniq\Controller\ExpenseController;
use OCA\Humaniq\Service\CompAdjustmentService;
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
 * Contract tests for POST /api/comp/effectuate and
 * POST /api/expenses/extract-receipt (gate-25).
 *
 * Both endpoints take an identifier and act on the object it names, and both
 * refuse an EMPTY identifier with 400 before doing anything. That guard is the
 * contract worth pinning: without it an empty string reaches a lookup, and what
 * a lookup does with "" is a question no caller should be able to ask.
 *
 * The success paths need a live OpenRegister register and belong to the
 * integration suite. The ObjectService double here throws on every call, so if
 * a change ever lets these endpoints reach the register before validating their
 * input, the test fails loudly instead of quietly passing.
 */
class RequiredIdentifierContractTest extends TestCase {

	/**
	 * An empty adjustmentId is refused before anything is read.
	 *
	 * @return void
	 */
	public function testCompEffectuateRequiresAnAdjustmentId(): void {
		$controller = new CompController(
			$this->createMock(IRequest::class),
			$this->hostileContainer(),
			$this->createMock(CompAdjustmentService::class),
			$this->settings(),
			$this->createMock(LoggerInterface::class)
		);

		$response = $controller->effectuate('');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testCompEffectuateRequiresAnAdjustmentId()

	/**
	 * An empty expenseId is refused before anything is read.
	 *
	 * @return void
	 */
	public function testExpenseExtractReceiptRequiresAnExpenseId(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('tester');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);
		$groupManager->method('isInGroup')->willReturn(true);

		$controller = new ExpenseController(
			$this->createMock(IRequest::class),
			$this->hostileContainer(),
			$this->createMock(ReceiptExtractionService::class),
			$this->settings(),
			$userSession,
			$groupManager,
			$this->createMock(LoggerInterface::class)
		);

		$response = $controller->extractReceipt('');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testExpenseExtractReceiptRequiresAnExpenseId()

	/**
	 * A container whose ObjectService refuses every call.
	 *
	 * @return ContainerInterface
	 */
	private function hostileContainer(): ContainerInterface {
		$objectService = new class {

			/**
			 * @param string $name The called method.
			 * @param array<int, mixed> $args The call arguments.
			 *
			 * @return mixed
			 */
			public function __call(string $name, array $args): mixed {
				throw new \RuntimeException(
					'the identifier guard must refuse before ObjectService::' . $name
				);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return $container;
	}//end hostileContainer()

	/**
	 * SettingsService reporting the app's own register slug.
	 *
	 * @return SettingsService
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');
		// objectService() now establishes availability first (ADR-083). A bare
		// createMock() answers a bool method with false, so without this the
		// guard trips and the test fails on a missing app, not on its subject.
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		return $settings;
	}//end settings()

}//end class
