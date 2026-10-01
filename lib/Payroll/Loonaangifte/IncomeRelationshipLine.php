<?php

/**
 * IncomeRelationshipLine
 *
 * One income relationship (inkomstenverhouding) of the wage tax return,
 * made from an employee, their contract, the run's payslip and the engine's
 * recalculation of that payslip. Every element follows the 2026
 * Gegevensspecificaties (GS) as listed in the filings-wage-tax-message
 * design, D5; what cannot be reported without a guess is a finding, never a
 * default.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Loonaangifte
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Loonaangifte;

use OCA\Humaniq\Payroll\CalculationResult;

/**
 * One income relationship: its elements, its amounts in cents, its findings.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 */
final class IncomeRelationshipLine {

	/**
	 * The income codes for which a contract, end reason and contract wage are reported (GS p65, p84-86, p136-137).
	 *
	 * @var list<string>
	 */
	private const EMPLOYMENT_CODES = ['11', '13', '15'];

	/**
	 * The relationship codes that carry the three contract indicators (GS p84-86, 2213-2215).
	 *
	 * @var list<string>
	 */
	private const INDICATOR_KINDS = ['1', '11', '21', '22', '23', '24', '82', '83'];

	/**
	 * The end reasons of GS p64.
	 *
	 * @var list<string>
	 */
	public const END_REASONS = ['01', '03', '04', '05', '06', '20', '21', '30', '32', '33', '34', '40', '41', '50', '51', '90', '91', '92', '99'];

	/**
	 * The amounts of Werknemersgegevens in the XSD's order, all reported in euros with cents.
	 *
	 * @var list<string>
	 */
	public const AMOUNTS = [
		'LnLbPh', 'LnSV', 'PrlnAofAnwLg', 'PrlnAofAnwHg', 'PrlnAofAnwUit', 'PrlnWhkAnw', 'PrlnAwfAnwLg', 'PrlnAwfAnwHg',
		'PrlnAwfAnwHz', 'PrlnAwfAnwUit', 'PrLnUfo', 'LnTabBB', 'VakBsl', 'OpgRchtVakBsl', 'OpnAvwb', 'OpbAvwb', 'LnInGld',
		'WrdLn', 'LnOwrk', 'VerstrAanv', 'IngLbPh', 'PrAofLg', 'PrAofHg', 'PrAofUit', 'OpslWko', 'PrGediffWhk', 'PrAwfLg',
		'PrAwfHg', 'PrAwfHz', 'PrAwfUit', 'PrUFO', 'BijdrZvw', 'WghZvw', 'WrdPrGebrAut', 'WrknBijdrAut', 'Reisk', 'VerrArbKrt',
	];

	/**
	 * The findings of this line.
	 *
	 * @var list<array{kind: string, severity: string, employeeId: string, element: string, problem: string}>
	 */
	private array $findings = [];

	/**
	 * The line's facts.
	 *
	 * @param array<string, mixed>      $employee    The Employee.
	 * @param array<string, mixed>      $contract    The covering EmploymentContract, or empty.
	 * @param array<string, mixed>      $payslip     The run's Payslip.
	 * @param CalculationResult|null    $result      The engine's recalculation, or null when it failed.
	 * @param array{0: string, 1: string} $period    The declaration period's first and last day.
	 */
	private function __construct(
		private readonly array $employee,
		private readonly array $contract,
		private readonly array $payslip,
		private readonly ?CalculationResult $result,
		private readonly array $period,
	) {
	}//end __construct()

