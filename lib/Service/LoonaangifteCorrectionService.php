<?php

/**
 * LoonaangifteCorrectionService
 *
 * Corrects a sent wage tax return by a linked new filing (filingType
 * `correctie`, `corrects` naming the sent one) that carries only what
 * changed. The sent filing is never touched again. Making the correction
 * compares the period's current approved payslips with the last stand the
 * Belastingdienst received (the sent message, then every sent correction
 * of that period) and stores, per employee, what changed, the period's new
 * collective stand and the saldo against the old one. Inside the tax year
 * the correction travels with the next return (Gegevensspecificaties 2026,
 * 2.4.1: TijdvakCorrectie plus SaldoCorrectiesVoorgaandTijdvak); for a
 * closed year it is its own correction message (2.4.3).
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
 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use OCA\Humaniq\Payroll\Loonaangifte\CorrectionDiff;
use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteMessageBuilder;
use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteYear;
use Psr\Log\LoggerInterface;

/**
 * Open and make corrections of sent wage tax returns.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The pure message classes are static by design, the LoonaangifteMessageService precedent.
 *
 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-001
 */
class LoonaangifteCorrectionService {

	/**
	 * The correction travels with the next return of the same tax year.
	 *
	 * @var string
	 */
	public const ROUTE_NEXT_RETURN = 'volgende-aangifte';

	/**
	 * The correction is its own message (a closed year).
	 *
	 * @var string
	 */
	public const ROUTE_OWN_MESSAGE = 'correctiebericht';

	/**
	 * The day the service decides the route on.
	 *
	 * @var DateTimeImmutable
	 */
	private readonly DateTimeImmutable $today;

	/**
	 * The service.
	 *
	 * @param HoursRegisterGateway       $gateway  Register reads and writes.
	 * @param LoonaangifteMessageService $messages The period's lines and the message header.
	 * @param LoggerInterface            $logger   Logger.
	 * @param DateTimeImmutable|null     $today    The day, for the route (defaults to now).
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly LoonaangifteMessageService $messages,
		private readonly LoggerInterface $logger,
		?DateTimeImmutable $today=null,
	) {
		$this->today = ($today ?? new DateTimeImmutable('now'));
	}//end __construct()

	/**
	 * Open a correction of a sent return, or return the one already open.
	 *
	 * @param array<string, mixed> $sent   The sent LoonaangifteFiling (or sent correction), with its id.
	 * @param string               $userId The acting account.
	 *
	 * @return array{status: string, filingId: string, message?: string}
	 *
	 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-001
	 */
	public function open(array $sent, string $userId): array {
		$sentId = (string)($sent['id'] ?? '');
		if ((string)($sent['jurisdiction'] ?? '') !== 'NL' || in_array((string)($sent['filingType'] ?? ''), ['loonaangifte', 'correctie'], true) === false) {
			return ['status' => 'refused-not-nl', 'filingId' => $sentId, 'message' => 'Alleen een Nederlandse loonaangifte kan worden gecorrigeerd.'];
		}

		if ((string)($sent['status'] ?? '') !== 'verzonden') {
			return ['status' => 'refused-not-sent', 'filingId' => $sentId, 'message' => 'Alleen een verzonden aangifte wordt gecorrigeerd; heropen een aangifte die nog niet is verzonden.'];
		}

		$period = (string)($sent['period'] ?? '');
		$administrationId = (string)($sent['administrationId'] ?? '');
		foreach ($this->correctionsOf($administrationId, $period) as $existing) {
			if (in_array((string)($existing['status'] ?? ''), ['concept', 'klaargezet', 'bevestigd'], true) === true) {
				return ['status' => 'exists', 'filingId' => (string)$existing['id'], 'message' => 'Er staat al een correctie open voor ' . $period . '.'];
			}
		}

		$payload = [
			'period' => $period,
			'jurisdiction' => 'NL',
			'filingType' => 'correctie',
			'tijdvak' => (string)($sent['tijdvak'] ?? 'maand'),
			'deadline' => (string)($sent['deadline'] ?? ''),
			'status' => 'concept',
			'administrationId' => $administrationId,
			'corrects' => $sentId,
			'correctionRoute' => $this->route($period, (string)($sent['tijdvak'] ?? 'maand')),
			'retainedUntil' => ($sent['retainedUntil'] ?? null),
		];
		$saved = $this->gateway->save(payload: $payload, schema: 'LoonaangifteFiling');
		$this->logger->info('LoonaangifteCorrectionService: ' . $userId . ' opened a correction of filing ' . $sentId);

		return ['status' => 'opened', 'filingId' => (string)$saved->getUuid()];
	}//end open()

