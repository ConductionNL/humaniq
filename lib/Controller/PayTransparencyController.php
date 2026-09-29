<?php

/**
 * Pay transparency controller
 *
 * `GET /api/reports/pay-transparency?year` answers the gender pay gap report
 * of the caller's active administration, and `POST
 * /api/reports/pay-transparency/export` the same report as CSV. HR and
 * accountants only (the analytics endpoint's full-reader roles,
 * reporting-pay-transparency D4). The rows are read without the caller's
 * field authorization because the report only shows figures of groups at or
 * above the threshold, never a person.
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
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AnalyticsAccess;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\PayTransparencyService;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The gender pay gap report, as JSON and as CSV.
 *
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
 */
class PayTransparencyController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request  The request.
	 * @param HoursRegisterGateway   $gateway  Reads the register's rows.
	 * @param PayTransparencyService $service  Computes the indicators.
	 * @param AnalyticsAccess        $access   HR and accountants of the active administration.
	 * @param SettingsService        $settings The group threshold.
	 * @param IUserSession           $session  The caller.
	 * @param ITimeFactory           $time     The clock for the default year.
	 * @param IL10N                  $l10n     Translations for the CSV and the row texts.
	 */
	public function __construct(
		IRequest $request,
		private readonly HoursRegisterGateway $gateway,
		private readonly PayTransparencyService $service,
		private readonly AnalyticsAccess $access,
		private readonly SettingsService $settings,
		private readonly IUserSession $session,
		private readonly ITimeFactory $time,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/reports/pay-transparency?year: the report; the year defaults to
	 * the previous calendar year.
	 *
	 * @param int|null $year The year.
	 *
	 * @return JSONResponse 403 outside HR and accountants.
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-002
	 */
	#[NoAdminRequired]
	public function report(?int $year = null): JSONResponse {
		$report = $this->build(year: $year);
		if ($report === null) {
			return new JSONResponse(['message' => 'Only HR and accountants can read the pay transparency report.'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($report);
	}//end report()

	/**
	 * POST /api/reports/pay-transparency/export {year}: the report as CSV.
	 *
	 * @param int|null $year The year.
	 *
	 * @return JSONResponse|DataDisplayResponse 403 outside HR and accountants.
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-002
	 */
	#[NoAdminRequired]
	public function export(?int $year = null): JSONResponse|DataDisplayResponse {
		$report = $this->build(year: $year);
		if ($report === null) {
			return new JSONResponse(['message' => 'Only HR and accountants can read the pay transparency report.'], Http::STATUS_FORBIDDEN);
		}

		$download = new DataDisplayResponse($this->csv(report: $report), Http::STATUS_OK, ['Content-Type' => 'text/csv; charset=utf-8']);
		$download->addHeader('Content-Disposition', 'attachment; filename="pay-transparency-' . $report['year'] . '.csv"');

		return $download;
	}//end export()

	/**
	 * The report of the caller's administration, or null when refused.
	 *
	 * @param int|null $year The year, or null for the previous calendar year.
	 *
	 * @return array<string, mixed>|null
	 */
	private function build(?int $year): ?array {
		$userId = (string)($this->session->getUser()?->getUID() ?? '');
		$administrationId = $userId === '' ? null : $this->access->fullReaderAdministration($userId);
		if ($administrationId === null) {
			return null;
		}

		$year = ($year ?? ((int)$this->time->now()->format('Y') - 1));
		$rows = [
			'employees' => $this->inAdministration(schema: 'Employee', administrationId: $administrationId),
			'contracts' => $this->inAdministration(schema: 'EmploymentContract', administrationId: $administrationId),
			'payslips' => $this->inAdministration(schema: 'Payslip', administrationId: $administrationId),
			'normfuncties' => $this->gateway->loadAll('Normfunctie'),
		];

		$report = $this->service->report(rows: $rows, year: $year, threshold: $this->settings->getPayTransparencyMinimumGroup());
		foreach ($report['categories'] as $index => $category) {
			$report['categories'][$index] = $this->displayRow(row: $category);
		}

		return $report;
	}//end build()

	/**
	 * A category row with the texts the page table shows.
	 *
	 * @param array<string, mixed> $row The category indicators.
	 *
	 * @return array<string, mixed>
	 */
	private function displayRow(array $row): array {
		$category = (string)($row['category'] ?? '');
		$row['id'] = $category === '' ? 'uncategorised' : $category;
		$row['name'] = $category === '' ? $this->l10n->t('No pay category') : $category;
		$tooSmall = $this->l10n->t('Too small to report');
		foreach (['meanGap', 'medianGap', 'variableMeanGap'] as $key) {
			$value = ($row[$key] ?? null);
			$row[$key . 'Text'] = $row['tooSmall'] === true ? $tooSmall : ($value === null ? '' : number_format((float)$value, 1) . '%');
		}

		return $row;
	}//end displayRow()

	/**
	 * Rows of a schema in the administration.
	 *
	 * @param string $schema           The schema.
	 * @param string $administrationId The administration.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function inAdministration(string $schema, string $administrationId): array {
		return array_values(
			array_filter(
				$this->gateway->loadAll($schema),
				static fn (array $row): bool => (string)($row['administrationId'] ?? '') === $administrationId
			)
		);
	}//end inAdministration()

	/**
	 * The report as CSV: the whole administration, then one row per category.
	 *
	 * @param array<string, mixed> $report The report.
	 *
	 * @return string
	 */
	private function csv(array $report): string {
		$lines = [[
			$this->l10n->t('Pay category'),
			$this->l10n->t('Women'),
			$this->l10n->t('Men'),
			$this->l10n->t('Result'),
			$this->l10n->t('Mean gap (%)'),
			$this->l10n->t('Median gap (%)'),
			$this->l10n->t('Mean gap in variable pay (%)'),
			$this->l10n->t('Median gap in variable pay (%)'),
			$this->l10n->t('Women receiving variable pay (%)'),
			$this->l10n->t('Men receiving variable pay (%)'),
		],
		];
		$lines[] = $this->csvLine(name: $this->l10n->t('All employees'), row: $report['overall']);
		foreach ($report['categories'] as $category) {
			$lines[] = $this->csvLine(name: (string)$category['name'], row: $category);
		}

		return implode("\n", array_map(static fn (array $line): string => implode(',', array_map(static fn (string $cell): string => '"' . str_replace('"', '""', $cell) . '"', $line)), $lines)) . "\n";
	}//end csv()

	/**
	 * One CSV line; a group too small to report carries no figure.
	 *
	 * @param string               $name The group.
	 * @param array<string, mixed> $row  The indicators.
	 *
	 * @return array<int, string>
	 */
	private function csvLine(string $name, array $row): array {
		if ($row['tooSmall'] === true) {
			return [$name, '', '', $this->l10n->t('Too small to report'), '', '', '', '', '', ''];
		}

		$cells = [$name, (string)$row['women'], (string)$row['men'], ''];
		foreach (['meanGap', 'medianGap', 'variableMeanGap', 'variableMedianGap', 'womenReceivingVariable', 'menReceivingVariable'] as $key) {
			$cells[] = $row[$key] === null ? '' : number_format((float)$row[$key], 1, '.', '');
		}

		return $cells;
	}//end csvLine()
}//end class