	/**
	 * Make the line.
	 *
	 * @param array<string, mixed>        $employee The Employee (with its id).
	 * @param array<string, mixed>        $contract The covering EmploymentContract, or empty.
	 * @param array<string, mixed>        $payslip  The run's Payslip.
	 * @param CalculationResult|null      $result   The engine's recalculation, or null when it failed.
	 * @param array{0: string, 1: string} $period   The declaration period's first and last day.
	 *
	 * @return array{tree: array<string, mixed>, cents: array<string, int>, findings: list<array{kind: string, severity: string, employeeId: string, element: string, problem: string}>}
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public static function make(array $employee, array $contract, array $payslip, ?CalculationResult $result, array $period): array {
		$line = new self($employee, $contract, $payslip, $result, $period);
		$cents = $line->cents();
		$tree = $line->tree($cents);

		return ['tree' => $tree, 'cents' => $cents, 'findings' => $line->findings];
	}//end make()

	/**
	 * Whether a nine-digit number passes the elfproef of GS p34 (0014.1) and p66 (0045).
	 *
	 * @param string $number The number.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public static function elfproef(string $number): bool {
		if (preg_match('/^\d{9}$/', $number) !== 1 || substr($number, 0, 3) === '000') {
			return false;
		}

		$sum = 0;
		for ($i = 0; $i < 8; $i++) {
			$sum += ((9 - $i) * (int)$number[$i]);
		}

		return ($sum % 11) === (int)$number[8];
	}//end elfproef()

	/**
	 * The element tree of InkomstenverhoudingInitieel.
	 *
	 * @param array<string, int> $cents The amounts.
	 *
	 * @return array<string, mixed>
	 */
	private function tree(array $cents): array {
		$code = $this->incomeCode();
		$end = $this->endDate();
		$start = $this->startDate();

		return [
			'NumIV' => (string)max(0, (int)($this->employee['incomeRelationshipNumber'] ?? 1)),
			'DatAanv' => $start,
			'DatEind' => $end,
			'CdRdnEindArbov' => $this->endReason($code, $end),
			'PersNr' => $this->filled('employeeNumber'),
			'NatuurlijkPersoon' => $this->person(),
			'Inkomstenperiode' => [$this->incomePeriod($code, $start)],
			'Werknemersgegevens' => $this->employeeData($code, $cents),
		];
	}//end tree()

	/**
	 * The person and, when complete, the Dutch address.
	 *
	 * @return array<string, mixed>
	 */
	private function person(): array {
		$bsn = $this->filled('bsn');
		if ($bsn === null && $this->anonymous() === false) {
			$this->find('employee-without-bsn', 'SofiNr', $this->name() . ' heeft geen burgerservicenummer; zonder BSN mag alleen het anoniementarief (tabel 940) worden aangegeven.');
		}

		if ($bsn !== null && self::elfproef(str_pad($bsn, 9, '0', STR_PAD_LEFT)) === false) {
			$this->find('employee-bsn-invalid', 'SofiNr', 'Het burgerservicenummer van ' . $this->name() . ' voldoet niet aan de elfproef.');
		}

		if ($bsn !== null && $this->filled('lastName') === null) {
			$this->find('employee-without-last-name', 'SignNm', $this->name() . ' heeft geen achternaam.');
		}

		if ($bsn !== null && $this->filled('dateOfBirth') === null) {
			$this->find('employee-without-date-of-birth', 'Gebdat', $this->name() . ' heeft geen geboortedatum.');
		}

		return [
			'SofiNr' => ($bsn === null ? null : str_pad($bsn, 9, '0', STR_PAD_LEFT)),
			'Voorl' => $this->initials(),
			'SignNm' => $this->filled('lastName'),
			'Gebdat' => $this->filled('dateOfBirth'),
			'AdresBinnenland' => $this->address(),
		];
	}//end person()

