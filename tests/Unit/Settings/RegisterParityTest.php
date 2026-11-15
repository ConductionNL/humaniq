<?php

/**
 * Register parity: the mock register declares nothing the shipped one lacks
 *
 * Loading demo data imports humaniq_mock_register.json. A TimeEntry property
 * declared only there works on a demo install and is missing on every real
 * one, which is how domainObjectType and domainObjectRef once reached the
 * timesheet tests without reaching the shipped register.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Settings
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
 * @spec openspec/changes/time-entry-domain-object-ref/specs/time-entry-capture/spec.md#requirement-the-mock-register-cannot-declare-what-the-shipped-one-lacks-req-tec-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Service\RegisterConfigReader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Compares the mock and the shipped TimeEntry property sets.
 */
#[CoversNothing]
class RegisterParityTest extends TestCase {

	/**
	 * The properties the mock register declares on a schema.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return list<string>
	 */
	private static function mockProperties(string $schema): array {
		$mock = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/humaniq_mock_register.json'), true);

		return array_keys(($mock['components']['schemas'][$schema]['properties'] ?? []));
	}//end mockProperties()

	/**
	 * The properties the shipped register declares on a schema, fragments merged.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return list<string>
	 */
	private static function shippedProperties(string $schema): array {
		$real = ((new RegisterConfigReader(new NullLogger()))->read()['data'] ?? []);

		return array_keys(($real['components']['schemas'][$schema]['properties'] ?? []));
	}//end shippedProperties()

	/**
	 * Every TimeEntry property of the mock register exists in the shipped register.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-entry-domain-object-ref/specs/time-entry-capture/spec.md#requirement-the-mock-register-cannot-declare-what-the-shipped-one-lacks-req-tec-008
	 */
	public function testTheMockTimeEntryDeclaresNothingTheShippedOneLacks(): void {
		$mock = self::mockProperties(schema: 'TimeEntry');
		self::assertNotEmpty($mock, 'the mock register declares no TimeEntry');

		$missing = array_values(array_diff($mock, self::shippedProperties(schema: 'TimeEntry')));

		self::assertSame([], $missing, 'TimeEntry properties only the mock register declares: ' . implode(', ', $missing));
	}//end testTheMockTimeEntryDeclaresNothingTheShippedOneLacks()

	/**
	 * The shipped TimeEntry carries the host object pair the hours leaf writes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-entry-domain-object-ref/specs/time-entry-capture/spec.md#requirement-timeentry-declares-the-host-object-it-was-booked-on-req-tec-006
	 */
	public function testTheShippedTimeEntryDeclaresTheHostObjectPair(): void {
		$shipped = self::shippedProperties(schema: 'TimeEntry');

		self::assertContains('domainObjectType', $shipped);
		self::assertContains('domainObjectRef', $shipped);
	}//end testTheShippedTimeEntryDeclaresTheHostObjectPair()
}//end class
