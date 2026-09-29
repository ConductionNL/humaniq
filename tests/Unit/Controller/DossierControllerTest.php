<?php

/**
 * Unit tests for DossierController (people-dossier-completeness REQ-DCP-002).
 *
 * The controller runs with the real DossierCompletenessService; the register
 * reads are doubles answering rows shaped like the register's schemas.
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
 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\DossierController;
use OCA\Humaniq\Service\AbsenceProgression;
use OCA\Humaniq\Service\DossierCompletenessService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the dossier endpoints.
 *
 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-002
 */
class DossierControllerTest extends TestCase {

	/**
	 * The rows in the register, per schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Three employees: emp-1 in the manager's unit with an expired VOG, emp-2
	 * outside it with nothing, emp-3 complete.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rows = [
			'Employee' => [
				['id' => 'emp-1', 'firstName' => 'Sanne', 'lastName' => 'de Vries', 'employeeNumber' => 'EMP-0002', 'startDate' => '2025-03-01'],
				['id' => 'emp-2', 'firstName' => 'Bram', 'lastName' => 'Koster', 'employeeNumber' => 'EMP-0009', 'startDate' => '2024-01-01'],
				['id' => 'emp-3', 'firstName' => 'Sam', 'lastName' => 'Jansen', 'employeeNumber' => 'EMP-0001', 'startDate' => '2024-01-01'],
			],
			'DossierRequirement' => [
				['id' => 'r-vog', 'code' => 'vog', 'label' => 'Certificate of conduct', 'scope' => 'all', 'evidenceKind' => 'personnel-document', 'expires' => true, 'active' => true],
			],
			'PersonnelDocument' => [
				['id' => 'doc-1', 'employeeId' => 'emp-1', 'requirementCode' => 'vog', 'issuedOn' => '2023-01-01', 'validUntil' => '2026-08-01'],
				['id' => 'doc-3', 'employeeId' => 'emp-3', 'requirementCode' => 'vog', 'issuedOn' => '2023-12-01', 'validUntil' => '2030-01-01'],
			],
		];
	}//end setUp()

	/**
	 * An unreadable employee answers 404 and nothing is read for them.
	 *
	 * @return void
	 */
	public function testAnUnreadableEmployeeIsNotFound(): void {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->expects($this->never())->method('findFiltered');

		$response = $this->controller(readable: [], gateway: $gateway)->status('emp-2');

		$this->assertSame(404, $response->getStatus());
		$this->assertArrayNotHasKey('requirements', $response->getData());
	}//end testAnUnreadableEmployeeIsNotFound()

	/**
	 * An expired VOG shows as verlopen with its evidence and a label.
	 *
	 * @return void
	 */
	public function testTheStatusOfOneFile(): void {
		$data = $this->controller(readable: ['emp-1', 'doc-1'])->status('emp-1', '2026-09-29')->getData();

		$this->assertFalse($data['complete']);
		$this->assertSame('verlopen', $data['requirements'][0]['status']);
		$this->assertSame('Expired', $data['requirements'][0]['statusLabel']);
		$this->assertSame(['schema' => 'PersonnelDocument', 'id' => 'doc-1'], $data['requirements'][0]['evidence']);
	}//end testTheStatusOfOneFile()

	/**
	 * A document the caller may not read is not evidence in the answer.
	 *
	 * @return void
	 */
	public function testAnUnreadableDocumentIsNotShown(): void {
		$data = $this->controller(readable: ['emp-3'])->status('emp-3', '2026-09-29')->getData();

		$this->assertSame('ontbreekt', $data['requirements'][0]['status']);
		$this->assertNull($data['requirements'][0]['evidence']);
	}//end testAnUnreadableDocumentIsNotShown()

	/**
	 * Incomplete files lists only readable employees with a gap: a manager sees only their own people.
	 *
	 * @return void
	 */
	public function testIncompleteListsOnlyReadableEmployeesWithAGap(): void {
		$data = $this->controller(readable: ['emp-1', 'emp-3'])->incomplete('2026-09-29')->getData();

		$this->assertSame(['emp-1'], array_column($data['employees'], 'id'));
		$this->assertSame('Sanne de Vries', $data['employees'][0]['name']);
		$this->assertSame('Certificate of conduct: Expired', $data['employees'][0]['gaps']);
		$this->assertSame(1, $data['employees'][0]['gapCount']);
	}//end testIncompleteListsOnlyReadableEmployeesWithAGap()

	/**
	 * A date that is not a date is refused.
	 *
	 * @return void
	 */
	public function testABadDateIsRefused(): void {
		$this->assertSame(400, $this->controller(readable: ['emp-1'])->incomplete('gisteren')->getStatus());
	}//end testABadDateIsRefused()

	/**
	 * The controller over these rows, with only the named ids readable to the caller.
	 *
	 * @param array<int, string> $readable The ids the caller may read.
	 * @param HoursRegisterGateway|null $gateway A gateway double to use instead.
	 *
	 * @return DossierController
	 */
	private function controller(array $readable, ?HoursRegisterGateway $gateway = null): DossierController {
		$rows = $this->rows;
		if ($gateway === null) {
			$gateway = $this->createMock(HoursRegisterGateway::class);
			$gateway->method('loadAll')->willReturnCallback(static fn (string $schema): array => ($rows[$schema] ?? []));
			$gateway->method('findFiltered')->willReturnCallback(
				static fn (string $schema, array $filters): array => array_values(
					array_filter(($rows[$schema] ?? []), static fn (array $row): bool => ($row['employeeId'] ?? null) === ($filters['employeeId'] ?? null))
				)
			);
			$gateway->method('findObjectData')->willReturnCallback(
				static function (string $uuid, string $schema) use ($rows): ?array {
					foreach (($rows[$schema] ?? []) as $row) {
						if ($row['id'] === $uuid) {
							return $row;
						}
					}

					return null;
				}
			);
		}

		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturnCallback(
			static fn (string $id, string $schema): ?array => in_array($id, $readable, true) ? ['id' => $id] : null
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new DossierController(
			$this->createMock(IRequest::class),
			$gateway,
			new DossierCompletenessService(new AbsenceProgression()),
			$rbac,
			$l10n
		);
	}//end controller()

}//end class
