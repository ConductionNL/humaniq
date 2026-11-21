<?php

/**
 * Unit tests for AnalyticsController's billable-share endpoints.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/humaniq-dashboard-steering-indicators/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\AnalyticsController;
use OCA\Humaniq\Kernel\Contracts\AnalyticsServiceInterface;
use OCA\Humaniq\Kernel\Contracts\ObligationsServiceInterface;
use OCA\Humaniq\Kernel\Contracts\SeriesLatestInterface;
use OCA\Humaniq\Service\AnalyticsAccess;
use OCA\Humaniq\Service\AnalyticsService;
use OCA\Humaniq\Service\BillableShareService;
use OCA\Humaniq\Service\UnitMembership;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The guarded billable-share reads (REQ-DSI-010).
 *
 * @spec openspec/specs/humaniq-dashboard-steering-indicators/spec.md#requirement-the-billable-share-shall-be-readable-per-team-and-per-person-req-dsi-010
 */
#[CoversClass(AnalyticsController::class)]
#[UsesClass(BillableShareService::class)]
#[UsesClass(UnitMembership::class)]
final class AnalyticsBillableShareControllerTest extends TestCase {

	/**
	 * A controller for the caller and the request parameters.
	 *
	 * @param string|null          $uid    The signed-in user.
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return AnalyticsController
	 */
	private function controller(?string $uid, array $params): AnalyticsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$access = $this->createMock(AnalyticsAccess::class);
		$access->method('activeAdministration')->willReturn('adm-1');
		$access->method('unitReader')->willReturnCallback(static fn (string $userId, string $unitId): ?array => ($unitId === 'u-own' ? ['administrationId' => 'adm-1', 'minimumMembers' => 5] : null));

		$analytics = $this->createMock(AnalyticsService::class);
		$analytics->method('rowsFor')->willReturnCallback(
			static fn (string $schema): array => match ($schema) {
				'OrgUnit' => [['id' => 'u-own', 'name' => 'Own team', 'managerId' => 'emp-boss']],
				'Employee' => [['id' => 'emp-boss', 'nextcloudUserId' => 'boss']],
				default => [],
			}
		);

		return new AnalyticsController(
			$request,
			$this->createMock(AnalyticsServiceInterface::class),
			$this->createMock(ObligationsServiceInterface::class),
			$access,
			$this->createMock(SeriesLatestInterface::class),
			$session,
			$this->createMock(LoggerInterface::class),
			new BillableShareService($analytics, new UnitMembership(), $access)
		);
	}//end controller()

	/**
	 * Signed out, a bad period, another team and the caller's own team.
	 *
	 * @return void
	 */
	public function testTheReadsAreGuarded(): void {
		self::assertSame(401, $this->controller(null, [])->billableShare()->getStatus());
		self::assertSame(400, $this->controller('boss', ['period' => 'october'])->billableShare()->getStatus());
		self::assertSame(403, $this->controller('boss', ['orgUnitId' => 'u-other', 'period' => '2026-10'])->billableSharePeople()->getStatus());

		$units = $this->controller('boss', ['period' => '2026-10'])->billableShare();
		self::assertSame(200, $units->getStatus());
		self::assertSame(['u-own'], array_column($units->getData()['units'], 'id'));

		$people = $this->controller('boss', ['orgUnitId' => 'u-own'])->billableSharePeople();
		self::assertSame(200, $people->getStatus());
		self::assertSame((new \DateTimeImmutable('first day of last month'))->format('Y-m'), $people->getData()['period'], 'The previous month by default.');
	}//end testTheReadsAreGuarded()
}//end class
