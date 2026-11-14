<?php

/**
 * Unit tests for SetupAdministrations (setup-wizard-six-steps, REQ-FTS-008 and REQ-FTS-009).
 *
 * The ObjectService is a small in-memory fake: it keeps what is saved and
 * answers findAll with it, so the tests show what the wizard leaves behind.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#requirement-the-administraties-step-shall-create-the-administrations-humaniq-starts-with-req-fts-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service\Setup;

use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\Setup\SetupAdministrations;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The Administraties and Werkwijze steps, server side.
 */
class SetupAdministrationsTest extends TestCase {

	/**
	 * The in-memory ObjectService.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The app config values written.
	 *
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * The active administration set per user.
	 *
	 * @var array<string,string>
	 */
	private array $active = [];


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = new class {

			/**
			 * Stored rows per schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $rows = ['hrAdministration' => [], 'AdministrationAccess' => []];

			/**
			 * Every patch: [id, data].
			 *
			 * @var array<int,array<int,mixed>>
			 */
			public array $patches = [];

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param string $register Ignored.
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string,mixed> $config Ignored.
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config = []): array {
				return $this->rows[$this->schema] ?? [];
			}

			/**
			 * @param array<string,mixed> $object   The object.
			 * @param mixed               $register The register.
			 * @param mixed               $schema   The schema.
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null): array {
				$row = $object + ['@self' => ['id' => 'obj-' . (count($this->rows[$schema]) + 1) . '-' . $schema]];
				$this->rows[$schema][] = $row;
				return $row;
			}

			/**
			 * @param string              $objectId The id.
			 * @param array<string,mixed> $data     The patch.
			 * @param mixed               $register The register.
			 * @param mixed               $schema   The schema.
			 * @return array<string,mixed>
			 */
			public function patchObject(string $objectId, array $data, mixed $register = null, mixed $schema = null): array {
				$this->patches[] = [$objectId, $data];
				return $data;
			}
		};
	}//end setUp()


	/**
	 * The service over the fakes.
	 *
	 * @return SetupAdministrations
	 */
	private function service(): SetupAdministrations {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objects);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$administrations = $this->createMock(AdministrationService::class);
		$administrations->method('setActiveAdministrationId')->willReturnCallback(
			function (string $userId, string $administrationId): void {
				$this->active[$userId] = $administrationId;
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));

		return new SetupAdministrations($container, $settings, $administrations, $config);
	}//end service()


	/**
	 * The two administrations of the scenario.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function two(): array {
		return [
			['name' => 'Gemeente Zuiddrecht', 'loonheffingennummer' => '123456789L01', 'kvkNumber' => '12345678'],
			['name' => ' Werkbedrijf Drechtsteden BV ', 'loonheffingennummer' => '987654321L02'],
		];
	}//end two()


	/**
	 * Two administrations are created side by side, the administrator gets hr on both, and the first is active.
	 *
	 * @return void
	 */
	public function testTwoAdministrationsAreCreatedSideBySide(): void {
		$keys = $this->service()->create(self::two(), 'admin');

		$this->assertSame(['ADM-123456789L01', 'ADM-987654321L02'], $keys);
		$this->assertSame(['Gemeente Zuiddrecht', 'Werkbedrijf Drechtsteden BV'], array_column($this->objects->rows['hrAdministration'], 'name'));
		$this->assertSame(['12345678', null], array_column($this->objects->rows['hrAdministration'], 'kvkNumber'));
		$this->assertSame(['standard', 'standard'], array_column($this->objects->rows['hrAdministration'], 'mode'));
		$this->assertSame(
			[
				['userId' => 'admin', 'administrationId' => 'ADM-123456789L01', 'role' => 'hr'],
				['userId' => 'admin', 'administrationId' => 'ADM-987654321L02', 'role' => 'hr'],
			],
			array_map(static fn (array $row): array => array_diff_key($row, ['@self' => true]), $this->objects->rows['AdministrationAccess'])
		);
		$this->assertSame(['admin' => 'ADM-123456789L01'], $this->active);
		$this->assertSame('created', $this->config['administrations_decided']);
		$this->assertSame(['obj-1-hrAdministration', 'obj-2-hrAdministration'], json_decode($this->config['setup_administration_object_ids'], true));
	}//end testTwoAdministrationsAreCreatedSideBySide()


	/**
	 * Running the step twice creates no duplicate administration and no duplicate grant.
	 *
	 * @return void
	 */
	public function testRunningTheStepTwiceCreatesNoDuplicates(): void {
		$this->service()->create(self::two(), 'admin');
		$keys = $this->service()->create([['name' => 'Renamed', 'loonheffingennummer' => '123456789l01']], 'admin');

		$this->assertSame(['ADM-123456789L01'], $keys);
		$this->assertCount(2, $this->objects->rows['hrAdministration']);
		$this->assertCount(2, $this->objects->rows['AdministrationAccess']);
		$this->assertSame(['obj-1-hrAdministration'], json_decode($this->config['setup_administration_object_ids'], true));
	}//end testRunningTheStepTwiceCreatesNoDuplicates()


	/**
	 * Another administrator reusing an administration gets their own hr grant.
	 *
	 * @return void
	 */
	public function testAnotherAdministratorGetsTheirOwnGrant(): void {
		$this->service()->create(self::two(), 'admin');
		$this->service()->create([self::two()[0]], 'ops');

		$this->assertSame(['admin', 'admin', 'ops'], array_column($this->objects->rows['AdministrationAccess'], 'userId'));
		$this->assertSame('ADM-123456789L01', $this->active['ops']);
	}//end testAnotherAdministratorGetsTheirOwnGrant()


	/**
	 * An entry without a name or loonheffingennummer refuses the whole list.
	 *
	 * @return void
	 */
	public function testABadEntryRefusesTheList(): void {
		$this->assertNull(SetupAdministrations::refusal(self::two()));
		$this->assertSame('Administration 2 has no loonheffingennummer.', SetupAdministrations::refusal([self::two()[0], ['name' => 'X', 'loonheffingennummer' => '  ']]));
		$this->assertSame('Administration 1 has no name.', SetupAdministrations::refusal([['loonheffingennummer' => '1']]));
		$this->assertSame('Administration 1 is not a set of fields.', SetupAdministrations::refusal(['x']));
		$this->assertSame('Enter at least one administration.', SetupAdministrations::refusal([]));
		$this->assertSame('Enter at least one administration.', SetupAdministrations::refusal('x'));
	}//end testABadEntryRefusesTheList()


	/**
	 * The working mode is written onto every administration the step created or reused.
	 *
	 * @return void
	 */
	public function testTheModeIsWrittenOnTheWizardsAdministrations(): void {
		$this->service()->create(self::two(), 'admin');

		$this->assertSame(2, $this->service()->applyMode('dga_single_person'));
		$this->assertSame(
			[['obj-1-hrAdministration', ['mode' => 'dga_single_person']], ['obj-2-hrAdministration', ['mode' => 'dga_single_person']]],
			$this->objects->patches
		);
	}//end testTheModeIsWrittenOnTheWizardsAdministrations()


	/**
	 * Without administrations from the wizard a mode writes nothing; an unknown mode is refused.
	 *
	 * @return void
	 */
	public function testAModeWithoutAdministrationsWritesNothing(): void {
		$this->assertSame(0, $this->service()->applyMode('standard'));
		$this->assertSame([], $this->objects->patches);

		$this->expectException(RuntimeException::class);
		$this->service()->applyMode('franchise');
	}//end testAModeWithoutAdministrationsWritesNothing()


	/**
	 * Skipping is an answer that creates nothing.
	 *
	 * @return void
	 */
	public function testSkippingIsRecordedAndCreatesNothing(): void {
		$this->service()->skip();

		$this->assertSame('skipped', $this->config['administrations_decided']);
		$this->assertSame([], $this->objects->rows['hrAdministration']);
	}//end testSkippingIsRecordedAndCreatesNothing()
}//end class
