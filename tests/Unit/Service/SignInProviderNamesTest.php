<?php

/**
 * SignInProviderNames unit tests
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
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\SignInProviderNames;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reading provider names from the sign-in apps' tables.
 *
 * @covers \OCA\Humaniq\Service\SignInProviderNames
 */
class SignInProviderNamesTest extends TestCase {

	/**
	 * A reader whose query returns the rows, recording the SQL asked.
	 *
	 * @param list<array<string, mixed>> $rows  The rows.
	 * @param array<int, string>         $asked Filled with the SQL.
	 *
	 * @return SignInProviderNames
	 */
	private function names(array $rows, array &$asked = []): SignInProviderNames {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql) use ($result, &$asked): IResult {
				$asked[] = $sql;
				return $result;
			}
		);

		return new SignInProviderNames($db, $this->createMock(LoggerInterface::class));
	}//end names()

	/**
	 * A SAML provider's name is the display name inside its JSON configuration.
	 *
	 * @return void
	 */
	public function testSamlNamesComeFromTheConfiguration(): void {
		$asked = [];
		$names = $this->names(
			[
				['configuration' => json_encode(['general-idp0_display_name' => ' Gemeente Zuiddrecht '])],
				['configuration' => json_encode(['general-uid_mapping' => 'uid'])],
				['configuration' => '{not json'],
				['configuration' => json_encode(['general-idp0_display_name' => 'Gemeente Zuiddrecht'])],
			],
			$asked
		)->namesFor('user_saml');

		self::assertSame(['Gemeente Zuiddrecht'], $names);
		self::assertSame(['SELECT configuration FROM *PREFIX*user_saml_configurations'], $asked);
	}//end testSamlNamesComeFromTheConfiguration()

	/**
	 * An OpenID Connect provider's name is its identifier.
	 *
	 * @return void
	 */
	public function testOidcNamesAreTheIdentifiers(): void {
		$asked = [];
		$names = $this->names([['identifier' => ' Keycloak '], ['identifier' => ''], ['identifier' => 'Keycloak'], ['identifier' => null], ['identifier' => 'Entra']], $asked)->namesFor('user_oidc');

		self::assertSame(['Keycloak', 'Entra'], $names);
		self::assertSame(['SELECT identifier FROM *PREFIX*user_oidc_providers'], $asked);
	}//end testOidcNamesAreTheIdentifiers()

	/**
	 * A table that cannot be read, or an app that is not a sign-in app, gives no names.
	 *
	 * @return void
	 */
	public function testAnUnreadableTableGivesNoNames(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willThrowException(new RuntimeException('no such table'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info');

		self::assertSame([], (new SignInProviderNames($db, $logger))->namesFor('user_saml'));
		self::assertSame([], $this->names([['identifier' => 'x']])->namesFor('files'));
	}//end testAnUnreadableTableGivesNoNames()

}//end class
