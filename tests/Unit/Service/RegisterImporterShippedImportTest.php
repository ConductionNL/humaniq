<?php

/**
 * RegisterImporter shipped-import marker test
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
 * @spec openspec/changes/approved-claim-keeps-its-amounts/specs/humaniq-expenses/spec.md#Requirement:-An-approved-expense-claim-SHALL-keep-the-amounts-it-was-approved-for
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OpenRegisterAvailability;
use OCA\Humaniq\Service\RegisterImporter;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The register import runs as a shipped import.
 *
 * @covers \OCA\Humaniq\Service\RegisterImporter
 * @uses   \OCA\Humaniq\Service\OpenRegisterAvailability
 * @uses   \OCA\Humaniq\Service\InternalWriteMarker
 * @uses   \OCA\Humaniq\Service\RegisterConfigReader
 */
class RegisterImporterShippedImportTest extends TestCase {

	/**
	 * importFromApp runs inside the shipped-import marker, and only there.
	 *
	 * @return void
	 */
	public function testTheRegisterImportIsAShippedImport(): void {
		$marker = new InternalWriteMarker();
		$importer = new class($marker) {
			/**
			 * Whether each call ran inside the shipped import.
			 *
			 * @var list<bool>
			 */
			public array $seen = [];

			/**
			 * @param InternalWriteMarker $marker The marker.
			 */
			public function __construct(private InternalWriteMarker $marker) {
			}

			/**
			 * @param string $appId The app id.
			 * @param array<mixed> $data The register config.
			 * @param string $version The version.
			 * @param bool $force Whether the import is forced.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->seen[] = $this->marker->isShippedImport();

				return ['registers' => [1], 'schemas' => [1]];
			}
		};
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($importer);

		$service = new RegisterImporter(new OpenRegisterAvailability($appManager), $container, new NullLogger(), $marker);
		$service->loadConfigurationForced();

		self::assertSame([true], $importer->seen);
		self::assertFalse($marker->isShippedImport());
	}//end testTheRegisterImportIsAShippedImport()

}//end class
