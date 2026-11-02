<?php

/**
 * Which caller the payroll endpoints check, and which ids reach the services.
 *
 * Only the session user's role counts: "pay" is in the payroll group,
 * "clerk" is in no group, and only "boss" is an administrator. Every service
 * call is pinned to the id the caller asked for.
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
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-008
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The payroll endpoints act for the session user and pass the asked ids on.
 */
class PayrollControllerCallerTest extends TestCase {

	/**
	 * A controller for one session user over the given services.
	 *
	 * @param string $uid The session user.
	 * @param PayrollRunServices $runs The run services.
	 *
	 * @return PayrollController
	 */
	private function controller(string $uid, PayrollRunServices $runs): PayrollController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $who): bool => $who === 'boss');
		$groups->method('isInGroup')->willReturnCallback(static fn (string $who, string $group): bool => $who === 'pay' && $group === HumaniqRoles::PAYROLL_GROUP);

		$rows = ['run-7' => ['id' => 'run-7', 'status' => 'draft'], 'run-6' => ['id' => 'run-6', 'status' => 'approved'], 'adj-3' => ['id' => 'adj-3', 'status' => 'open']];
		$objects = new class($rows) {

			/**
			 * @param array<string, array<string, mixed>> $rows Rows by id.
			 */
			public function __construct(
				private readonly array $rows,
			) {
			}

			/**
			 * One row by id.
			 *
			 * @param string $id The id.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, string $register = '', string $schema = ''): ?array {
				return ($this->rows[$id] ?? null);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		return new PayrollController(
			$this->createMock(IRequest::class),
			$runs,
			$this->createMock(WkrService::class),
			$settings,
			new CallerContext($session, new HumaniqRoles($groups), $container, new NullLogger())
		);
	}//end controller()

	/**
	 * Run services with the given doubles, mocks elsewhere.
	 *
	 * @param PayrollRunService|null $run The run service.
	 * @param PayrollMutationService|null $mutations The mutation service.
	 * @param RetroAdjustmentService|null $retro The retro service.
	 *
	 * @return PayrollRunServices
	 */
	private function runs(?PayrollRunService $run = null, ?PayrollMutationService $mutations = null, ?RetroAdjustmentService $retro = null): PayrollRunServices {
		return new PayrollRunServices(
			($run ?? $this->createMock(PayrollRunService::class)),
			($mutations ?? $this->createMock(PayrollMutationService::class)),
			$this->createMock(ProformaPayslipService::class),
			($retro ?? $this->createMock(RetroAdjustmentService::class))
		);
	}//end runs()

	/**
	 * A payroll clerk recalculates the run asked for; a user without a role is
	 * refused before the engine runs.
	 *
	 * @return void
	 */
	public function testOnlyTheSessionUsersRoleCountsForARecalculation(): void {
		$run = $this->createMock(PayrollRunService::class);
		$run->expects($this->once())->method('recalculateRun')->with('run-7')->willReturn(['status' => 'calculated']);

		$this->assertSame(200, $this->controller('pay', $this->runs($run))->calculate('run-7')->getStatus());

		$never = $this->createMock(PayrollRunService::class);
		$never->expects($this->never())->method('recalculateRun');
		$this->assertSame(403, $this->controller('clerk', $this->runs($never))->calculate('run-7')->getStatus());
	}//end testOnlyTheSessionUsersRoleCountsForARecalculation()

	/**
	 * The mutation report diffs and persists the runs asked for.
	 *
	 * @return void
	 */
	public function testTheMutationReportUsesTheAskedRuns(): void {
		$mutations = $this->createMock(PayrollMutationService::class);
		$mutations->expects($this->once())->method('diff')->with('run-7', 'run-6')->willReturn(['status' => 'ok', 'report' => ['lines' => 3]]);
		$mutations->expects($this->once())->method('persist')->with(['lines' => 3])->willReturn(['status' => 'ok', 'reportId' => 'rep-1']);

		$response = $this->controller('pay', $this->runs(null, $mutations))->mutations('run-7', 'run-6');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('rep-1', $response->getData()['reportId']);
	}//end testTheMutationReportUsesTheAskedRuns()

	/**
	 * A retro adjustment recomputes the adjustment asked for.
	 *
	 * @return void
	 */
	public function testAnAdjustmentRecomputesTheAskedAdjustment(): void {
		$retro = $this->createMock(RetroAdjustmentService::class);
		$retro->expects($this->once())->method('recomputeAdjustment')->with('adj-3')->willReturn(['status' => 'computed']);

		$this->assertSame(200, $this->controller('pay', $this->runs(null, null, $retro))->adjust('adj-3')->getStatus());
	}//end testAnAdjustmentRecomputesTheAskedAdjustment()
}//end class
