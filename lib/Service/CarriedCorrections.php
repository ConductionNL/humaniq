<?php

/**
 * CarriedCorrections
 *
 * The corrections of earlier periods of a tax year that travel with the
 * next regular wage tax return (Gegevensspecificaties 2026, 2.4.1), and
 * the record of which return carried them.
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
 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Which corrections a return carries.
 *
 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
 */
class CarriedCorrections {

	/**
	 * The corrections.
	 *
	 * @param HoursRegisterGateway $gateway Register reads and writes.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
	) {
	}//end __construct()

	/**
	 * The corrections that travel with this return (GS 2.4.1): made ready,
	 * for an earlier period of the same year, routed to the next return, not
	 * carried by another return. At most 13 (XSD).
	 *
	 * @param array<string, mixed> $filing The regular filing.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
	 */
	public function forReturn(array $filing): array {
		$carried = [];
		foreach ($this->gateway->findFiltered('LoonaangifteFiling', ['administrationId' => (string)($filing['administrationId'] ?? ''), 'filingType' => 'correctie']) as $correction) {
			if ($this->travelsWith($correction, $filing) === true) {
				$carried[] = $correction;
			}
		}

		usort($carried, static fn (array $one, array $two): int => strcmp((string)$one['period'], (string)$two['period']));
		return array_slice($carried, 0, 13);
	}//end forReturn()

	/**
	 * Whether a correction travels with a regular return.
	 *
	 * @param array<string, mixed> $correction The correction.
	 * @param array<string, mixed> $filing     The regular filing.
	 *
	 * @return bool
	 */
	private function travelsWith(array $correction, array $filing): bool {
		$period = (string)$filing['period'];
		$other = (string)($correction['period'] ?? '');
		$carrier = (string)($correction['carriedBy'] ?? '');

		return (string)($correction['correctionRoute'] ?? '') === 'volgende-aangifte'
			&& in_array((string)($correction['status'] ?? ''), ['klaargezet', 'bevestigd', 'verzonden'], true) === true
			&& substr($other, 0, 4) === substr($period, 0, 4) && $other < $period
			&& ($carrier === '' || $carrier === (string)($filing['id'] ?? ''))
			&& is_array($correction['correctionTree'] ?? null) === true;
	}//end travelsWith()

	/**
	 * Record which return carries a correction.
	 *
	 * @param array<string, mixed> $correction The correction.
	 * @param string               $filingId   The regular filing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
	 */
	public function stamp(array $correction, string $filingId): void {
		if ($filingId === '' || (string)($correction['carriedBy'] ?? '') === $filingId) {
			return;
		}

		$correctionId = (string)$correction['id'];
		unset($correction['id']);
		$this->gateway->save(payload: array_merge($correction, ['carriedBy' => $filingId]), schema: 'LoonaangifteFiling', uuid: $correctionId);
	}//end stamp()

}//end class
