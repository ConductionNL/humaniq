<?php

/**
 * HoursRegisterGateway unit tests
 *
 * The shared plumbing the hours-process listeners stand on: object load
 * (with id echo), filtered queries, the org-chain index shapes, the
 * account-link Employee lookup, and slug resolution — including the
 * unresolvable/'' degradations the listeners' no-op paths depend on.
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
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use PHPUnit\Framework\TestCase;

/**
 * Reads, writes, index shapes and degradations of the gateway.
 */
class HoursRegisterGatewayTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The subject.
	 *
	 * @var HoursRegisterGateway
	 */
	private HoursRegisterGateway $gateway;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		$this->gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
	}//end setUp()

	/**
	 * findObjectData returns the payload with the uuid echoed as id, and
	 * null for a missing object.
	 *
	 * @return void
	 */
	public function testFindObjectDataEchoesIdAndDegradesToNull(): void {
		$this->store->seed('Timesheet', 'ts-1', ['period' => '2026-05']);

		$data = $this->gateway->findObjectData('ts-1', 'Timesheet');
		$this->assertSame('2026-05', $data['period']);
		$this->assertSame('ts-1', $data['id']);

		$this->assertNull($this->gateway->findObjectData('ts-gone', 'Timesheet'));
	}//end testFindObjectDataEchoesIdAndDegradesToNull()

	/**
	 * findFiltered applies top-level equality and save() round-trips.
	 *
	 * @return void
	 */
	public function testFindFilteredAndSaveRoundTrip(): void {
		$this->gateway->save(['employeeId' => 'e1', 'period' => '2026-05'], 'Timesheet', 'ts-1');
		$this->gateway->save(['employeeId' => 'e1', 'period' => '2026-06'], 'Timesheet', 'ts-2');
		$this->gateway->save(['employeeId' => 'e2', 'period' => '2026-05'], 'Timesheet', 'ts-3');

		$matches = $this->gateway->findFiltered('Timesheet', ['employeeId' => 'e1', 'period' => '2026-05']);
		$this->assertCount(1, $matches);
		$this->assertSame('ts-1', $matches[0]['id']);
	}//end testFindFilteredAndSaveRoundTrip()

	/**
	 * The chain lookups resolve manager / cost centre through the SAME
	 * OrgResolutionService the audit uses, with the never-guessed
	 * unique-or-null posture; the account-link lookup finds the matching
	 * Employee (or null).
	 *
	 * @return void
	 */
	public function testChainLookupsAndAccountLinkLookup(): void {
		$this->store->seed('Employee', 'emp-1', ['nextcloudUserId' => 'admin']);
		$this->store->seed('Employee', 'emp-9', ['nextcloudUserId' => 'manager1']);
		$this->store->seed('OrgAssignment', 'as-1', ['employeeId' => 'emp-1', 'orgUnitId' => 'unit-1', 'endDate' => '']);
		$this->store->seed('OrgAssignment', 'as-2', ['employeeId' => 'emp-2', 'orgUnitId' => 'unit-2', 'endDate' => '']);
		$this->store->seed('OrgUnit', 'unit-1', ['managerId' => 'emp-9', 'costCenter' => 'CC-100']);

		$this->assertSame('manager1', $this->gateway->uniqueManagerUserIdFor('emp-1', '2026-08-21'));
		$this->assertSame('CC-100', $this->gateway->uniqueCostCenterFor('emp-1', '2026-08-21'));
		$this->assertNull($this->gateway->uniqueManagerUserIdFor('emp-2', '2026-08-21'), 'A dead-end chain stamps nothing.');
		$this->assertNull($this->gateway->uniqueCostCenterFor('emp-unknown', '2026-08-21'));

		$this->assertSame('emp-1', $this->gateway->findEmployeeByUserId('admin')['id']);
		$this->assertNull($this->gateway->findEmployeeByUserId('nobody'));
		$this->assertNull($this->gateway->findEmployeeByUserId(''));
	}//end testChainLookupsAndAccountLinkLookup()

	/**
	 * Slug resolution echoes the mapper's slug and degrades to '' for an
	 * empty id — the listeners' schema gates depend on both.
	 *
	 * @return void
	 */
	public function testSlugResolutionAndDegradation(): void {
		$this->assertSame('TimeEntry', $this->gateway->resolveSchemaSlug('TimeEntry'));
		$this->assertSame('', $this->gateway->resolveSchemaSlug(''));
	}//end testSlugResolutionAndDegradation()

	/**
	 * Build a gateway over an arbitrary ObjectService double, WITHOUT a
	 * SchemaMapper registration (its absence is one of the degradations
	 * under test).
	 *
	 * @param object $store The ObjectService double.
	 *
	 * @return HoursRegisterGateway The subject.
	 */
	private function gatewayWith(object $store): HoursRegisterGateway {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		return new HoursRegisterGateway(
			container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $store]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
	}//end gatewayWith()

	/**
	 * findObjectData degrades a THROWING find to null, and echoes the uuid
	 * ONLY when the payload does not already carry an id. (An entity whose
	 * payload is not an array cannot be produced through the real
	 * ObjectEntity — its getObject() always answers an array — so that
	 * defensive branch is deliberately not fabricated here.)
	 *
	 * @return void
	 */
	public function testFindObjectDataDegradationAndIdEcho(): void {
		$store = new class extends FakeObjectStore {

			/**
			 * {@inheritDoc}
			 */
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): ?\OCA\OpenRegister\Db\ObjectEntity {
				if ((string)$id === 'ts-throw') {
					throw new \RuntimeException('register down');
				}

				$entity = new \OCA\OpenRegister\Db\ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setSchema((string)$schema);
				$entity->setObject(['period' => '2026-05']);

				return $entity;
			}//end find()

		};
		$gateway = $this->gatewayWith($store);

		$this->assertNull($gateway->findObjectData('ts-throw', 'Timesheet'), 'A throwing find degrades to null.');

		$data = $gateway->findObjectData('ts-noid', 'Timesheet');
		$this->assertSame('ts-noid', $data['id'], 'The uuid is echoed into a payload that lacks an id.');
	}//end testFindObjectDataDegradationAndIdEcho()

	/**
	 * A missing SchemaMapper degrades slug resolution to '' — the listeners
	 * then treat the object as a foreign schema rather than crashing.
	 *
	 * @return void
	 */
	public function testMissingSchemaMapperDegradesSlugToEmpty(): void {
		$gateway = $this->gatewayWith(new FakeObjectStore());

		$this->assertSame('', $gateway->resolveSchemaSlug('Timesheet'));
	}//end testMissingSchemaMapperDegradesSlugToEmpty()

	/**
	 * findFiltered RE-CHECKS the filters in PHP: a store whose pushed-down
	 * filter grammar drifted (returns everything) is caught by the belt-and-
	 * braces re-check, and rows arriving as jsonSerializable objects or
	 * garbage are unwrapped/dropped by toArray().
	 *
	 * @return void
	 */
	public function testFindFilteredReChecksInPhpAndUnwrapsObjectRows(): void {
		$store = new class extends FakeObjectStore {

			/**
			 * {@inheritDoc}
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				// A drifted filter grammar: the filters are IGNORED.
				return [
					['id' => 'ts-1', 'employeeId' => 'e1', 'period' => '2026-05'],
					['id' => 'ts-2', 'employeeId' => 'e2', 'period' => '2026-05'],
					new class implements \JsonSerializable {

						/**
						 * @return array<string, mixed>
						 */
						public function jsonSerialize(): array {
							return ['id' => 'ts-3', 'employeeId' => 'e1', 'period' => '2026-05'];
						}//end jsonSerialize()

					},
					17,
				];
			}//end findAll()

		};
		$gateway = $this->gatewayWith($store);

		$matches = $gateway->findFiltered('Timesheet', ['employeeId' => 'e1']);

		$ids = array_map(static fn (array $row): string => (string)$row['id'], $matches);
		$this->assertSame(['ts-1', 'ts-3'], $ids, 'e2 is dropped by the PHP re-check; the object row is unwrapped; the garbage row is dropped.');
	}//end testFindFilteredReChecksInPhpAndUnwrapsObjectRows()

}//end class