	/**
	 * Make a correction: compare, store the lines, the new stand and the
	 * saldo, and for a closed year render the correction message.
	 *
	 * @param array<string, mixed> $correction The correction filing, with its id.
	 * @param string               $userId     The acting account.
	 *
	 * @return array{status: string, filingId: string, blockingFindings?: int, warningFindings?: int, message?: string}
	 *
	 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
	 */
	public function render(array $correction, string $userId): array {
		$filingId = (string)($correction['id'] ?? '');
		if ((string)($correction['status'] ?? 'concept') !== 'concept') {
			return ['status' => 'refused-not-concept', 'filingId' => $filingId, 'message' => 'Deze correctie is al klaargezet of verzonden.'];
		}

		$period = (string)($correction['period'] ?? '');
		$tijdvak = (string)($correction['tijdvak'] ?? 'maand');
		$year = LoonaangifteYear::forYear((int)substr($period, 0, 4));
		$dates = LoonaangifteYear::periodDates($period, $tijdvak);
		if ($year === null || $dates === null) {
			return ['status' => 'refused-no-specification', 'filingId' => $filingId, 'message' => 'humaniq kent de specificaties van de aangifte voor tijdvak ' . $period . ' niet.'];
		}

		$stored = ['messageRenderedAt' => gmdate('Y-m-d\TH:i:s\Z'), 'messageRenderedBy' => $userId, 'messageVersion' => $year['version']];
		$baseline = $this->baseline($correction);
		if ($baseline === null) {
			return $this->store($correction, $stored, [$this->finding('baseline-without-message', 'De gecorrigeerde aangifte heeft geen bericht van humaniq; corrigeer haar buiten humaniq.')]);
		}

		$current = $this->messages->periodLines((string)($correction['administrationId'] ?? ''), $period, [$dates[0], $dates[1]]);
		if ($current['run'] === null) {
			return $this->store($correction, $stored, [$this->finding('run-not-approved', 'Er is voor ' . $period . ' geen goedgekeurde loonrun.')]);
		}

		$diff = CorrectionDiff::compare($baseline['relationships'], $current['lines']);
		$stand = LoonaangifteMessageBuilder::collective($current['lines']);
		unset($stand['TotGen']);
		$findings = $this->lineFindings($current['lines']);
		$saldo = ($stand['TotTeBet'] - $baseline['totTeBet']);
		if ($diff['lines'] === [] && $saldo === 0) {
			$findings[] = $this->finding('nothing-to-correct', 'Er is niets te corrigeren: de loonstroken van ' . $period . ' komen overeen met wat is verzonden.');
		}

		$tree = [
			'DatAanvTv' => $dates[0],
			'DatEindTv' => $dates[1],
			'CollectieveAangifte' => array_map(static fn (int $euros): string => (string)$euros, $stand),
			'InkomstenverhoudingInitieel' => $diff['initial'],
			'InkomstenverhoudingIntrekking' => $diff['withdrawn'],
		];
		$stored = array_merge($stored, [
			'messageRunId' => (string)$current['run']['id'],
			'messageRunCalculatedAt' => (string)($current['run']['calculatedAt'] ?? ''),
			'messageRunTotalLoonheffing' => (float)($current['run']['totalLoonheffing'] ?? 0),
			'collectiveTotals' => $stand,
			'correctionLines' => $this->withEmployees($diff['lines']),
			'correctionTree' => $tree,
			'correctionSaldo' => $saldo,
		]);

		if ($this->blocking($findings) === 0 && (string)($correction['correctionRoute'] ?? '') === self::ROUTE_OWN_MESSAGE) {
			$findings = array_merge($findings, $this->ownMessage($current['administration'], $period, $tree, $year, $stored));
		}

		return $this->store($correction, $stored, $findings);
	}//end render()

	/**
	 * The route: the next return inside the tax year, its own message after
	 * it (GS 2.4.1, 2.4.3), and always its own message for a yearly filer
	 * (GS 2.4.2).
	 *
	 * @param string $period  The corrected period.
	 * @param string $tijdvak The filing frequency.
	 *
	 * @return string
	 */
	private function route(string $period, string $tijdvak): string {
		if ($tijdvak === 'jaar' || substr($period, 0, 4) !== $this->today->format('Y')) {
			return self::ROUTE_OWN_MESSAGE;
		}

		return self::ROUTE_NEXT_RETURN;
	}//end route()

	/**
	 * The last stand the Belastingdienst has of the period: the sent return's
	 * relationships and total, with every sent correction of the period
	 * applied in order. Null when the sent return has no humaniq message.
	 *
	 * @param array<string, mixed> $correction The correction.
	 *
	 * @return array{relationships: array<string, array<string, string>>, totTeBet: int}|null
	 */
	private function baseline(array $correction): ?array {
		$original = $this->gateway->findObjectData((string)($correction['corrects'] ?? ''), 'LoonaangifteFiling');
		while ($original !== null && (string)($original['filingType'] ?? '') === 'correctie') {
			$original = $this->gateway->findObjectData((string)($original['corrects'] ?? ''), 'LoonaangifteFiling');
		}

		$xml = (string)($original['messageXml'] ?? '');
		if ($original === null || trim($xml) === '') {
			return null;
		}

		$stand = ['relationships' => CorrectionDiff::fromXml($xml), 'totTeBet' => (int)($original['collectiveTotals']['TotTeBet'] ?? 0)];
		$sent = array_filter(
			$this->correctionsOf((string)($correction['administrationId'] ?? ''), (string)($correction['period'] ?? '')),
			static fn (array $earlier): bool => (string)($earlier['status'] ?? '') === 'verzonden' && (string)($earlier['id'] ?? '') !== (string)($correction['id'] ?? '')
		);
		usort($sent, static fn (array $a, array $b): int => strcmp((string)($a['messageRenderedAt'] ?? ''), (string)($b['messageRenderedAt'] ?? '')));
		foreach ($sent as $earlier) {
			$stand['relationships'] = CorrectionDiff::apply($stand['relationships'], (array)($earlier['correctionTree'] ?? []));
			$stand['totTeBet'] = (int)($earlier['collectiveTotals']['TotTeBet'] ?? $stand['totTeBet']);
		}

		return $stand;
	}//end baseline()

