<?php

/**
 * LoonaangifteMessageService
 *
 * Makes the wage tax return message of a Dutch LoonaangifteFiling from the
 * administration's approved payroll run for the filing's period: every
 * payslip's engine input is recalculated for the premium components, each
 * employee becomes one income relationship, and the message is validated
 * against the year's XSD before it is stored on the filing. What stops the
 * message is stored as findings, per employee and element, and the
 * klaarzetten guard refuses while any of them blocks
 * (filings-wage-tax-message D1, D3, D5).
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Payroll\Loonaangifte\IncomeRelationshipLine;
use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteMessage;
use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteMessageBuilder;
use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteYear;
use OCA\Humaniq\Payroll\Loonaangifte\PayslipRecalculator;
use OCP\App\IAppManager;
use OCP\IAppConfig;

/**
 * Render, validate and store the wage tax return message of a filing.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The pure message classes are static by design, the UbdMessage precedent.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */
class LoonaangifteMessageService {

	/**
	 * The run states a return is made from (D1).
	 *
	 * @var list<string>
	 */
	private const FILED_RUN_STATES = ['approved', 'posted', 'paid'];

	/**
	 * The corrections a return carries.
	 *
	 * @var CarriedCorrections
	 */
	private readonly CarriedCorrections $carried;

