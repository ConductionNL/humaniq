<?php

/**
 * Humaniq TrainingCompetenceWriter
 *
 * Writes the competence a followed training grants (talent-training-and-lms
 * D2): a TrainingRecord that is `gevolgd` with a `competenceCode` creates the
 * employee's EmployeeCompetence for that code, or extends the one they hold
 * when the training's validity runs later. It never shortens or deletes a
 * competence, so a training followed early cannot cut short a certificate
 * that already runs longer.
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
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Grants or extends the competence of a followed training.
 *
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-001
 */
class TrainingCompetenceWriter {

	/**
	 * The competence schema.
	 *
	 * @var string
	 */
	public const COMPETENCE_SCHEMA = 'EmployeeCompetence';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Reads and writes humaniq's register.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
	) {

	}//end __construct()

	/**
	 * Grant or extend the competence of one training record.
	 *
	 * @param array<string, mixed> $record The record as saved.
	 *
	 * @return string|null The id of the competence written, or null when nothing was written.
	 *
	 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-001
	 */
	public function apply(array $record): ?string {
		$employeeId = trim((string)($record['employeeId'] ?? ''));
		$code = trim((string)($record['competenceCode'] ?? ''));
		if (($record['status'] ?? null) !== 'gevolgd' || $employeeId === '' || $code === '') {
			return null;
		}

		$validUntil = $this->textOrNull($record['validUntil'] ?? null);
		$held = $this->gateway->findFiltered(self::COMPETENCE_SCHEMA, ['employeeId' => $employeeId, 'competenceCode' => $code]);
		if ($held === []) {
			$payload = [
				'employeeId' => $employeeId,
				'competenceCode' => $code,
				'label' => $this->textOrNull($record['title'] ?? null) ?? $code,
				'issuedOn' => $this->textOrNull($record['completedOn'] ?? null) ?? $this->textOrNull($record['plannedOn'] ?? null),
				'validUntil' => $validUntil,
			];

			return (string)$this->gateway->save($payload, self::COMPETENCE_SCHEMA)->getUuid();
		}

		$current = $this->longestRunning($held);
		if ($this->runsLonger(candidate: $validUntil, current: $this->textOrNull($current['validUntil'] ?? null)) === false) {
			return null;
		}

		$competenceId = (string)($current['id'] ?? '');
		$this->gateway->save(array_merge($this->withoutId($current), ['validUntil' => $validUntil]), self::COMPETENCE_SCHEMA, $competenceId);

		return $competenceId;
	}//end apply()

	/**
	 * The held competence that runs longest; one without an end runs longest.
	 *
	 * @param list<array<string, mixed>> $held The competences for one code.
	 *
	 * @return array<string, mixed>
	 */
	private function longestRunning(array $held): array {
		$best = $held[0];
		foreach ($held as $row) {
			if ($this->runsLonger(candidate: $this->textOrNull($row['validUntil'] ?? null), current: $this->textOrNull($best['validUntil'] ?? null)) === true) {
				$best = $row;
			}
		}

		return $best;
	}//end longestRunning()

	/**
	 * Whether a validity runs later than the current one. Null means no end.
	 *
	 * @param string|null $candidate The new last day.
	 * @param string|null $current   The held last day.
	 *
	 * @return bool
	 */
	private function runsLonger(?string $candidate, ?string $current): bool {
		if ($current === null) {
			return false;
		}

		return $candidate === null || $candidate > $current;
	}//end runsLonger()

	/**
	 * The row without its id, which is passed separately on a save.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutId(array $row): array {
		unset($row['id'], $row['@self']);

		return $row;
	}//end withoutId()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null
	 */
	private function textOrNull(mixed $value): ?string {
		$trimmed = trim((string)($value ?? ''));

		return $trimmed === '' ? null : $trimmed;
	}//end textOrNull()

}//end class
