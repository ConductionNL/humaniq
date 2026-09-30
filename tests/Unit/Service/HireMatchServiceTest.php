<?php

/**
 * The duplicate check before a hire: same BSN, same last name and date of
 * birth, or same private e-mail, in that order.
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
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HireMatchService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use PHPUnit\Framework\TestCase;

/**
 * Ordered keys, one row per person, and never a match on name alone.
 */
class HireMatchServiceTest extends TestCase {

	/**
	 * The service over a fixed set of employees.
	 *
	 * @return HireMatchService
	 */
	private function service(): HireMatchService {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->with('Employee')->willReturn([
			['id' => 'emp-smit', 'firstName' => 'Pieter', 'lastName' => 'Smit', 'dateOfBirth' => '1985-03-03', 'startDate' => '2015-01-01', 'endDate' => '2023-06-30'],
			['id' => 'emp-vries-1970', 'firstName' => 'Jan', 'lastName' => 'de Vries', 'dateOfBirth' => '1970-05-01', 'startDate' => '2000-01-01'],
			['id' => 'emp-bsn', 'firstName' => 'Kim', 'lastName' => 'Bos', 'bsn' => '123456782', 'startDate' => '2020-01-01'],
			['id' => 'emp-mail', 'firstName' => 'Lotte', 'lastName' => 'Kok', 'privateEmail' => 'Lotte.Kok@Example.org', 'startDate' => '2021-01-01', 'endDate' => '2022-01-31'],
		]);

		return new HireMatchService($gateway);
	}//end service()

	/**
	 * A BSN match comes first and says it matched on the BSN.
	 *
	 * @return void
	 */
	public function testABsnMatch(): void {
		$matches = $this->service()->matches(['bsn' => '123456782', 'lastName' => 'Anders']);

		self::assertCount(1, $matches);
		self::assertSame('emp-bsn', $matches[0]['employeeId']);
		self::assertSame('bsn', $matches[0]['matchedOn']);
		self::assertFalse($matches[0]['hasLeft']);
	}//end testABsnMatch()

	/**
	 * A former employee is found on last name and date of birth, case and
	 * spacing ignored, and is marked as having left.
	 *
	 * @return void
	 */
	public function testALastNameAndBirthDateMatch(): void {
		$matches = $this->service()->matches(['lastName' => ' smit ', 'dateOfBirth' => '1985-03-03']);

		self::assertCount(1, $matches);
		self::assertSame('emp-smit', $matches[0]['employeeId']);
		self::assertSame('name-and-birth-date', $matches[0]['matchedOn']);
		self::assertTrue($matches[0]['hasLeft']);
		self::assertSame('2023-06-30', $matches[0]['endDate']);
		self::assertSame('Pieter Smit', $matches[0]['name']);
	}//end testALastNameAndBirthDateMatch()

	/**
	 * The private e-mail matches case-insensitively.
	 *
	 * @return void
	 */
	public function testAPrivateEmailMatch(): void {
		$matches = $this->service()->matches(['privateEmail' => 'lotte.kok@example.org']);

		self::assertCount(1, $matches);
		self::assertSame('emp-mail', $matches[0]['employeeId']);
		self::assertSame('private-email', $matches[0]['matchedOn']);
	}//end testAPrivateEmailMatch()

	/**
	 * Two people with the same name and different birth dates are not a match,
	 * and a name without a birth date matches nobody.
	 *
	 * @return void
	 */
	public function testTheSameNameWithAnotherBirthDateIsNoMatch(): void {
		self::assertSame([], $this->service()->matches(['lastName' => 'de Vries', 'dateOfBirth' => '1996-02-02']));
		self::assertSame([], $this->service()->matches(['lastName' => 'de Vries']));
	}//end testTheSameNameWithAnotherBirthDateIsNoMatch()

	/**
	 * A person matching on two keys is listed once, under the strongest key,
	 * and the BSN hit sorts before a weaker hit on someone else.
	 *
	 * @return void
	 */
	public function testKeysAreOrderedAndAPersonIsListedOnce(): void {
		$matches = $this->service()->matches(['bsn' => '123456782', 'lastName' => 'Bos', 'privateEmail' => 'lotte.kok@example.org']);

		self::assertSame(['emp-bsn', 'emp-mail'], array_column($matches, 'employeeId'));
		self::assertSame(['bsn', 'private-email'], array_column($matches, 'matchedOn'));
	}//end testKeysAreOrderedAndAPersonIsListedOnce()

}//end class