	/**
	 * The Dutch address when every required part is there; otherwise omitted (it is optional).
	 *
	 * @return array<string, string|null>|null
	 */
	private function address(): ?array {
		$postcode = strtoupper(str_replace(' ', '', (string)($this->employee['postcode'] ?? '')));
		$street = $this->filled('straat');
		$place = $this->filled('woonplaats');
		$country = strtoupper((string)($this->employee['land'] ?? 'NL'));
		if ($street === null || $place === null || preg_match('/^[1-9]\d{3}[A-Z]{2}$/', $postcode) !== 1 || in_array($country, ['', 'NL', 'NEDERLAND'], true) === false) {
			return null;
		}

		preg_match('/^\s*(\d{1,5})\s*[-\s]?\s*(.{0,4})/', (string)($this->employee['huisnummer'] ?? ''), $number);
		$houseNumber = ((int)($number[1] ?? 0) > 0) ? (string)(int)$number[1] : null;
		$addition = trim((string)($number[2] ?? ''));

		return [
			'Str' => mb_substr($street, 0, 24),
			'HuisNr' => $houseNumber,
			'HuisNrToev' => ($houseNumber !== null && $addition !== '') ? $addition : null,
			'Pc' => $postcode,
			'Woonpl' => mb_substr($place, 0, 24),
		];
	}//end address()

	/**
	 * The income period: codes and indicators (GS p77-99).
	 *
	 * @param string $code  The income code.
	 * @param string $start The start of the income relationship.
	 *
	 * @return array<string, string|null>
	 */
	private function incomePeriod(string $code, string $start): array {
		$kind = $this->relationshipKind($code);
		$indicators = (in_array($code, self::EMPLOYMENT_CODES, true) === true && in_array((string)$kind, self::INDICATOR_KINDS, true) === true);
		$insured = ($code !== '17' && $this->insured() === true) ? 'J' : 'N';
		$type = (string)($this->contract['type'] ?? '');

		return [
			'DatAanv' => max($start, $this->period[0]),
			'SrtIV' => $code,
			'CdAard' => $kind,
			'IndArbovOnbepTd' => $indicators === true ? $this->yesNo(trim((string)($this->contract['endDate'] ?? '')) === '') : null,
			'IndSchriftArbov' => $indicators === true ? $this->yesNo(($this->contract['writtenContract'] ?? false) === true) : null,
			'IndOprov' => $indicators === true ? $this->yesNo($type === 'oproep' || $this->contractHours() === 0.0) : null,
			'IndLhKort' => $this->yesNo((($this->snapshot()['loonheffingskortingToegepast'] ?? false) === true) && $this->anonymous() === false),
			'LbTab' => $this->table(),
			'IndWAO' => $insured,
			'IndWW' => $insured,
			'IndZW' => $insured,
			'CdZvw' => ($this->withheldZvw() === true ? 'M' : 'K'),
		];
	}//end incomePeriod()

	/**
	 * Werknemersgegevens: the amounts, hours and contract wage.
	 *
	 * @param string             $code  The income code.
	 * @param array<string, int> $cents The amounts.
	 *
	 * @return array<string, string|null>
	 */
	private function employeeData(string $code, array $cents): array {
		$data = [];
		foreach (self::AMOUNTS as $element) {
			$data[$element] = $this->euros($cents[$element] ?? 0);
		}

		$contracted = in_array($code, ['11', '13', '15', '17'], true);
		$data['AantVerlU'] = (string)$this->paidHours();
		$data['Ctrctln'] = $contracted === true ? $this->euros($this->contractWageCents()) : null;
		$data['AantCtrcturenPWk'] = $contracted === true ? $this->hours($this->contractHours()) : null;
		$data['BedrRntKstvPersl'] = '0.00';

		return $data;
	}//end employeeData()