	/**
	 * The service.
	 *
	 * @param HoursRegisterGateway $gateway      Register reads and writes.
	 * @param PayslipRecalculator  $recalculator The engine, to recalculate a payslip's components.
	 * @param IAppConfig           $appConfig    The software relation number.
	 * @param IAppManager|null        $appManager   The app version for the message header.
	 * @param CarriedCorrections|null $carried      The corrections a return carries (defaults to one over the gateway).
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly PayslipRecalculator $recalculator,
		private readonly IAppConfig $appConfig,
		private readonly ?IAppManager $appManager=null,
		?CarriedCorrections $carried=null,
	) {
		$this->carried = ($carried ?? new CarriedCorrections($gateway));
	}//end __construct()

	/**
	 * Make the message of a filing and store it, or store why it cannot be made.
	 *
	 * @param array<string, mixed> $filing The LoonaangifteFiling, with its id.
	 * @param string               $userId The acting account.
	 *
	 * @return array{status: string, filingId: string, blockingFindings?: int, warningFindings?: int, messageFileName?: string, message?: string}
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
	 */
	public function render(array $filing, string $userId): array {
		$filingId = (string)($filing['id'] ?? '');
		$refusal = $this->refusal($filing);
		if ($refusal !== null) {
			return ['status' => $refusal[0], 'filingId' => $filingId, 'message' => $refusal[1]];
		}

		$period = (string)$filing['period'];
		$dates = (array)LoonaangifteYear::periodDates($period, (string)($filing['tijdvak'] ?? 'maand'));
		// The refusal above has checked that the year and the period are known.
		$year = (LoonaangifteYear::forYear((int)substr($period, 0, 4)) ?? ['version' => '', 'namespace' => '', 'xsd' => '']);
		$current = $this->periodLines((string)($filing['administrationId'] ?? ''), $period, [$dates[0], $dates[1]]);
		$run = $current['run'];

		$stored = ['messageRenderedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'messageRenderedBy' => $userId, 'messageVersion' => $year['version']];
		if ($run === null) {
			$findings = [['kind' => 'run-not-approved', 'severity' => 'blocking', 'employeeId' => '', 'element' => 'TijdvakAangifte', 'problem' => 'Er is voor ' . $period . ' geen goedgekeurde loonrun; de aangifte wordt alleen gemaakt uit een goedgekeurde, geboekte of betaalde loonrun.']];
			return $this->store($filing, $stored, $findings, null);
		}

		$corrections = $this->carried->forReturn($filing);
		$parts = array_map(static fn (array $correction): array => ['tree' => (array)$correction['correctionTree'], 'saldo' => (int)($correction['correctionSaldo'] ?? 0)], $corrections);
		$built = LoonaangifteMessageBuilder::build($current['administration'], $this->header('LA' . $period), [$dates[0], $dates[1]], $current['lines'], $parts);

		$stored = array_merge($stored, [
			'messageRunId' => (string)$run['id'],
			'messageRunCalculatedAt' => (string)($run['calculatedAt'] ?? ''),
			'messageRunTotalLoonheffing' => (float)($run['totalLoonheffing'] ?? 0),
			'collectiveTotals' => $built['collective'],
			'carriedCorrectionIds' => array_map(static fn (array $correction): string => (string)$correction['id'], $corrections),
		]);
		$findings = $built['findings'];
		$message = null;
		if ($this->blocking($findings) === 0) {
			$fileName = 'LH_' . (string)($current['administration']['loonheffingennummer'] ?? '') . '_' . $period . '_' . gmdate('YmdHis') . '.xml';
			[$message, $findings] = $this->validated($built['tree'], $year, $fileName, $findings);
		}

		$outcome = $this->store($filing, $stored, $findings, $message);
		if ($message !== null) {
			foreach ($corrections as $correction) {
				$this->carried->stamp($correction, $filingId);
			}
		}

		return $outcome;
	}//end render()

	/**
	 * The approved run of a period and one income relationship per payslip,
	 * for the regular return and for a correction of the period.
	 *
	 * @param string                      $administrationId The administration.
	 * @param string                      $period           The period.
	 * @param array{0: string, 1: string} $dates            The declaration period's first and last day.
	 *
	 * @return array{run: array<string, mixed>|null, administration: array<string, mixed>, lines: list<array{employeeId: string, tree: array<string, mixed>, cents: array<string, int>, findings: list<array{kind: string, severity: string, employeeId: string, element: string, problem: string}>}>}
	 *
	 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
	 */
	public function periodLines(string $administrationId, string $period, array $dates): array {
		$run = $this->approvedRun($administrationId, $period);
		return [
			'run' => $run,
			'administration' => ($this->gateway->findFiltered('hrAdministration', ['administrationId' => $administrationId])[0] ?? []),
			'lines' => ($run === null ? [] : $this->lines($run, $dates)),
		];
	}//end periodLines()

	/**
	 * The message header values (GS p30-33).
	 *
	 * @param string $prefix The message id prefix with the period.
	 *
	 * @return array{idBer: string, createdAt: string, relNr: string, software: string}
	 *
	 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
	 */
	public function header(string $prefix): array {
		return [
			'idBer' => mb_substr($prefix . gmdate('YmdHis'), 0, 32),
			'createdAt' => gmdate('Y-m-d\TH:i:s'),
			'relNr' => trim($this->appConfig->getValueString(Application::APP_ID, ThirdPartyReportService::RELNR_KEY, '')),
			'software' => 'humaniq ' . $this->softwareVersion(),
		];
	}//end header()

	/**
	 * Serialise and validate a message tree.
	 *
	 * @param array<string, mixed>                                   $tree     The tree.
	 * @param array{version: string, namespace: string, xsd: string} $year     The year entry.
	 * @param string                                                 $fileName The file name.
	 * @param list<array<string, string>>                            $findings The findings so far.
	 *
	 * @return array{0: array{xml: string, fileName: string}|null, 1: list<array<string, string>>}
	 *
	 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
	 */
	public function validated(array $tree, array $year, string $fileName, array $findings): array {
		$xml = LoonaangifteMessage::render($year['namespace'], $year['version'], $tree);
		$errors = LoonaangifteMessage::errors($xml, $year['xsd']);
		foreach ($errors as $error) {
			$findings[] = ['kind' => 'schema-invalid', 'severity' => 'blocking', 'employeeId' => '', 'element' => 'Loonaangifte', 'problem' => 'Het bericht voldoet niet aan het XSD van de Belastingdienst: ' . $error];
		}

		return [($errors === [] ? ['xml' => $xml, 'fileName' => $fileName] : null), $findings];
	}//end validated()

	/**
	 * Why a filing is not rendered at all, or null.
	 *
	 * @param array<string, mixed> $filing The filing.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function refusal(array $filing): ?array {
		if ((string)($filing['jurisdiction'] ?? '') !== 'NL' || (string)($filing['filingType'] ?? '') !== 'loonaangifte') {
			return ['refused-not-nl', 'Alleen een Nederlandse loonaangifte heeft een aangiftebericht.'];
		}

		if ((string)($filing['status'] ?? 'concept') !== 'concept') {
			return ['refused-not-concept', 'Deze aangifte is al klaargezet, bevestigd of verzonden; heropen haar eerst.'];
		}

		$period = (string)($filing['period'] ?? '');
		if (LoonaangifteYear::forYear((int)substr($period, 0, 4)) === null || LoonaangifteYear::periodDates($period, (string)($filing['tijdvak'] ?? 'maand')) === null) {
			return ['refused-no-specification', 'humaniq kent de specificaties van de aangifte voor tijdvak ' . $period . ' niet.'];
		}

		return null;
	}//end refusal()

	/**
	 * The administration's approved, posted or paid run of a period; the
	 * latest calculated one when there are several.
	 *
	 * @param string $administrationId The administration.
	 * @param string $period           The period.
	 *
	 * @return array<string, mixed>|null
	 */
	private function approvedRun(string $administrationId, string $period): ?array {
		$found = null;
		foreach ($this->gateway->findFiltered('PayrollRun', ['administrationId' => $administrationId, 'period' => $period]) as $run) {
			if (in_array((string)($run['status'] ?? ''), self::FILED_RUN_STATES, true) === false) {
				continue;
			}

			if ($found === null || (string)($run['calculatedAt'] ?? '') > (string)($found['calculatedAt'] ?? '')) {
				$found = $run;
			}
		}

		return $found;
	}//end approvedRun()

	/**
	 * One income relationship per payslip of the run.
	 *
	 * @param array<string, mixed>        $run    The run.
	 * @param array{0: string, 1: string} $period The declaration period.
	 *
	 * @return list<array{employeeId: string, tree: array<string, mixed>, cents: array<string, int>, findings: list<array{kind: string, severity: string, employeeId: string, element: string, problem: string}>}>
	 */
	private function lines(array $run, array $period): array {
		$lines = [];
		foreach ($this->gateway->findFiltered('Payslip', ['payrollRunId' => (string)$run['id']]) as $slip) {
			$employeeId = (string)($slip['employeeId'] ?? '');
			$employee = ($this->gateway->findObjectData($employeeId, 'Employee') ?? ['id' => $employeeId]);
			$employee['id'] = $employeeId;
			$lines[] = IncomeRelationshipLine::make($employee, $this->contractOf($employeeId, $period), $slip, $this->recalculator->recalculate($slip), $period);
		}

		return $lines;
	}//end lines()

	/**
	 * The contract covering the period, the latest started when several do.
	 *
	 * @param string                      $employeeId The employee.
	 * @param array{0: string, 1: string} $period     The declaration period.
	 *
	 * @return array<string, mixed>
	 */
	private function contractOf(string $employeeId, array $period): array {
		$covering = [];
		foreach ($this->gateway->findFiltered('EmploymentContract', ['employeeId' => $employeeId]) as $contract) {
			$start = (string)($contract['startDate'] ?? '');
			$end = (string)($contract['endDate'] ?? '');
			if ($start <= $period[1] && ($end === '' || $end >= $period[0])) {
				$covering[$start . '|' . (string)($contract['id'] ?? '')] = $contract;
			}
		}

		krsort($covering);
		return (array_values($covering)[0] ?? []);
	}//end contractOf()

	/**
	 * Store the outcome on the filing.
	 *
	 * @param array<string, mixed>                $filing   The filing.
	 * @param array<string, mixed>                $stored   The render facts.
	 * @param list<array<string, string>>         $findings The findings.
	 * @param array{xml: string, fileName: string}|null $message The validated message, or null.
	 *
	 * @return array{status: string, filingId: string, blockingFindings: int, warningFindings: int, messageFileName?: string}
	 */
	private function store(array $filing, array $stored, array $findings, ?array $message): array {
		$filingId = (string)($filing['id'] ?? '');
		$blocking = $this->blocking($findings);
		$payload = array_merge($filing, $stored, [
			'messageFindings' => $findings,
			'blockingFindings' => $blocking,
			'warningFindings' => (count($findings) - $blocking),
			'messageXml' => ($message['xml'] ?? null),
			'messageFileName' => ($message['fileName'] ?? null),
		]);
		unset($payload['id']);
		$this->gateway->save(payload: $payload, schema: 'LoonaangifteFiling', uuid: ($filingId === '' ? null : $filingId));

		$outcome = ['status' => ($message === null ? 'blocked' : 'rendered'), 'filingId' => $filingId, 'blockingFindings' => $blocking, 'warningFindings' => (count($findings) - $blocking)];
		if ($message !== null) {
			$outcome['messageFileName'] = $message['fileName'];
		}

		return $outcome;
	}//end store()

	/**
	 * The number of blocking findings.
	 *
	 * @param list<array<string, string>> $findings The findings.
	 *
	 * @return int
	 */
	private function blocking(array $findings): int {
		return count(array_filter($findings, static fn (array $finding): bool => ($finding['severity'] ?? '') === 'blocking'));
	}//end blocking()

	/**
	 * The app version for the message header.
	 *
	 * @return string
	 */
	private function softwareVersion(): string {
		$version = ($this->appManager?->getAppVersion(Application::APP_ID) ?? '');
		return $version === '' ? 'dev' : $version;
	}//end softwareVersion()

}//end class
