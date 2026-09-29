<?php

/**
 * Humaniq FlexContractController
 *
 * The fixed-term chain of one contract, and the on-call average hours
 * (people-flex-contract-rules D1, D3, D4).
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
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\ContractChainService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\OnCallAverageService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves the contract chain and the on-call overview.
 *
 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
 */
class FlexContractController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request  The request.
	 * @param HoursRegisterGateway $gateway  Register reads.
	 * @param RbacObjectReader     $rbac     What the caller may read.
	 * @param ContractChainService $chains   The chain model.
	 * @param OnCallAverageService $averages The on-call averages.
	 * @param HumaniqRoles         $roles    Who is HR.
	 * @param IUserSession         $session  The caller.
	 * @param IL10N                $l10n     Translations for the CSV header.
	 */
	public function __construct(
		IRequest $request,
		private readonly HoursRegisterGateway $gateway,
		private readonly RbacObjectReader $rbac,
		private readonly ContractChainService $chains,
		private readonly OnCallAverageService $averages,
		private readonly HumaniqRoles $roles,
		private readonly IUserSession $session,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/contracts/{id}/chain: where a contract sits in its fixed-term chain.
	 *
	 * `rows` holds one summary row for a link and none for a contract outside
	 * any chain, so the contract page shows its empty text there.
	 *
	 * @param string $id The contract.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-001
	 */
	#[NoAdminRequired]
	public function chain(string $id): JSONResponse {
		$contract = $this->rbac->findOrNull(id: $id, schema: 'EmploymentContract');
		if ($contract === null) {
			return new JSONResponse(['message' => 'Contract not found'], Http::STATUS_NOT_FOUND);
		}

		$employeeId = (string)($contract['employeeId'] ?? '');
		$siblings = [];
		if ($employeeId !== '') {
			$siblings = $this->gateway->findFiltered('EmploymentContract', ['employeeId' => $employeeId]);
		}

		$siblings = array_values(array_filter($siblings, static fn (array $row): bool => (string)($row['id'] ?? '') !== $id));
		$siblings[] = array_merge($contract, ['id' => $id]);
		$chain = $this->chains->chainFor(contracts: $siblings, contractId: $id);
		$rows = [];
		if ($chain['position'] > 0) {
			$rows[] = $chain;
		}

		return new JSONResponse(array_merge($chain, ['contractId' => $id, 'rows' => $rows]));
	}//end chain()

	/**
	 * GET /api/contracts/on-call-averages?from&to&format: the average approved
	 * hours per on-call contract, as JSON or as a CSV download. HR only.
	 *
	 * @param string|null $from   First day (default: a year before `to`).
	 * @param string|null $to     Last day (default: today).
	 * @param string|null $format `csv` for a download.
	 *
	 * @return JSONResponse|DataDisplayResponse
	 *
	 * @spec openspec/specs/flex-contract-rules/spec.md#REQ-FLX-002
	 */
	#[NoAdminRequired]
	public function onCallAverages(?string $from = null, ?string $to = null, ?string $format = null): JSONResponse|DataDisplayResponse {
		if ($this->roles->isHr($this->session->getUser()?->getUID()) === false) {
			return new JSONResponse(['message' => 'Only HR can read the on-call overview.'], Http::STATUS_FORBIDDEN);
		}

		$window = $this->averages->window(from: $from, to: $to);
		if ($window === null) {
			return new JSONResponse(['message' => 'from and to must be dates (YYYY-MM-DD), from not after to.'], Http::STATUS_BAD_REQUEST);
		}

		$rows = $this->named(rows: $this->averages->averages(
			contracts: $this->readable(rows: $this->gateway->findFiltered('EmploymentContract', ['type' => 'oproep'])),
			entries: $this->gateway->loadAll('TimeEntry'),
			approvedTimesheetIds: array_map(static fn (array $row): string => (string)($row['id'] ?? ''), $this->gateway->findFiltered('Timesheet', ['status' => 'approved'])),
			from: $window[0],
			to: $window[1]
		));

		if ($format === 'csv') {
			$download = new DataDisplayResponse($this->csv(rows: $rows), Http::STATUS_OK, ['Content-Type' => 'text/csv; charset=utf-8']);
			$download->addHeader('Content-Disposition', 'attachment; filename="on-call-averages-' . $window[0] . '-' . $window[1] . '.csv"');

			return $download;
		}

		return new JSONResponse(['from' => $window[0], 'to' => $window[1], 'rows' => $rows]);
	}//end onCallAverages()

	/**
	 * Keep the contracts the caller may read.
	 *
	 * @param array<int, array<string, mixed>> $rows The contracts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function readable(array $rows): array {
		return array_values(
			array_filter(
				$rows,
				fn (array $row): bool => (string)($row['id'] ?? '') !== '' && $this->rbac->findOrNull(id: (string)$row['id'], schema: 'EmploymentContract') !== null
			)
		);
	}//end readable()

	/**
	 * Add each employee's name and number.
	 *
	 * @param array<int, array<string, mixed>> $rows The average rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function named(array $rows): array {
		foreach ($rows as $index => $row) {
			$employee = ($this->gateway->findObjectData((string)$row['employeeId'], 'Employee') ?? []);
			$rows[$index]['name'] = trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? ''));
			$rows[$index]['employeeNumber'] = ($employee['employeeNumber'] ?? null);
		}

		usort($rows, static fn (array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));

		return $rows;
	}//end named()

	/**
	 * The rows as CSV, with a translated header.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return string
	 */
	private function csv(array $rows): string {
		$lines = [[
			$this->l10n->t('Employee'),
			$this->l10n->t('Employee number'),
			$this->l10n->t('Counted from'),
			$this->l10n->t('Counted to'),
			$this->l10n->t('Approved hours'),
			$this->l10n->t('Hours per week'),
			$this->l10n->t('Hours per month'),
			$this->l10n->t('Offer due'),
		],
		];
		foreach ($rows as $row) {
			$lines[] = [
				$row['name'],
				(string)($row['employeeNumber'] ?? ''),
				$row['countedFrom'],
				$row['countedTo'],
				(string)$row['hours'],
				(string)$row['hoursPerWeek'],
				(string)$row['hoursPerMonth'],
				$row['offerDue'] === true ? $this->l10n->t('Yes') : $this->l10n->t('No'),
			];
		}

		return implode("\n", array_map(static fn (array $line): string => implode(',', array_map(static fn (string $cell): string => '"' . str_replace('"', '""', $cell) . '"', $line)), $lines)) . "\n";
	}//end csv()
}//end class
