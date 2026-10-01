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
use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\CalculationResult;
use OCA\Humaniq\Payroll\Loonaangifte\IncomeRelationshipLine;
use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteMessage;
use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteMessageBuilder;
use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteYear;
use OCA\Humaniq\Payroll\PackRepository;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\TaxTables;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Render, validate and store the wage tax return message of a filing.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The pure message classes, TaxTables::load and CalculationInput::fromDecoded are static by design, the AwfReviewService precedent.
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
	 * The service.
	 *
	 * @param HoursRegisterGateway $gateway    Register reads and writes.
	 * @param PayrollCalculator    $calculator The payroll engine, to recalculate a payslip's components.
	 * @param IAppConfig           $appConfig  The software relation number.
	 * @param LoggerInterface      $logger     Logger.
	 * @param PackRepository       $packs      The jurisdiction-pack resolver.
	 * @param IAppManager|null     $appManager The app version for the message header.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly PayrollCalculator $calculator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly PackRepository $packs=new PackRepository(),
		private readonly ?IAppManager $appManager=null,
	) {
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
	 */
	public function render(array $filing, string $userId): array {
		$filingId = (string)($filing['id'] ?? '');
		$refusal = $this->refusal($filing);
		if ($refusal !== null) {
			return ['status' => $refusal[0], 'filingId' => $filingId, 'message' => $refusal[1]];
		}

		$period = (string)$filing['period'];
		$dates = (array)LoonaangifteYear::periodDates($period, (string)($filing['tijdvak'] ?? 'maand'));
		$year = (array)LoonaangifteYear::forYear((int)substr($period, 0, 4));
		$administrationId = (string)($filing['administrationId'] ?? '');
		$run = $this->approvedRun($administrationId, $period);

		$stored = ['messageRenderedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'messageRenderedBy' => $userId, 'messageVersion' => $year['version']];
		if ($run === null) {
			$findings = [['kind' => 'run-not-approved', 'severity' => 'blocking', 'employeeId' => '', 'element' => 'TijdvakAangifte', 'problem' => 'Er is voor ' . $period . ' geen goedgekeurde loonrun; de aangifte wordt alleen gemaakt uit een goedgekeurde, geboekte of betaalde loonrun.']];
			return $this->store($filing, $stored, $findings, null);
		}

		$administration = ($this->gateway->findFiltered('hrAdministration', ['administrationId' => $administrationId])[0] ?? []);
		$createdAt = gmdate('Y-m-d\TH:i:s');
		$header = [
			'idBer' => mb_substr('LA' . $period . gmdate('YmdHis'), 0, 32),
			'createdAt' => $createdAt,
			'relNr' => trim($this->appConfig->getValueString(Application::APP_ID, ThirdPartyReportService::RELNR_KEY, '')),
			'software' => 'humaniq ' . $this->softwareVersion(),
		];
		$built = LoonaangifteMessageBuilder::build($administration, $header, [$dates[0], $dates[1]], $this->lines($run, [$dates[0], $dates[1]]));

		$stored = array_merge($stored, [
			'messageRunId' => (string)$run['id'],
			'messageRunCalculatedAt' => (string)($run['calculatedAt'] ?? ''),
			'messageRunTotalLoonheffing' => (float)($run['totalLoonheffing'] ?? 0),
			'collectiveTotals' => $built['collective'],
		]);
		$message = null;
		if ($this->blocking($built['findings']) === 0) {
			$xml = LoonaangifteMessage::render($year['namespace'], $year['version'], $built['tree']);
			$errors = LoonaangifteMessage::errors($xml, $year['xsd']);
			foreach ($errors as $error) {
				$built['findings'][] = ['kind' => 'schema-invalid', 'severity' => 'blocking', 'employeeId' => '', 'element' => 'Loonaangifte', 'problem' => 'Het bericht voldoet niet aan het XSD van de Belastingdienst: ' . $error];
			}

			$message = ($errors === []) ? ['xml' => $xml, 'fileName' => 'LH_' . (string)$administration['loonheffingennummer'] . '_' . $period . '_' . gmdate('YmdHis') . '.xml'] : null;
		}

		return $this->store($filing, $stored, $built['findings'], $message);
	}//end render()

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
	 * @return list<array{tree: array<string, mixed>, cents: array<string, int>, findings: list<array<string, string>>}>
	 */
	private function lines(array $run, array $period): array {
		$lines = [];
		foreach ($this->gateway->findFiltered('Payslip', ['payrollRunId' => (string)$run['id']]) as $slip) {
			$employeeId = (string)($slip['employeeId'] ?? '');
			$employee = ($this->gateway->findObjectData($employeeId, 'Employee') ?? ['id' => $employeeId]);
			$employee['id'] = $employeeId;
			$lines[] = IncomeRelationshipLine::make($employee, $this->contractOf($employeeId, $period), $slip, $this->recalculate($slip), $period);
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
	 * The engine's recalculation of a payslip from its stored input, or null.
	 *
	 * @param array<string, mixed> $slip The payslip.
	 *
	 * @return CalculationResult|null
	 */
	private function recalculate(array $slip): ?CalculationResult {
		$snapshot = ($slip['engineInputSnapshot'] ?? null);
		if (is_array($snapshot) === false || $snapshot === []) {
			return null;
		}

		try {
			$tables = TaxTables::load($this->packs->resolve((string)($snapshot['jurisdiction'] ?? 'NL'), (string)($slip['period'] ?? ''))->tablesId());
			return $this->calculator->calculate(CalculationInput::fromDecoded($snapshot), $tables);
		} catch (\Throwable $e) {
			$this->logger->warning('LoonaangifteMessageService: payslip ' . (string)($slip['id'] ?? '') . ' could not be recalculated: ' . $e->getMessage());
			return null;
		}
	}//end recalculate()

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
