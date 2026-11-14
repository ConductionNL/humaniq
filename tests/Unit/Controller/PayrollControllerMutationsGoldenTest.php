<?php

/**
 * Golden tests for PayrollController::mutations().
 *
 * Pins the refused AND allowed outcomes of the mutation-report endpoint: a
 * caller outside payroll is 403 before any lookup, a blank toRunId is 400,
 * an unreadable toRunId or fromRunId is 404 before any diff, a refused diff
 * is 400 with its message, a failed persist is 500 with its message, and a
 * successful run answers the persisted report id with the report.
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
 * @spec openspec/specs/payroll-mutation-reports/spec.md#REQ-MUT-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\PayrollController;
use OCA\Humaniq\Service\CallerContext;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\PayrollMutationService;
use OCA\Humaniq\Service\PayrollRunService;
use OCA\Humaniq\Service\PayrollRunServices;
use OCA\Humaniq\Service\ProformaPayslipService;
use OCA\Humaniq\Service\RetroAdjustmentService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\WkrService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * PayrollController::mutations() outcomes.
 */
class PayrollControllerMutationsGoldenTest extends TestCase {

	/**
	 * @var PayrollMutationService&MockObject
	 */
	private PayrollMutationService $mutations;

	/**
	 * The ids the fake register was asked for, in order.
	 *
	 * @var array<int, string>
	 */
	private array $finds = [];

	/**
	 * Build the controller for a user; the register knows run-7 and run-6.
	 *
	 * @param string $uid The session user: `pay` is payroll staff, anyone else is not.
	 *
	 * @return PayrollController
	 */
	private function controller(string $uid): PayrollController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $who, string $group): bool => $who === 'pay' && $group === HumaniqRoles::PAYROLL_GROUP);

		$objects = new class($this->finds) {
			/**
			 * @param array<int, string> $finds The ids asked for (by reference).
			 */
			public function __construct(
				private array &$finds,
			) {
			}//end __construct()

			/**
			 * Accepts whatever find signature the controller uses.
			 *
			 * @param string $id The object id.
			 * @param mixed ...$rest Register/schema arguments (unused).
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, mixed ...$rest): ?array {
				$this->finds[] = $id;
				$rows = ['run-7' => ['id' => 'run-7'], 'run-6' => ['id' => 'run-6']];
				return ($rows[$id] ?? null);
			}//end find()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		$this->mutations = $this->createMock(PayrollMutationService::class);
		$runs = new PayrollRunServices(
			$this->createMock(PayrollRunService::class),
			$this->mutations,
			$this->createMock(ProformaPayslipService::class),
			$this->createMock(RetroAdjustmentService::class)
		);

		return new PayrollController(
			$this->createMock(IRequest::class),
			$runs,
			$this->createMock(WkrService::class),
			$settings,
			new CallerContext($session, new HumaniqRoles($groups), $container, new NullLogger())
		);
	}//end controller()

	/**
	 * A caller outside payroll is refused before any lookup or diff.
	 *
	 * @return void
	 */
	public function testCallerOutsidePayrollIs403BeforeAnyLookup(): void {
		$controller = $this->controller('clerk');
		$this->mutations->expects($this->never())->method('diff');

		$response = $controller->mutations('run-7', 'run-6');

		self::assertSame(403, $response->getStatus());
		self::assertSame(['error' => 'Alleen de salarisadministratie en beheerders mogen mutatierapporten genereren.'], $response->getData());
		self::assertSame([], $this->finds);
	}//end testCallerOutsidePayrollIs403BeforeAnyLookup()

	/**
	 * A blank toRunId is 400 before any lookup.
	 *
	 * @return void
	 */
	public function testBlankToRunIdIs400(): void {
		$controller = $this->controller('pay');
		$this->mutations->expects($this->never())->method('diff');

		$response = $controller->mutations('  ', 'run-6');

		self::assertSame(400, $response->getStatus());
		self::assertSame(['error' => 'toRunId is verplicht.'], $response->getData());
		self::assertSame([], $this->finds);
	}//end testBlankToRunIdIs400()

	/**
	 * An unreadable toRunId is 404 before the baseline is looked up.
	 *
	 * @return void
	 */
	public function testUnreadableToRunIs404(): void {
		$controller = $this->controller('pay');
		$this->mutations->expects($this->never())->method('diff');

		$response = $controller->mutations(' run-hidden ', 'run-6');

		self::assertSame(404, $response->getStatus());
		self::assertSame(['error' => 'Loonrun niet gevonden.'], $response->getData());
		self::assertSame(['run-hidden'], $this->finds);
	}//end testUnreadableToRunIs404()

	/**
	 * An unreadable fromRunId is 404 before any diff.
	 *
	 * @return void
	 */
	public function testUnreadableFromRunIs404(): void {
		$controller = $this->controller('pay');
		$this->mutations->expects($this->never())->method('diff');

		$response = $controller->mutations('run-7', ' run-hidden ');

		self::assertSame(404, $response->getStatus());
		self::assertSame(['error' => 'Loonrun niet gevonden.'], $response->getData());
		self::assertSame(['run-7', 'run-hidden'], $this->finds);
	}//end testUnreadableFromRunIs404()

	/**
	 * A blank fromRunId lets the service resolve the prior period; a refused
	 * diff is 400 with its message and nothing is persisted.
	 *
	 * @return void
	 */
	public function testRefusedDiffIs400AndNothingIsPersisted(): void {
		$controller = $this->controller('pay');
		$this->mutations->expects($this->once())->method('diff')->with('run-7', null)
			->willReturn(['status' => 'failed', 'message' => 'Andere administratie.']);
		$this->mutations->expects($this->never())->method('persist');

		$response = $controller->mutations('run-7', '  ');

		self::assertSame(400, $response->getStatus());
		self::assertSame(['error' => 'Andere administratie.'], $response->getData());
		self::assertSame(['run-7'], $this->finds);
	}//end testRefusedDiffIs400AndNothingIsPersisted()

	/**
	 * A failed persist is 500 with its message.
	 *
	 * @return void
	 */
	public function testFailedPersistIs500(): void {
		$controller = $this->controller('pay');
		$this->mutations->method('diff')->willReturn(['status' => 'ok', 'report' => ['lines' => 1]]);
		$this->mutations->expects($this->once())->method('persist')->with(['lines' => 1])
			->willReturn(['status' => 'failed', 'message' => 'Opslaan mislukt.']);

		$response = $controller->mutations('run-7', 'run-6');

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => 'Opslaan mislukt.'], $response->getData());
	}//end testFailedPersistIs500()

	/**
	 * A successful run answers the persisted report id and the report.
	 *
	 * @return void
	 */
	public function testSuccessfulRunAnswersTheReport(): void {
		$controller = $this->controller('pay');
		$this->mutations->expects($this->once())->method('diff')->with('run-7', 'run-6')
			->willReturn(['status' => 'ok', 'report' => ['lines' => 3]]);
		$this->mutations->method('persist')->willReturn(['status' => 'ok', 'reportId' => 'rep-1']);

		$response = $controller->mutations(' run-7 ', ' run-6 ');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['reportId' => 'rep-1', 'report' => ['lines' => 3]], $response->getData());
		self::assertSame(['run-7', 'run-6'], $this->finds);
	}//end testSuccessfulRunAnswersTheReport()
}//end class