	/**
	 * The amounts in cents (design D5): the payslip for what was withheld and
	 * paid, the recalculation for the premium components and their bases.
	 *
	 * @return array<string, int>
	 */
	private function cents(): array {
		$this->checkReproduced();
		$this->checkUnsupported();
		$result = $this->result;
		$insured = ($this->incomeCode() !== '17' && $this->insured() === true);
		$taxable = ($result?->taxableWageCents ?? 0);
		$base = ($insured === true ? ($result?->premiumWageCents ?? 0) : 0);
		$aofLow = (($this->snapshot()['aofTariff'] ?? 'laag') !== 'hoog');
		$awfLow = ((string)($this->payslip['awfTariff'] ?? $this->snapshot()['awfTariff'] ?? 'low') !== 'high');
		$zvw = $this->money('zvw');

		return [
			'LnLbPh' => $taxable,
			'LnSV' => ($insured === true ? $taxable : 0),
			'PrlnAofAnwLg' => ($aofLow === true ? $base : 0),
			'PrlnAofAnwHg' => ($aofLow === true ? 0 : $base),
			'PrlnWhkAnw' => $base,
			'PrlnAwfAnwLg' => ($awfLow === true ? $base : 0),
			'PrlnAwfAnwHg' => ($awfLow === true ? 0 : $base),
			'OpgRchtVakBsl' => $this->money('vakantiegeldReserved'),
			'LnInGld' => $this->money('grossPay'),
			'LnOwrk' => $this->money('overtimePay'),
			'IngLbPh' => $this->money('loonheffing'),
			'PrAofLg' => ($aofLow === true ? ($result?->aofCents ?? 0) : 0),
			'PrAofHg' => ($aofLow === true ? 0 : ($result?->aofCents ?? 0)),
			'OpslWko' => ($result?->wkoCents ?? 0),
			'PrGediffWhk' => ($result?->whkCents ?? 0),
			'PrAwfLg' => ($awfLow === true ? ($result?->awfCents ?? 0) : 0),
			'PrAwfHg' => ($awfLow === true ? 0 : ($result?->awfCents ?? 0)),
			'BijdrZvw' => ($this->withheldZvw() === true ? $zvw : 0),
			'WghZvw' => ($this->withheldZvw() === true ? 0 : $zvw),
			'VerrArbKrt' => $this->money('arbeidskorting'),
		];
	}//end cents()

	/**
	 * The message must report what was paid: a payslip the engine no longer
	 * reproduces is refused (design D5).
	 *
	 * @return void
	 */
	private function checkReproduced(): void {
		if ($this->result === null) {
			$this->find('payslip-not-reproducible', 'IngLbPh', 'De loonstrook van ' . $this->name() . ' kon niet opnieuw worden berekend uit de vastgelegde invoer; bereken de loonrun opnieuw.');
			return;
		}

		if ($this->result->loonheffingCents !== $this->money('loonheffing')) {
			$this->find('payslip-not-reproducible', 'IngLbPh', 'De ingehouden loonheffing op de loonstrook van ' . $this->name() . ' wijkt af van de herberekening; bereken de loonrun opnieuw.');
		}
	}//end checkReproduced()

	/**
	 * What the message cannot report without a guess yet.
	 *
	 * @return void
	 */
	private function checkUnsupported(): void {
		if ($this->money('bijtelling') > 0) {
			$this->find('company-car-not-supported', 'WrdPrGebrAut', 'Voor ' . $this->name() . ' is een bijtelling auto verloond; de waarde vóór eigen bijdrage is niet vastgelegd, dus het bericht kan dit nog niet aangeven.');
		}

		if ($this->money('retroAdjustment') !== 0) {
			$this->find('retro-adjustment-elsewhere', 'IngLbPh', 'De loonstrook van ' . $this->name() . ' verrekent een correctie over een eerder tijdvak; die hoort in een correctiebericht.', 'warning');
		}

		if ($this->filled('publicSectorRegime') !== null) {
			$this->find('public-sector-ufo', 'PrUFO', $this->name() . ' is overheidspersoneel; de Ufo-premie wordt door de loonrun niet berekend, controleer de AWf- en Ufo-premie.', 'warning');
		}
	}//end checkUnsupported()

	/**
	 * The income code (GS p78).
	 *
	 * @return string
	 */
	private function incomeCode(): string {
		if (($this->employee['isDga'] ?? false) === true && $this->insured() === false) {
			return '17';
		}

		return $this->filled('publicSectorRegime') !== null ? '11' : '15';
	}//end incomeCode()

