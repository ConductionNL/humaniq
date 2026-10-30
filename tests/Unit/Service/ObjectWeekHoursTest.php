<?php
/**
 * ObjectWeekHours tests
 *
 * The hours booked on one object in one week, per person: only the entries
 * the store hands the caller are counted, an entry outside the week or
 * without hours adds nothing, and each person carries a name and, when the
 * organisation resolves one, a team.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/hours-leaf/spec.md#requirement-the-hours-surface-shows-the-week-per-person
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\ObjectWeekHours;
use OCA\Humaniq\Service\OrgResolutionService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Humaniq\Service\ObjectWeekHours
 */
class ObjectWeekHoursTest extends TestCase {

	/**
	 * The subject over the given visible entries.
	 *
	 * @param array<int, array<string, mixed>> $visible The entries the caller may see.
	 *
	 * @return ObjectWeekHours The subject.
	 */
	private function weekHours(array $visible): ObjectWeekHours {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('findFiltered')->willReturnCallback(
			function (string $schema, array $filters, bool $asCaller = false) use ($visible): array {
				if ($schema === 'TimeEntry') {
					// The entries are read as the caller, never the whole register.
					$this->assertTrue($asCaller);
					$this->assertSame(['domainObjectType' => 'dossiq:case', 'domainObjectRef' => 'case-1'], $filters);
					return $visible;
				}

				return [
					'e-pieter' => [['employeeId' => 'e-pieter', 'orgUnitId' => 'u-woo', 'startDate' => '2025-01-01']],
					'e-lars' => [
						['employeeId' => 'e-lars', 'orgUnitId' => 'u-mob', 'startDate' => '2024-01-01'],
						['employeeId' => 'e-lars', 'orgUnitId' => 'u-woo', 'startDate' => '2024-01-01', 'endDate' => '2025-06-30'],
					],
				][$filters['employeeId']] ?? [];
			}
		);
		$gateway->method('loadAll')->with('OrgUnit')->willReturn([
			['id' => 'u-woo', 'name' => 'Woo en informatie'],
			['id' => 'u-mob', 'name' => 'Mobiliteit en verkeer'],
		]);
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(
			static fn (string $uid): ?string => ['pieter' => 'Pieter Jansen', 'lars' => 'Lars de Koning'][$uid] ?? null
		);

		return new ObjectWeekHours(gateway: $gateway, users: $users, orgResolution: new OrgResolutionService());
	}//end weekHours()

	/**
	 * One entry on the object.
	 *
	 * @param string $uid The person.
	 * @param string $date The day.
	 * @param mixed $hours The hours.
	 * @param string $employeeId The employee.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry(string $uid, string $date, mixed $hours, string $employeeId = ''): array {
		return [
			'userId' => $uid,
			'employeeId' => $employeeId,
			'date' => $date,
			'hours' => $hours,
			'domainObjectType' => 'dossiq:case',
			'domainObjectRef' => 'case-1',
		];
	}//end entry()

	/**
	 * Week 41 of 2026: Pieter 6.5 over two days, Lars 3, Marloes 2; the rest outside the week or without hours.
	 *
	 * @return void
	 */
	public function testTheWeekIsSummedPerPersonMostHoursFirst(): void {
		$week = $this->weekHours([
			$this->entry('pieter', '2026-10-05', 4, 'e-pieter'),
			$this->entry('lars', '2026-10-07', '3', 'e-lars'),
			$this->entry('pieter', '2026-10-09', 2.5, 'e-pieter'),
			$this->entry('marloes', '2026-10-11', 2),
			$this->entry('pieter', '2026-10-12', 8, 'e-pieter'),
			$this->entry('lars', '2026-10-04', 5, 'e-lars'),
			$this->entry('lars', '2026-10-08', null, 'e-lars'),
		])->forObject(domainObjectType: 'dossiq:case', domainObjectRef: 'case-1', day: new DateTimeImmutable('2026-10-08'));

		self::assertSame(41, $week['week']);
		self::assertSame('2026-10-05', $week['weekStart']);
		self::assertSame('2026-10-11', $week['weekEnd']);
		self::assertSame(11.5, $week['total']);
		self::assertSame(
			[
				['userId' => 'pieter', 'displayName' => 'Pieter Jansen', 'team' => 'Woo en informatie', 'hours' => 6.5],
				['userId' => 'lars', 'displayName' => 'Lars de Koning', 'team' => 'Mobiliteit en verkeer', 'hours' => 3.0],
				['userId' => 'marloes', 'displayName' => 'marloes', 'team' => '', 'hours' => 2.0],
			],
			$week['people']
		);
	}//end testTheWeekIsSummedPerPersonMostHoursFirst()

	/**
	 * Nothing visible in the week: no people and a total of zero, read from the store and not assumed.
	 *
	 * @return void
	 */
	public function testAWeekWithoutVisibleEntriesHasNoPeople(): void {
		$week = $this->weekHours([])->forObject(domainObjectType: 'dossiq:case', domainObjectRef: 'case-1', day: new DateTimeImmutable('2026-10-08'));

		self::assertSame(0.0, $week['total']);
		self::assertSame([], $week['people']);
	}//end testAWeekWithoutVisibleEntriesHasNoPeople()
}//end class
