<?php

/**
 * Humaniq RightToWorkService
 *
 * Decides a new hire's right to work in the Netherlands by a stated rule
 * (people-dossier-completeness D4), and verifies the ICAO 9303 check digits
 * of a machine-readable zone when one is given.
 *
 * The rule, in order:
 * 1. no document, or a document that expires before the start date: mislukt;
 * 2. an EU, EEA or Swiss nationality (lib/Standards/reference/eea-nationalities.json): geslaagd;
 * 3. a residence document whose endorsement allows work freely: geslaagd;
 * 4. a work permit (TWV) valid on the start date: geslaagd;
 * 5. anything else: mislukt, no permission to work.
 *
 * Pure: no register access, no clock. The caller passes the start date and
 * stores the answer. No document number leaves this class.
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
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Applies the right-to-work rule to one document.
 *
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-003
 */
class RightToWorkService {

	public const RESULT_PASS = 'geslaagd';

	public const RESULT_FAIL = 'mislukt';

	/**
	 * Endorsement phrases on a Dutch residence document that allow work
	 * without a work permit.
	 */
	private const FREE_WORK_PHRASES = ['arbeid vrij toegestaan', 'twv niet vereist'];

	/**
	 * Constructor.
	 *
	 * @param MachineReadableZone $zone Reads a pasted zone and checks its digits.
	 */
	public function __construct(
		private readonly MachineReadableZone $zone = new MachineReadableZone(),
	) {

	}//end __construct()

	/**
	 * The EEA table, loaded once.
	 *
	 * @var array{codes: array<int, string>, aliases: array<string, string>}|null
	 */
	private ?array $eea = null;

	/**
	 * Decide one right-to-work check.
	 *
	 * @param array<string, mixed> $input startDate, documentType, nationality, documentExpiry,
	 *                                    endorsement, twvValidUntil, and optionally mrz (lines, as
	 *                                    an array or one newline-separated string).
	 *
	 * @return array<string, mixed> result, reasonCode, reason, method and the fields the decision used.
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-003
	 */
	public function decide(array $input): array {
		$facts = [
			'documentType' => $this->text(value: ($input['documentType'] ?? null)),
			'nationality' => strtoupper($this->text(value: ($input['nationality'] ?? null))),
			'documentExpiry' => $this->text(value: ($input['documentExpiry'] ?? null)),
			'endorsement' => $this->text(value: ($input['endorsement'] ?? null)),
			'twvValidUntil' => $this->text(value: ($input['twvValidUntil'] ?? null)),
			'method' => 'handmatig',
		];

		$lines = $this->mrzLines(value: ($input['mrz'] ?? null));
		if ($lines !== []) {
			$facts['method'] = 'extractie';
			$zone = $this->zone->read(lines: $lines);
			if ($zone['error'] !== null) {
				return $this->answer(facts: $facts, result: self::RESULT_FAIL, code: 'controlecijfer-onjuist', reason: $zone['error']);
			}

			$facts['documentType'] = $zone['documentType'];
			$facts['nationality'] = $zone['nationality'];
			$facts['documentExpiry'] = $zone['documentExpiry'];
		}

		$facts['nationality'] = $this->canonicalNationality(code: $facts['nationality']);
		$start = $this->date(value: ($input['startDate'] ?? null)) ?? new DateTimeImmutable('today');

		return $this->apply(facts: $facts, start: $start);
	}//end decide()

