<?php

/**
 * Unit tests for LearningController.
 *
 * The people feed only returns employees the caller may read under their own
 * OpenRegister RBAC, and refuses a `modifiedSince` it cannot read.
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
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use DateTime;
use OCA\Humaniq\Controller\LearningController;
use OCA\Humaniq\Service\LearningPeopleFeed;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The feed endpoint and its RBAC filter.
 */
class LearningControllerTest extends TestCase {

	/**
	 * A controller whose caller may read only the given employees.
	 *
	 * @param list<string>              $readable The employee ids the caller may read.
	 * @param LearningPeopleFeed|null   $feed     The feed, or a stub with two rows.
	 *
	 * @return LearningController
	 */
	private function controller(array $readable, ?LearningPeopleFeed $feed=null): LearningController {
		if ($feed === null) {
			$feed = $this->createMock(LearningPeopleFeed::class);
			$feed->method('people')->willReturn(
				[
					['id' => 'emp-own-team', 'firstName' => 'Anna'],
					['id' => 'emp-elsewhere', 'firstName' => 'Bram'],
				]
			);
		}

		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturnCallback(
			static fn (string $id, string $schema): ?array => ($schema === 'Employee' && in_array($id, $readable, true) === true) ? ['id' => $id] : null
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-09-30'));

		return new LearningController(
			request: $this->createMock(IRequest::class),
			feed: $feed,
			rbac: $rbac,
			time: $time
		);
	}//end controller()

	/**
	 * A caller who may not read an employee does not receive them.
	 *
	 * @return void
	 */
	public function testACallerOnlyReceivesEmployeesTheyMayRead(): void {
		$response = $this->controller(['emp-own-team'])->people();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([['id' => 'emp-own-team', 'firstName' => 'Anna']], $response->getData()['people']);
	}//end testACallerOnlyReceivesEmployeesTheyMayRead()

	/**
	 * `modifiedSince` and today reach the feed.
	 *
	 * @return void
	 */
	public function testModifiedSinceAndTodayReachTheFeed(): void {
		$feed = $this->createMock(LearningPeopleFeed::class);
		$feed->expects(self::once())->method('people')->with('2026-09-30', '2026-09-28T00:00:00+00:00')->willReturn([]);

		$response = $this->controller([], $feed)->people('2026-09-28T00:00:00+00:00');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame([], $response->getData()['people']);
	}//end testModifiedSinceAndTodayReachTheFeed()

	/**
	 * An unreadable `modifiedSince` is refused.
	 *
	 * @return void
	 */
	public function testAnUnreadableModifiedSinceIsRefused(): void {
		$response = $this->controller(['emp-own-team'])->people('last tuesday-ish');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAnUnreadableModifiedSinceIsRefused()

	/**
	 * A `+` offset that arrived as a space is still read.
	 *
	 * @return void
	 */
	public function testAnUnencodedOffsetIsStillRead(): void {
		$feed = $this->createMock(LearningPeopleFeed::class);
		$feed->expects(self::once())->method('people')->with('2026-09-30', '2026-09-28T00:00:00+02:00')->willReturn([]);

		self::assertSame(Http::STATUS_OK, $this->controller([], $feed)->people('2026-09-28T00:00:00 02:00')->getStatus());
	}//end testAnUnencodedOffsetIsStillRead()

}//end class
