<?php

/**
 * TimesheetWeekController unit tests
 *
 * The week view is composed with system reads, so the controller first
 * requires the caller's own access rules to return the timesheet.
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
 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-leave-and-sickness-shall-show-on-the-weekly-timesheet-from-their-own-records-req-tac-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\TimesheetWeekController;
use OCA\Humaniq\Service\AbsenceDays;
use OCA\Humaniq\Service\AbsenceProgression;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\TimeActivityCatalog;
use OCA\Humaniq\Service\TimesheetWeekService;
use OCA\Humaniq\Service\WorkingHoursService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Access and outcomes of the week endpoint.
 */
class TimesheetWeekControllerTest extends TestCase {

	/**
	 * The register reads.
	 *
	 * @var RecordStoreInterface&MockObject
	 */
	private RecordStoreInterface&MockObject $store;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->createMock(RecordStoreInterface::class);
		$this->store->method('findObjectData')->willReturnCallback(
			static fn (string $uuid, string $schema): ?array => ($uuid === 'ts-1' && $schema === 'Timesheet')
				? ['id' => 'ts-1', 'employeeId' => 'emp-1', 'period' => '2026-W45']
				: null
		);
	}//end setUp()

	/**
	 * The controller, signed in as the given user or as nobody.
	 *
	 * @param string|null $uid The caller.
	 *
	 * @return TimesheetWeekController
	 */
	private function controller(?string $uid): TimesheetWeekController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new TimesheetWeekController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			store: $this->store,
			weeks: new TimesheetWeekService(
				store: $this->store,
				activities: new TimeActivityCatalog($this->store),
				absenceDays: new AbsenceDays(new WorkingHoursService(), new AbsenceProgression())
			),
			logger: new NullLogger()
		);
	}//end controller()

	/**
	 * A caller whose access rules return the timesheet gets its view.
	 *
	 * @return void
	 */
	public function testACallerWhoMayReadTheTimesheetGetsItsWeek(): void {
		$this->store->method('findFiltered')->willReturnCallback(
			static function (string $schema, array $filters, bool $asCaller = false): array {
				if ($schema === 'Timesheet') {
					self::assertTrue($asCaller, 'the access read runs as the caller');
					self::assertSame(['employeeId' => 'emp-1', 'period' => '2026-W45'], $filters);

					return [['id' => 'ts-other'], ['@self' => ['id' => 'ts-1']]];
				}

				return ($schema === 'TimeEntry') ? [['timesheetId' => 'ts-1', 'date' => '2026-11-03', 'hours' => 3, 'projectId' => 'P-1']] : [];
			}
		);

		$response = $this->controller('jansen')->week('ts-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('2026-W45', $response->getData()['period']);
		self::assertSame(3.0, $response->getData()['totals']['projectHours']);
	}//end testACallerWhoMayReadTheTimesheetGetsItsWeek()

	/**
	 * A timesheet the caller may not read answers as if it did not exist.
	 *
	 * @return void
	 */
	public function testATimesheetTheCallerMayNotReadIsNotFound(): void {
		$this->store->method('findFiltered')->willReturnCallback(
			static fn (string $schema): array => ($schema === 'Timesheet') ? [['id' => 'ts-other']] : [['timesheetId' => 'ts-1', 'hours' => 8]]
		);

		$response = $this->controller('buurman')->week('ts-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertArrayNotHasKey('lines', $response->getData());
	}//end testATimesheetTheCallerMayNotReadIsNotFound()

	/**
	 * A timesheet that does not exist is not found.
	 *
	 * @return void
	 */
	public function testAMissingTimesheetIsNotFound(): void {
		$this->store->expects(self::never())->method('findFiltered');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller('jansen')->week('ts-gone')->getStatus());
	}//end testAMissingTimesheetIsNotFound()

	/**
	 * No session, no id, and a failed read each answer without data.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailures(): void {
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->week('ts-1')->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller('jansen')->week('  ')->getStatus());

		$this->store->method('findFiltered')->willThrowException(new \RuntimeException('register down'));
		$response = $this->controller('jansen')->week('ts-1');
		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'De urenstaat kon niet worden gelezen.'], $response->getData());
	}//end testRefusalsAndFailures()

}//end class
