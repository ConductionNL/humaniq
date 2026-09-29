<?php

/**
 * Humaniq DossierController
 *
 * The completeness of personnel files (people-dossier-completeness D3):
 * one employee's file per requirement, and the list of files with a gap.
 *
 * Both answers include only what the caller may read: an employee the caller
 * cannot read answers 404 (and is never listed), and on one file a document
 * the caller cannot read is not shown as evidence. The list names only the
 * gap per requirement, never the documents themselves.
 *
 * @category Controller
 * @package  OCA\Humaniq\Controller
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
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use DateTimeImmutable;
use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\DossierCompletenessService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Serves personnel-file completeness.
 *
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-002
 */
class DossierController extends Controller {

	/**
	 * The schemas whose rows are shown as evidence, and so are read per row.
	 */
	private const EVIDENCE_SCHEMAS = ['PersonnelDocument', 'HrGeneratedDocument', 'EmployeeCompetence'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param HoursRegisterGateway $gateway Register reads.
	 * @param DossierCompletenessService $completeness The completeness rule.
	 * @param RbacObjectReader $rbac What the caller may read.
	 * @param IL10N $l10n Translations for the status labels.
	 */
	public function __construct(
		IRequest $request,
		private readonly HoursRegisterGateway $gateway,
		private readonly DossierCompletenessService $completeness,
		private readonly RbacObjectReader $rbac,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/employees/{id}/dossier-status: one file, per requirement.
	 *
	 * @param string $id The employee.
	 * @param string|null $date The day to judge on (default today).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-002
	 */
	#[NoAdminRequired]
	public function status(string $id, ?string $date = null): JSONResponse {
		$employee = $this->rbac->findOrNull(id: $id, schema: 'Employee');
		if ($employee === null) {
			return new JSONResponse(['error' => 'Medewerker niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$day = $this->day(date: $date);
		if ($day === null) {
			return $this->badDate();
		}

		$rows = [
			'employee' => ($this->gateway->findObjectData($id, 'Employee') ?? $employee),
			'DossierRequirement' => $this->gateway->loadAll('DossierRequirement'),
		];
		foreach (DossierCompletenessService::SOURCES as $schema) {
			if ($schema === 'DossierRequirement') {
				continue;
			}

			$found = $this->gateway->findFiltered($schema, ['employeeId' => $id]);
			$rows[$schema] = in_array($schema, self::EVIDENCE_SCHEMAS, true) ? $this->readable(schema: $schema, rows: $found) : $found;
		}

		$status = $this->labelled(rows: $this->completeness->statusFor(employeeId: $id, date: $day, rows: $rows));

		return new JSONResponse(['employeeId' => $id, 'date' => $day, 'complete' => $this->completeness->hasGap($status) === false, 'requirements' => $status]);
	}//end status()

	/**
	 * GET /api/dossier/incomplete: every readable employee with at least one gap.
	 *
	 * @param string|null $date The day to judge on (default today).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-002
	 */
	#[NoAdminRequired]
	public function incomplete(?string $date = null): JSONResponse {
		$day = $this->day(date: $date);
		if ($day === null) {
			return $this->badDate();
		}

		$rows = [];
		foreach (DossierCompletenessService::SOURCES as $schema) {
			$rows[$schema] = $this->gateway->loadAll($schema);
		}

		$out = [];
		foreach ($this->gateway->loadAll('Employee') as $employee) {
			$employeeId = (string)($employee['id'] ?? '');
			if ($employeeId === '' || $this->rbac->findOrNull(id: $employeeId, schema: 'Employee') === null) {
				continue;
			}

			$gaps = array_values(
				array_filter(
					$this->labelled(rows: $this->completeness->statusFor(employeeId: $employeeId, date: $day, rows: array_merge($rows, ['employee' => $employee]))),
					static fn (array $row): bool => $row['status'] !== DossierCompletenessService::PRESENT
				)
			);
			if ($gaps === []) {
				continue;
			}

			$out[] = [
				'id' => $employeeId,
				'name' => trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? '')),
				'employeeNumber' => ($employee['employeeNumber'] ?? null),
				'gapCount' => count($gaps),
				'gaps' => implode(', ', array_map(static fn (array $row): string => $row['label'] . ': ' . $row['statusLabel'], $gaps)),
			];
		}//end foreach

		usort($out, static fn (array $a, array $b): int => [$b['gapCount'], $a['name']] <=> [$a['gapCount'], $b['name']]);

		return new JSONResponse(['date' => $day, 'employees' => $out]);
	}//end incomplete()

	/**
	 * Keep only the rows the caller may read.
	 *
	 * @param string $schema The schema.
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function readable(string $schema, array $rows): array {
		return array_values(
			array_filter(
				$rows,
				fn (array $row): bool => (string)($row['id'] ?? '') !== '' && $this->rbac->findOrNull(id: (string)$row['id'], schema: $schema) !== null
			)
		);
	}//end readable()

	/**
	 * Add a translated statusLabel to each row.
	 *
	 * @param array<int, array<string, mixed>> $rows The status rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function labelled(array $rows): array {
		$labels = [
			'aanwezig' => $this->l10n->t('Present'),
			'ontbreekt' => $this->l10n->t('Missing'),
			'verlopen' => $this->l10n->t('Expired'),
			'verloopt-binnenkort' => $this->l10n->t('Expiring soon'),
			'te-oud-bij-start' => $this->l10n->t('Too old at start'),
		];
		foreach ($rows as $index => $row) {
			$rows[$index]['statusLabel'] = ($labels[$row['status']] ?? $row['status']);
		}

		return $rows;
	}//end labelled()

	/**
	 * The requested day as Y-m-d, today when none is given, null when unreadable.
	 *
	 * @param string|null $date The date parameter.
	 *
	 * @return string|null
	 */
	private function day(?string $date): ?string {
		$date = trim((string)$date);
		if ($date === '') {
			return (new DateTimeImmutable('today'))->format('Y-m-d');
		}

		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1 || checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false) {
			return null;
		}

		return $date;
	}//end day()

	/**
	 * The answer to a date that is not a date.
	 *
	 * @return JSONResponse
	 */
	private function badDate(): JSONResponse {
		return new JSONResponse(['error' => 'date moet een geldige datum zijn (JJJJ-MM-DD).'], Http::STATUS_BAD_REQUEST);
	}//end badDate()

}//end class