	/**
	 * Apply steps 1 to 5 to the facts.
	 *
	 * @param array<string, string> $facts The document facts.
	 * @param DateTimeImmutable $start The start date.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function apply(array $facts, DateTimeImmutable $start): array {
		$expiry = $this->date(value: $facts['documentExpiry']);
		if ($facts['documentType'] === '' || $facts['documentType'] === 'geen' || $expiry === null) {
			return $this->answer(facts: $facts, result: self::RESULT_FAIL, code: 'geen-document', reason: 'No identity document with an expiry date was recorded.');
		}

		if ($expiry < $start) {
			return $this->answer(
				facts: $facts,
				result: self::RESULT_FAIL,
				code: 'document-verlopen',
				reason: sprintf('The document expires on %s, before the start date %s.', $expiry->format('Y-m-d'), $start->format('Y-m-d'))
			);
		}

		if (in_array($facts['nationality'], $this->eeaTable()['codes'], true) === true) {
			return $this->answer(facts: $facts, result: self::RESULT_PASS, code: 'eer-onderdaan', reason: 'EU, EEA or Swiss national with a valid document.');
		}

		if ($this->allowsFreeWork(endorsement: $facts['endorsement']) === true) {
			return $this->answer(facts: $facts, result: self::RESULT_PASS, code: 'verblijf-arbeid-vrij', reason: 'The residence document allows work.');
		}

		$twv = $this->date(value: $facts['twvValidUntil']);
		if ($twv !== null && $twv >= $start) {
			return $this->answer(facts: $facts, result: self::RESULT_PASS, code: 'twv-geldig', reason: 'A work permit covers the start date.');
		}

		return $this->answer(
			facts: $facts,
			result: self::RESULT_FAIL,
			code: 'geen-arbeidstoestemming',
			reason: 'No permission to work: the residence document does not allow work and there is no work permit covering the start date.'
		);
	}//end apply()

	/**
	 * Whether the endorsement allows work without a work permit.
	 *
	 * @param string $endorsement The endorsement text.
	 *
	 * @return bool
	 */
	private function allowsFreeWork(string $endorsement): bool {
		$text = strtolower($endorsement);
		foreach (self::FREE_WORK_PHRASES as $phrase) {
			if (str_contains($text, $phrase) === true) {
				return true;
			}
		}

		return false;
	}//end allowsFreeWork()

	/**
	 * Map a zone alias (D for Germany) to its ISO code.
	 *
	 * @param string $code The nationality as given.
	 *
	 * @return string The canonical code.
	 */
	private function canonicalNationality(string $code): string {
		return ($this->eeaTable()['aliases'][$code] ?? $code);
	}//end canonicalNationality()

	/**
	 * The EEA table from lib/Standards/reference.
	 *
	 * @return array{codes: array<int, string>, aliases: array<string, string>}
	 */
	private function eeaTable(): array {
		if ($this->eea === null) {
			$raw = json_decode((string)file_get_contents(__DIR__ . '/../Standards/reference/eea-nationalities.json'), true);
			$this->eea = [
				'codes' => array_values(array_map('strval', (array)($raw['codes'] ?? []))),
				'aliases' => array_map('strval', (array)($raw['aliases'] ?? [])),
			];
		}

		return $this->eea;
	}//end eeaTable()

	/**
	 * The zone lines, trimmed, from an array or a newline-separated string.
	 *
	 * @param mixed $value The mrz input.
	 *
	 * @return array<int, string>
	 */
	private function mrzLines(mixed $value): array {
		if (is_string($value) === true) {
			$value = preg_split('/\R/', $value);
		}

		if (is_array($value) === false) {
			return [];
		}

		$lines = [];
		foreach ($value as $line) {
			$line = strtoupper(str_replace(' ', '', trim((string)$line)));
			if ($line !== '') {
				$lines[] = $line;
			}
		}

		return $lines;
	}//end mrzLines()

	/**
	 * A trimmed string, '' for null or a non-scalar.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()

	/**
	 * A Y-m-d date, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function date(mixed $value): ?DateTimeImmutable {
		$text = $this->text(value: $value);
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $parts) !== 1 || checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false) {
			return null;
		}

		return new DateTimeImmutable(substr($text, 0, 10));
	}//end date()

	/**
	 * Shape the answer.
	 *
	 * @param array<string, string> $facts The facts.
	 * @param string $result geslaagd or mislukt.
	 * @param string $code The reason code.
	 * @param string $reason The reason sentence.
	 *
	 * @return array<string, mixed>
	 */
	private function answer(array $facts, string $result, string $code, string $reason): array {
		return array_merge($facts, ['result' => $result, 'reasonCode' => $code, 'reason' => $reason]);
	}//end answer()

}//end class
