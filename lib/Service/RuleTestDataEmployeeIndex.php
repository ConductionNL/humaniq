<?php

/**
 * Rule Test Data Employee Index
 *
 * Resolves the synthetic `employeeNumber` placeholders the rule-engine seed
 * samples carry (e.g. 'EMP-NL-0001') to the real UUIDs OpenRegister generated
 * for the seeded Employees. Every schema typing `employeeId` also requires
 * `format: 'uuid'`, so writing the natural key literally always fails create;
 * this index is what makes those samples writable.
 *
 * Split out of `RuleTestDataSeeder` because that class had reached its PHPMD
 * `ExcessiveClassComplexity` budget, and this pair of methods is the part that
 * is genuinely separable: building the map and substituting into a sample is
 * one cohesive job with a single input (the ObjectService) and no overlap with
 * the seeder's upsert/create logic.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use Psr\Log\LoggerInterface;

/**
 * employeeNumber => uuid index for the rule-engine seed samples.
 */
class RuleTestDataEmployeeIndex {

	/**
	 * Max Employee rows loaded when building the index.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Map every seeded Employee's `employeeNumber` to its real generated UUID.
	 *
	 * @param mixed $objectService The ObjectService.
	 * @param string $register Register slug.
	 *
	 * @return array<string, string> employeeNumber => uuid.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
	 */
	public function byNumber(mixed $objectService, string $register): array {
		try {
			$rows = $objectService->setRegister($register)->setSchema('Employee')->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('RuleTestDataEmployeeIndex: cannot load Employee for employeeId resolution: ' . $e->getMessage());
			return [];
		}

		$map = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$obj = is_array($row) === true ? $row : $row->jsonSerialize();
			$employeeNumber = trim((string)($obj['employeeNumber'] ?? ''));
			$uuid = (string)($obj['id'] ?? $obj['@self']['id'] ?? '');
			if ($employeeNumber !== '' && $uuid !== '') {
				$map[$employeeNumber] = $uuid;
			}
		}

		return $map;
	}//end byNumber()

	/**
	 * Substitute a sample's `employeeId` field when it carries a synthetic
	 * employeeNumber-shaped placeholder (e.g. 'EMP-NL-0001') matching a real
	 * seeded Employee, with that Employee's actual UUID.
	 *
	 * A sample with no `employeeId`, or one that does not match a known
	 * placeholder (e.g. the EuUsPayrollChecks DE/FR/US samples, which
	 * reference employees the seeder never creates), passes through unchanged
	 * -- the create attempt then fails exactly as it did before this
	 * resolution step existed.
	 *
	 * @param array<string, mixed> $sample The seed sample.
	 * @param array<string, string> $employeeUuidsByNumber employeeNumber => uuid map.
	 *
	 * @return array<string, mixed> The sample, with `employeeId` resolved when possible.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
	 */
	public function resolvePlaceholder(array $sample, array $employeeUuidsByNumber): array {
		$employeeId = ($sample['employeeId'] ?? null);
		if (is_string($employeeId) === true && isset($employeeUuidsByNumber[$employeeId]) === true) {
			$sample['employeeId'] = $employeeUuidsByNumber[$employeeId];
		}

		return $sample;
	}//end resolvePlaceholder()

}//end class