	/**
	 * The relationship kind (GS p79-80); not sent with income code 17 (2218).
	 *
	 * @param string $code The income code.
	 *
	 * @return string|null
	 */
	private function relationshipKind(string $code): ?string {
		if ($code === '17') {
			return null;
		}

		$type = (string)($this->contract['type'] ?? '');
		$kinds = ['bbl' => '83', 'agency' => '11'];
		if (($this->employee['publicSectorRegime'] ?? null) === 'ambtenarenwet') {
			return '18';
		}

		return ($kinds[$type] ?? '1');
	}//end relationshipKind()

	/**
	 * The tax table code (GS p93-94): 940 for the anonymous rate, else
	 * 0 + colour + wage period.
	 *
	 * @return string
	 */
	private function table(): string {
		if ($this->anonymous() === true) {
			return '940';
		}

		$colour = (($this->snapshot()['taxTableColor'] ?? 'wit') === 'groen') ? '2' : '1';
		$wagePeriod = (preg_match('/-P\d{2}$/', (string)($this->payslip['period'] ?? '')) === 1) ? '4' : '2';

		return '0' . $colour . $wagePeriod;
	}//end table()

	/**
	 * The end date, only once it falls on or before the period end (GS p62, 0040).
	 *
	 * @return string|null
	 */
	private function endDate(): ?string {
		$end = $this->filled('endDate');
		return ($end !== null && $end <= $this->period[1]) ? $end : null;
	}//end endDate()

	/**
	 * The end reason, required for an ended 11/13/15 relationship (GS p65, 2501).
	 *
	 * @param string      $code The income code.
	 * @param string|null $end  The reported end date.
	 *
	 * @return string|null
	 */
	private function endReason(string $code, ?string $end): ?string {
		if ($end === null || in_array($code, self::EMPLOYMENT_CODES, true) === false) {
			return null;
		}

		$reason = (string)($this->employee['endReason'] ?? '');
		if (in_array($reason, self::END_REASONS, true) === false) {
			$this->find('employment-end-without-reason', 'CdRdnEindArbov', 'Het dienstverband van ' . $this->name() . ' eindigt op ' . $end . ' zonder reden van einde arbeidsverhouding.');
			return null;
		}

		return $reason;
	}//end endReason()

	/**
	 * The start of the income relationship (GS p60).
	 *
	 * @return string
	 */
	private function startDate(): string {
		$start = $this->filled('startDate');
		if ($start === null) {
			$this->find('employee-without-start-date', 'DatAanv', $this->name() . ' heeft geen datum in dienst.');
			return $this->period[0];
		}

		return $start;
	}//end startDate()

	/**
	 * The paid hours, half an hour or more rounding up (GS p135-136).
	 *
	 * @return int
	 */
	private function paidHours(): int {
		foreach (['hoursPaid', 'hoursWorked'] as $field) {
			if (is_numeric($this->payslip[$field] ?? null) === true && (float)$this->payslip[$field] > 0.0) {
				return (int)round((float)$this->payslip[$field], 0, PHP_ROUND_HALF_UP);
			}
		}

		$weeks = (preg_match('/-P\d{2}$/', (string)($this->payslip['period'] ?? '')) === 1) ? 4.0 : (52 / 12);
		return (int)round($this->contractHours() * $weeks, 0, PHP_ROUND_HALF_UP);
	}//end paidHours()

	/**
	 * The contracted hours per week.
	 *
	 * @return float
	 */
	private function contractHours(): float {
		return is_numeric($this->contract['hoursPerWeek'] ?? null) === true ? (float)$this->contract['hoursPerWeek'] : 0.0;
	}//end contractHours()

	/**
	 * The contract wage (GS p136): the agreed monthly salary, else the hourly
	 * wage times the contracted hours of a month.
	 *
	 * @return int
	 */
	private function contractWageCents(): int {
		if (is_numeric($this->employee['grossMonthlySalary'] ?? null) === true && (float)$this->employee['grossMonthlySalary'] > 0.0) {
			return (int)round((float)$this->employee['grossMonthlySalary'] * 100);
		}

		$hourly = is_numeric($this->contract['hourlyWage'] ?? null) === true ? (float)$this->contract['hourlyWage'] : 0.0;
		return (int)round($hourly * $this->contractHours() * 52 / 12 * 100);
	}//end contractWageCents()

