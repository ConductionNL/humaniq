<?php

/**
 * Third-Party Report Service
 *
 * Assembles the yearly report of payments to third parties (UBD, formerly
 * IB47) of one administration (filings-ib47 D2): one melding per payee with
 * the year's payments and expense allowances summed and rounded down to
 * whole euros, dated on the last payment (Handleiding Deel 2, 2.3.4),
 * rendered in the Belastingdienst's format and validated against its XSD,
 * and stored on the year's ThirdPartyReport in concept. What stops the
 * report (a payee without BSN, an administration without a payroll tax
 * number) is stored as a blocking finding; UbdReportReadyGuard then refuses
 * klaarzetten.
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
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Humaniq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Assembles the yearly third-party payments report.
 *
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */
class ThirdPartyReportService {

	/**
	 * The app config key holding the software developer's relation number
	 * with the Belastingdienst (SWOxxxxx), the message's relNr.
	 *
	 * @var string
	 */
	public const RELNR_KEY = 'ubd_relnr';

	/**
	 * The largest amount the format carries (n1..7).
	 *
	 * @var int
	 */
	private const MAX_AMOUNT = 9999999;

	/**
	 * The payee fields the format needs, with the finding each one's absence raises.
	 *
	 * @var array<string, string>
	 */
	private const PAYEE_REQUIRED = [
		'bsn' => 'payee-without-bsn',
		'dateOfBirth' => 'payee-without-date-of-birth',
		'street' => 'payee-without-address',
		'houseNumber' => 'payee-without-address',
		'postcode' => 'payee-without-address',
		'city' => 'payee-without-address',
	];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway    The register plumbing.
	 * @param IAppConfig           $appConfig  The software relation number.
	 * @param LoggerInterface      $logger     The logger.
	 * @param IAppManager|null     $appManager The app version for versieSwPakket; 'dev' without it.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ?IAppManager $appManager = null,
	) {
	}//end __construct()

	/**
	 * Assemble (or assemble again, while in concept) the report of one
	 * administration and year.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $year             The year whose payments are reported.
	 * @param string $userId           The account assembling it.
	 *
	 * @return array{status: string, reportId: string, lineCount?: int, totalAmount?: int, blockingFindings?: int, warningFindings?: int, message?: string}
	 *
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
	 */
	public function assemble(string $administrationId, int $year, string $userId): array {
		$current = $this->reportOf(administrationId: $administrationId, year: $year);
		if ($current !== null && (string)($current['status'] ?? '') !== 'concept') {
			return ['status' => 'refused-not-concept', 'reportId' => (string)$current['id'], 'message' => 'Dit overzicht is al klaargezet of verzonden; heropen het eerst.'];
		}

		$totals = $this->totalsPerPayee(administrationId: $administrationId, year: $year);
		if ($totals === []) {
			return ['status' => 'nothing-to-report', 'reportId' => (string)($current['id'] ?? ''), 'message' => 'Er zijn in ' . $year . ' geen betalingen aan derden geregistreerd.'];
		}

		$administration = ($this->gateway->findFiltered('hrAdministration', ['administrationId' => $administrationId])[0] ?? []);
		$findings = $this->administrationFindings($administration);
		$lines = $this->lines(totals: $totals, administrationId: $administrationId, year: $year, findings: $findings);

		$payload = array_merge(($current ?? []), [
			'administrationId' => $administrationId,
			'year' => $year,
			'status' => 'concept',
			'deadline' => ($year + 1) . '-01-31',
			'lineCount' => count($lines),
			'totalAmount' => array_sum(array_column($lines, 'amount')),
			'assembledAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'assembledBy' => $userId,
		]);
		unset($payload['id'], $payload['messageXml'], $payload['messageFileName'], $payload['leveringsId']);

		if ($this->blocking($findings) === 0) {
			$payload = array_merge($payload, $this->message(administration: $administration, lines: $lines, year: $year, findings: $findings));
		}

		$payload['findings'] = $findings;
		$payload['blockingFindings'] = $this->blocking($findings);
		$payload['warningFindings'] = (count($findings) - $payload['blockingFindings']);

		$saved = $this->gateway->save(payload: $payload, schema: 'ThirdPartyReport', uuid: (isset($current['id']) === true ? (string)$current['id'] : null));

		return [
			'status' => 'assembled',
			'reportId' => (string)$saved->getUuid(),
			'lineCount' => $payload['lineCount'],
			'totalAmount' => $payload['totalAmount'],
			'blockingFindings' => $payload['blockingFindings'],
			'warningFindings' => $payload['warningFindings'],
		];
	}//end assemble()

	/**
	 * The initials as the format takes them: at most six characters, a
	 * longer value cut to five plus a hyphen (Deel 2, 4.5.4).
	 *
	 * @param string $initials The payee's initials.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
	 */
	public static function initials(string $initials): string {
		$initials = trim($initials);
		if (mb_strlen($initials) <= 6) {
			return $initials;
		}

		return mb_substr($initials, 0, 5) . '-';
	}//end initials()

	/**
	 * The administration's report of a year, or null.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $year             The year.
	 *
	 * @return array<string, mixed>|null
	 */
	private function reportOf(string $administrationId, int $year): ?array {
		foreach ($this->gateway->findFiltered('ThirdPartyReport', ['administrationId' => $administrationId]) as $report) {
			if ((int)($report['year'] ?? 0) === $year) {
				return $report;
			}
		}

		return null;
	}//end reportOf()

	/**
	 * The year's payments summed per payee, in cents, with the last
	 * payment date, ordered by each payee's first payment.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $year             The year.
	 *
	 * @return array<string, array{cents: int, first: string, last: string}>
	 */
	private function totalsPerPayee(string $administrationId, int $year): array {
		$totals = [];
		foreach ($this->gateway->findFiltered('ThirdPartyPayment', ['administrationId' => $administrationId]) as $payment) {
			$paidOn = (string)($payment['paidOn'] ?? '');
			$payeeId = (string)($payment['payeeId'] ?? '');
			if ($payeeId === '' || str_starts_with($paidOn, $year . '-') === false) {
				continue;
			}

			$cents = ((int)round((float)($payment['amount'] ?? 0) * 100) + (int)round((float)($payment['expenseAllowance'] ?? 0) * 100));
			$entry = ($totals[$payeeId] ?? ['cents' => 0, 'first' => $paidOn, 'last' => $paidOn]);
			$totals[$payeeId] = ['cents' => ($entry['cents'] + $cents), 'first' => min($entry['first'], $paidOn), 'last' => max($entry['last'], $paidOn)];
		}

		uksort($totals, static fn (string $a, string $b): int => [$totals[$a]['first'], $a] <=> [$totals[$b]['first'], $b]);
		return $totals;
	}//end totalsPerPayee()

	/**
	 * What stops the administration itself from reporting.
	 *
	 * @param array<string, mixed> $administration The hrAdministration.
	 *
	 * @return list<array<string, string>>
	 */
	private function administrationFindings(array $administration): array {
		$findings = [];
		if (preg_match('/^\d{9}L\d{2}$/', (string)($administration['loonheffingennummer'] ?? '')) !== 1) {
			$findings[] = ['kind' => 'administration-without-tax-number', 'severity' => 'blocking', 'message' => 'De administratie heeft geen geldig loonheffingennummer (negen cijfers, L, twee cijfers).'];
		}

		if (trim((string)($administration['postalAddress'] ?? '')) === '') {
			$findings[] = ['kind' => 'administration-without-address', 'severity' => 'blocking', 'message' => 'De administratie heeft geen postadres.'];
		}

		if (trim($this->appConfig->getValueString(Application::APP_ID, self::RELNR_KEY, '')) === '') {
			$findings[] = ['kind' => 'software-relation-number-missing', 'severity' => 'warning', 'message' => 'Het relatienummer van de softwareleverancier (SWO) is niet ingesteld; de Belastingdienst verwacht het in het bericht.'];
		}

		return $findings;
	}//end administrationFindings()

	/**
	 * One line per payee whose total is at least one euro; a payee that
	 * cannot be reported adds a blocking finding instead.
	 *
	 * @param array<string, array{cents: int, first: string, last: string}> $totals           The totals per payee.
	 * @param string                                                         $administrationId The administration.
	 * @param int                                                            $year             The year.
	 * @param list<array<string, string>>                                    $findings         The findings, appended to.
	 *
	 * @return list<array<string, string|int>>
	 */
	private function lines(array $totals, string $administrationId, int $year, array &$findings): array {
		$lines = [];
		foreach ($totals as $payeeId => $total) {
			$amount = intdiv($total['cents'], 100);
			if ($amount < 1) {
				continue;
			}

			$payee = $this->gateway->findObjectData(uuid: (string)$payeeId, schema: 'ThirdPartyPayee');
			$name = trim((string)($payee['initials'] ?? '') . ' ' . (string)($payee['lastName'] ?? ''));
			$missing = $this->missing(payee: ($payee ?? []), amount: $amount);
			foreach ($missing as $kind) {
				$findings[] = ['kind' => $kind, 'severity' => 'blocking', 'payeeId' => (string)$payeeId, 'message' => $this->missingMessage(kind: $kind, name: ($name === '' ? (string)$payeeId : $name))];
			}

			if ($missing !== []) {
				continue;
			}

			$lines[] = [
				'meldingsId' => substr(hash('sha256', $administrationId . '|' . $year . '|' . $payeeId), 0, 32),
				'amount' => $amount,
				'paidOn' => $total['last'],
				'lastName' => (string)$payee['lastName'],
				'prefix' => (string)($payee['prefix'] ?? ''),
				'initials' => self::initials((string)($payee['initials'] ?? '')),
				'dateOfBirth' => (string)$payee['dateOfBirth'],
				'street' => (string)$payee['street'],
				'houseNumber' => (string)$payee['houseNumber'],
				'houseNumberAddition' => (string)($payee['houseNumberAddition'] ?? ''),
				'postcode' => (string)$payee['postcode'],
				'city' => (string)$payee['city'],
				'country' => (string)($payee['country'] ?? 'NL'),
				'bsn' => (string)$payee['bsn'],
			];
		}//end foreach

		return $lines;
	}//end lines()

	/**
	 * The finding kinds a payee raises, without duplicates.
	 *
	 * @param array<string, mixed> $payee  The ThirdPartyPayee ([] when it does not resolve).
	 * @param int                  $amount The payee's reported amount.
	 *
	 * @return list<string>
	 */
	private function missing(array $payee, int $amount): array {
		if ($payee === [] || trim((string)($payee['lastName'] ?? '')) === '' || trim((string)($payee['initials'] ?? '')) === '') {
			return ['payee-not-found'];
		}

		$kinds = [];
		foreach (self::PAYEE_REQUIRED as $field => $kind) {
			if (trim((string)($payee[$field] ?? '')) === '') {
				$kinds[$kind] = true;
			}
		}

		if ($amount > self::MAX_AMOUNT) {
			$kinds['amount-too-large'] = true;
		}

		return array_keys($kinds);
	}//end missing()

	/**
	 * The Dutch message of a payee finding.
	 *
	 * @param string $kind The finding kind.
	 * @param string $name The payee's name.
	 *
	 * @return string
	 */
	private function missingMessage(string $kind, string $name): string {
		return match ($kind) {
			'payee-without-bsn' => $name . ' heeft geen BSN.',
			'payee-without-date-of-birth' => $name . ' heeft geen geboortedatum.',
			'payee-without-address' => $name . ' heeft geen volledig adres (straat, huisnummer, postcode, plaats).',
			'amount-too-large' => 'Het totaal van ' . $name . ' past niet in het formaat (meer dan 9.999.999 euro).',
			default => 'Ontvanger ' . $name . ' is niet gevonden of mist naam of voorletters.',
		};
	}//end missingMessage()

	/**
	 * The rendered, validated message and its delivery fields; a message
	 * that does not validate adds a blocking finding instead.
	 *
	 * @param array<string, mixed>            $administration The hrAdministration.
	 * @param list<array<string, string|int>> $lines          The lines.
	 * @param int                             $year           The year.
	 * @param list<array<string, string>>     $findings       The findings, appended to.
	 *
	 * @return array<string, string>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) UbdMessage is a stateless renderer and validator.
	 */
	private function message(array $administration, array $lines, int $year, array &$findings): array {
		$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam'));
		$leveringsId = 'UBD' . $year . $now->format('YmdHis');
		$taxNumber = (string)$administration['loonheffingennummer'];
		$xml = UbdMessage::render(
			header: [
				'aanmaakmoment' => $now->format('Y-m-d\TH:i:s'),
				'leveringsId' => $leveringsId,
				'relNr' => trim($this->appConfig->getValueString(Application::APP_ID, self::RELNR_KEY, '')),
				'software' => 'humaniq',
				'softwareVersion' => $this->softwareVersion(),
				'name' => mb_substr(trim((string)($administration['name'] ?? '')), 0, 200),
				'loonheffingennummer' => $taxNumber,
				'postalAddress' => mb_substr(trim((string)$administration['postalAddress']), 0, 250),
			],
			lines: $lines
		);

		$errors = UbdMessage::errors($xml);
		if ($errors !== []) {
			$this->logger->warning('ThirdPartyReportService: the UBD message does not validate: ' . implode('; ', $errors));
			$findings[] = ['kind' => 'message-invalid', 'severity' => 'blocking', 'message' => 'Het bericht voldoet niet aan het XSD van de Belastingdienst: ' . implode('; ', $errors)];
			return [];
		}

		return ['messageXml' => $xml, 'messageFileName' => 'UBD_' . $taxNumber . '_' . $leveringsId . '.xml', 'leveringsId' => $leveringsId];
	}//end message()

	/**
	 * The number of blocking findings.
	 *
	 * @param list<array<string, string>> $findings The findings.
	 *
	 * @return int
	 */
	private function blocking(array $findings): int {
		return count(array_filter($findings, static fn (array $f): bool => $f['severity'] === 'blocking'));
	}//end blocking()

	/**
	 * The installed humaniq version, or 'dev'.
	 *
	 * @return string
	 */
	private function softwareVersion(): string {
		$version = ($this->appManager?->getAppVersion(Application::APP_ID) ?? '');
		return $version === '' ? 'dev' : mb_substr($version, 0, 27);
	}//end softwareVersion()

}//end class