	/**
	 * The correction message of a closed year: only the corrected period.
	 *
	 * @param array<string, mixed>                                   $administration The hrAdministration.
	 * @param string                                                 $period         The period.
	 * @param array<string, mixed>                                   $tree           The TijdvakCorrectie.
	 * @param array{version: string, namespace: string, xsd: string} $year           The year entry.
	 * @param array<string, mixed>                                   $stored         The stored facts, extended with the message.
	 *
	 * @return list<array<string, string>> The findings of the header and the XSD.
	 */
	private function ownMessage(array $administration, string $period, array $tree, array $year, array &$stored): array {
		$built = LoonaangifteMessageBuilder::correctionMessage($administration, $this->messages->header('LC' . $period), [$tree]);
		if ($this->blocking($built['findings']) > 0) {
			return $built['findings'];
		}

		$fileName = 'LHC_' . (string)($administration['loonheffingennummer'] ?? '') . '_' . $period . '_' . gmdate('YmdHis') . '.xml';
		[$message, $findings] = $this->messages->validated($built['tree'], $year, $fileName, $built['findings']);
		if ($message !== null) {
			$stored['messageXml'] = $message['xml'];
			$stored['messageFileName'] = $message['fileName'];
		}

		return $findings;
	}//end ownMessage()

	/**
	 * The open and sent corrections of a period.
	 *
	 * @param string $administrationId The administration.
	 * @param string $period           The period.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function correctionsOf(string $administrationId, string $period): array {
		return $this->gateway->findFiltered('LoonaangifteFiling', ['administrationId' => $administrationId, 'period' => $period, 'filingType' => 'correctie']);
	}//end correctionsOf()

	/**
	 * Name the employee of a withdrawn line from the register, by BSN.
	 *
	 * @param list<array<string, mixed>> $lines The correction lines.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function withEmployees(array $lines): array {
		foreach ($lines as $i => $line) {
			if (($line['employeeId'] ?? '') === '' && ($line['bsn'] ?? '') !== '') {
				$employee = ($this->gateway->findFiltered('Employee', ['bsn' => (string)$line['bsn']])[0] ?? []);
				$lines[$i]['employeeId'] = (string)($employee['id'] ?? '');
			}

			unset($lines[$i]['bsn']);
		}

		return array_values($lines);
	}//end withEmployees()

	/**
	 * The blocking findings of the current lines: a wrong line makes a wrong stand.
	 *
	 * @param list<array{findings: list<array<string, string>>}> $lines The current lines.
	 *
	 * @return list<array<string, string>>
	 */
	private function lineFindings(array $lines): array {
		$findings = [];
		foreach ($lines as $line) {
			$findings = array_merge($findings, $line['findings']);
		}

		return $findings;
	}//end lineFindings()

	/**
	 * Store the outcome on the correction.
	 *
	 * @param array<string, mixed>        $correction The correction.
	 * @param array<string, mixed>        $stored     The facts.
	 * @param list<array<string, string>> $findings   The findings.
	 *
	 * @return array{status: string, filingId: string, blockingFindings: int, warningFindings: int}
	 */
	private function store(array $correction, array $stored, array $findings): array {
		$filingId = (string)($correction['id'] ?? '');
		$blocking = $this->blocking($findings);
		$payload = array_merge($correction, ['messageXml' => null, 'messageFileName' => null], $stored, [
			'messageFindings' => $findings,
			'blockingFindings' => $blocking,
			'warningFindings' => (count($findings) - $blocking),
		]);
		unset($payload['id']);
		$this->gateway->save(payload: $payload, schema: 'LoonaangifteFiling', uuid: ($filingId === '' ? null : $filingId));

		return ['status' => ($blocking === 0 ? 'prepared' : 'blocked'), 'filingId' => $filingId, 'blockingFindings' => $blocking, 'warningFindings' => (count($findings) - $blocking)];
	}//end store()

	/**
	 * A blocking finding about the correction as a whole.
	 *
	 * @param string $kind    The kind.
	 * @param string $problem What is wrong.
	 *
	 * @return array<string, string>
	 */
	private function finding(string $kind, string $problem): array {
		return ['kind' => $kind, 'severity' => 'blocking', 'employeeId' => '', 'element' => 'TijdvakCorrectie', 'problem' => $problem];
	}//end finding()

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

}//end class