	/**
	 * The initials of the given names, at most six (GS p67).
	 *
	 * @return string|null
	 */
	private function initials(): ?string {
		$initials = '';
		foreach (preg_split('/[\s.\-]+/u', (string)($this->employee['firstName'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $name) {
			$initials .= mb_strtoupper(mb_substr($name, 0, 1));
		}

		return $initials === '' ? null : mb_substr($initials, 0, 6);
	}//end initials()

	/**
	 * The engine input of the payslip.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(): array {
		return is_array($this->payslip['engineInputSnapshot'] ?? null) === true ? $this->payslip['engineInputSnapshot'] : [];
	}//end snapshot()

	/**
	 * Whether the employee is insured for the employee insurances.
	 *
	 * @return bool
	 */
	private function insured(): bool {
		return (($this->snapshot()['verzekeringsplichtig'] ?? true) !== false);
	}//end insured()

	/**
	 * Whether the anonymous rate was applied.
	 *
	 * @return bool
	 */
	private function anonymous(): bool {
		return (($this->payslip['anoniementariefApplied'] ?? false) === true);
	}//end anonymous()

	/**
	 * Whether the Zvw contribution was withheld rather than levied on the employer.
	 *
	 * @return bool
	 */
	private function withheldZvw(): bool {
		return (($this->payslip['zvwMode'] ?? '') === 'inhouding');
	}//end withheldZvw()

	/**
	 * A payslip amount in cents.
	 *
	 * @param string $field The field.
	 *
	 * @return int
	 */
	private function money(string $field): int {
		return is_numeric($this->payslip[$field] ?? null) === true ? (int)round((float)$this->payslip[$field] * 100) : 0;
	}//end money()

	/**
	 * A filled employee string, trimmed, or null.
	 *
	 * @param string $field The field.
	 *
	 * @return string|null
	 */
	private function filled(string $field): ?string {
		$value = trim((string)($this->employee[$field] ?? ''));
		return $value === '' ? null : $value;
	}//end filled()

	/**
	 * The employee's display name.
	 *
	 * @return string
	 */
	private function name(): string {
		$name = trim(((string)($this->employee['firstName'] ?? '')) . ' ' . ((string)($this->employee['lastName'] ?? '')));
		return $name === '' ? (string)($this->employee['id'] ?? 'Een medewerker') : $name;
	}//end name()

	/**
	 * Record a finding.
	 *
	 * @param string $kind     The finding kind.
	 * @param string $element  The message element it concerns.
	 * @param string $problem  What is wrong, for the payroll officer.
	 * @param string $severity Blocking or warning.
	 *
	 * @return void
	 */
	private function find(string $kind, string $element, string $problem, string $severity='blocking'): void {
		$this->findings[] = ['kind' => $kind, 'severity' => $severity, 'employeeId' => (string)($this->employee['id'] ?? ''), 'element' => $element, 'problem' => $problem];
	}//end find()

	/**
	 * J or N.
	 *
	 * @param bool $yes The truth.
	 *
	 * @return string
	 */
	private function yesNo(bool $yes): string {
		return $yes === true ? 'J' : 'N';
	}//end yesNo()

	/**
	 * Cents as euros with two decimals.
	 *
	 * @param int $cents The amount.
	 *
	 * @return string
	 */
	private function euros(int $cents): string {
		return number_format($cents / 100, 2, '.', '');
	}//end euros()

	/**
	 * Hours per week in the Aant format (GS p137): up to two decimals.
	 *
	 * @param float $hours The hours.
	 *
	 * @return string
	 */
	private function hours(float $hours): string {
		$text = rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
		return $text === '' ? '0' : $text;
	}//end hours()

}//end class
