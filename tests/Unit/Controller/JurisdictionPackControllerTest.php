<?php

/**
 * The pack endpoints: admin only, tables only with a pack, deactivate and the year check.
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
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-002
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\JurisdictionPackController;
use OCA\Humaniq\Service\JurisdictionPackService;
use OCA\Humaniq\Service\YearTransitionService;
use OCA\Humaniq\Tests\Unit\Support\PackFixtures;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * JurisdictionPackController.
 */
class JurisdictionPackControllerTest extends TestCase {

	private JurisdictionPackService&MockObject $packs;

	private YearTransitionService&MockObject $years;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->packs = $this->createMock(JurisdictionPackService::class);
		$this->years = $this->createMock(YearTransitionService::class);
	}//end setUp()

	/**
	 * REQ-PKU-003 A non-administrator cannot deactivate.
	 *
	 * @return void
	 */
	public function testANonAdministratorCannotDeactivate(): void {
		$this->packs->expects(self::never())->method('deactivate');

		self::assertSame(403, $this->controller(false)->deactivate('pack-1')->getStatus());
	}//end testANonAdministratorCannotDeactivate()

	/**
	 * REQ-PKU-003 An administrator deactivates and gets the pack back.
	 *
	 * @return void
	 */
	public function testAnAdministratorDeactivates(): void {
		$this->packs->expects(self::once())->method('deactivate')->with('pack-1')->willReturn(['id' => 'pack-1', 'packId' => 'nl-2027', 'active' => false]);

		$response = $this->controller(true)->deactivate('pack-1');

		self::assertSame(200, $response->getStatus());
		self::assertFalse($response->getData()['active']);
	}//end testAnAdministratorDeactivates()

	/**
	 * REQ-PKU-003 Deactivating a pack that does not exist answers 404.
	 *
	 * @return void
	 */
	public function testDeactivatingAnUnknownPackIs404(): void {
		$this->packs->method('deactivate')->willThrowException(new \OutOfBoundsException('not found'));

		self::assertSame(404, $this->controller(true)->deactivate('nope')->getStatus());
	}//end testDeactivatingAnUnknownPackIs404()

	/**
	 * REQ-PKU-001 Tables are forwarded with their pack.
	 *
	 * @return void
	 */
	public function testTheTablesTravelWithThePack(): void {
		$pack = PackFixtures::pack();
		$tables = PackFixtures::tables();
		$this->packs->expects(self::once())->method('upload')->with($pack, false, $tables)->willReturn(
			['packId' => 'nl-2027', 'jurisdiction' => 'NL', 'taxYear' => 2027, 'packVersion' => '1.0.0', 'overridesBundled' => false, 'provenance' => '', 'tables' => 'nl-2027']
		);

		$response = $this->controller(true)->upload($pack, false, $tables);

		self::assertSame(200, $response->getStatus());
		self::assertSame('nl-2027@1.0.0', $response->getData()['engineVersion']);
		self::assertSame('nl-2027', $response->getData()['tables']);
	}//end testTheTablesTravelWithThePack()

	/**
	 * REQ-PKU-001 Tables without a pack are refused: no golden vector can prove them.
	 *
	 * @return void
	 */
	public function testTablesWithoutAPackAreRefused(): void {
		$this->packs->expects(self::never())->method('upload');

		self::assertSame(400, $this->controller(true)->upload(null, false, PackFixtures::tables())->getStatus());
	}//end testTablesWithoutAPackAreRefused()

	/**
	 * REQ-PKU-002 The year check is for administrators and answers the service's resolution.
	 *
	 * @return void
	 */
	public function testTheYearCheckIsForAdministrators(): void {
		$this->years->expects(self::once())->method('resolution')->with('NL', 2027)->willReturn(['resolves' => true, 'packId' => 'nl-2027']);

		self::assertSame(403, $this->controller(false)->resolution(2027)->getStatus());
		$response = $this->controller(true)->resolution(2027);
		self::assertSame(200, $response->getStatus());
		self::assertSame('nl-2027', $response->getData()['packId']);
		self::assertSame(400, $this->controller(true)->resolution(20271)->getStatus(), 'a year is four digits');
	}//end testTheYearCheckIsForAdministrators()

	/**
	 * REQ-PKU-001 The packs list is for administrators.
	 *
	 * @return void
	 */
	public function testThePackListIsForAdministrators(): void {
		$this->packs->method('list')->willReturn([['id' => 'pack-1', 'packId' => 'nl-2027']]);

		self::assertSame(403, $this->controller(false)->index()->getStatus());
		self::assertSame('nl-2027', $this->controller(true)->index()->getData()['packs'][0]['packId']);
	}//end testThePackListIsForAdministrators()

	/**
	 * The controller for an admin or a non-admin caller.
	 *
	 * @param bool $admin Whether the caller is an administrator.
	 *
	 * @return JurisdictionPackController
	 */
	private function controller(bool $admin): JurisdictionPackController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($admin === true ? 'admin' : 'hr-demo');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new JurisdictionPackController(
			request: $this->createMock(IRequest::class),
			packService: $this->packs,
			yearTransition: $this->years,
			userSession: $session,
			groupManager: $groups,
			logger: new NullLogger()
		);
	}//end controller()

}//end class
