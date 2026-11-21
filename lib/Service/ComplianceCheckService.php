<?php

/**
 * Compliance Check Service
 *
 * The `Compliance check` page's data (REQ-RULE-010): runs the rule audit and
 * answers with the corpus coverage and every violation about a record the
 * caller may read. The audit itself reads the whole register; which
 * violations come back is decided by one caller-scoped read per audited
 * schema, so OpenRegister's own access rules decide what a manager sees.
 * Counts are of the listed violations only, so nothing is learned about
 * records the caller may not read. The page's tiles and table each ask once,
 * so the unfiltered audit is kept for a minute; the per-caller filter runs on
 * every answer.
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
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\ICacheFactory;

/**
 * Coverage and the violations the caller may see.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#requirement-hr-shall-see-the-audits-violations-and-coverage-on-a-page-req-rule-010
 */
final class ComplianceCheckService {

	/**
	 * Severity order on the page: mandatory first.
	 */
	private const SEVERITY_ORDER = ['mandatory' => 0, 'conditional' => 1, 'recommended' => 2];

	/**
	 * Seconds the unfiltered audit is kept.
	 */
	private const CACHE_TTL = 60;

	/**
	 * Constructor.
	 *
	 * @param RuleAuditService     $audit        The rule audit.
	 * @param RecordStoreInterface $store        The caller-scoped reads.
	 * @param ICacheFactory|null   $cacheFactory Keeps the unfiltered audit for a minute; null runs it every time.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#requirement-hr-shall-see-the-audits-violations-and-coverage-on-a-page-req-rule-010
	 */
	public function __construct(
		private readonly RuleAuditService $audit,
		private readonly RecordStoreInterface $store,
		private readonly ?ICacheFactory $cacheFactory = null,
	) {

	}//end __construct()

	/**
	 * Run the check for the signed-in caller.
	 *
	 * @param string $jurisdiction The jurisdiction the rules are evaluated for.
	 *
	 * @return array{coverage: array<string, mixed>, counts: array<string, int>, violations: list<array<string, string>>}
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#requirement-hr-shall-see-the-audits-violations-and-coverage-on-a-page-req-rule-010
	 */
	public function run(string $jurisdiction = 'NL'): array {
		$findings = $this->findings(jurisdiction: $jurisdiction);
		$visible = $this->readable(violations: $findings['violations']);

		usort(
			$visible,
			static fn (array $a, array $b): int => [(self::SEVERITY_ORDER[$a['severity']] ?? 9), $a['ruleId'], $a['schema'], $a['id']] <=> [(self::SEVERITY_ORDER[$b['severity']] ?? 9), $b['ruleId'], $b['schema'], $b['id']]
		);

		return [
			'coverage' => self::coverage(report: $findings['report']),
			'counts' => self::counts(violations: $visible),
			'violations' => $visible,
		];
	}//end run()

	/**
	 * The unfiltered audit, from the cache when it ran in the last minute.
	 *
	 * @param string $jurisdiction The jurisdiction.
	 *
	 * @return array{report: array<string, mixed>, violations: list<array<string, string>>}
	 */
	private function findings(string $jurisdiction): array {
		$cache = null;
		if ($this->cacheFactory !== null && $this->cacheFactory->isAvailable() === true) {
			$cache = $this->cacheFactory->createDistributed('humaniq-compliance-check');
		}

		$key = 'findings-' . $jurisdiction;
		$cached = $cache?->get($key);
		if (is_array($cached) === true && isset($cached['report'], $cached['violations']) === true) {
			return $cached;
		}

		$findings = $this->audit->findings(['jurisdiction' => $jurisdiction]);
		$cache?->set($key, $findings, self::CACHE_TTL);

		return $findings;
	}//end findings()

	/**
	 * The violations about records the caller may read, each with the
	 * record's id as `id` so the page row opens that record.
	 *
	 * @param list<array<string, string>> $violations Every violation of the audit.
	 *
	 * @return list<array<string, string>>
	 */
	private function readable(array $violations): array {
		$readable = [];
		$visible = [];
		foreach ($violations as $violation) {
			$schema = $violation['schema'];
			if ($violation['objectId'] === '') {
				continue;
			}

			if (isset($readable[$schema]) === false) {
				$readable[$schema] = $this->readableIds(schema: $schema);
			}

			if (isset($readable[$schema][$violation['objectId']]) === false) {
				continue;
			}

			$visible[] = [
				'id' => $violation['objectId'],
				'schema' => $schema,
				'ruleId' => $violation['ruleId'],
				'severity' => $violation['severity'],
				'statement' => $violation['statement'],
				'source' => $violation['source'],
			];
		}

		return $visible;
	}//end readable()

	/**
	 * The ids of a schema's records the caller may read. A failed read
	 * reads as none, so an error never widens what is shown.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<string, true>
	 */
	private function readableIds(string $schema): array {
		try {
			$rows = $this->store->findFiltered(schema: $schema, filters: [], asCaller: true);
		} catch (\Throwable) {
			return [];
		}

		$ids = [];
		foreach ($rows as $row) {
			$id = (string)($row['id'] ?? $row['@self']['id'] ?? '');
			if ($id !== '') {
				$ids[$id] = true;
			}
		}

		return $ids;
	}//end readableIds()

	/**
	 * The corpus coverage: catalogued rules, how many are enforced and how
	 * many are not.
	 *
	 * @param array<string, mixed> $report The audit report.
	 *
	 * @return array<string, mixed>
	 */
	private static function coverage(array $report): array {
		$catalogued = (int)($report['machineCheckable'] ?? 0);
		$enforced = (int)($report['enforceableRules'] ?? 0);

		return [
			'catalogueVersion' => (string)($report['catalogueVersion'] ?? ''),
			'corpusTotal' => (int)($report['corpusTotal'] ?? 0),
			'catalogued' => $catalogued,
			'enforced' => $enforced,
			'notEnforced' => max(0, ($catalogued - $enforced)),
			'coveragePct' => (float)($report['coveragePct'] ?? 0.0),
		];
	}//end coverage()

	/**
	 * Counts of the listed violations, in total and per severity.
	 *
	 * @param list<array<string, string>> $violations The listed violations.
	 *
	 * @return array<string, int>
	 */
	private static function counts(array $violations): array {
		$counts = ['total' => count($violations), 'mandatory' => 0, 'conditional' => 0, 'recommended' => 0];
		foreach ($violations as $violation) {
			if (isset($counts[$violation['severity']]) === true && $violation['severity'] !== 'total') {
				$counts[$violation['severity']]++;
			}
		}

		return $counts;
	}//end counts()
}//end class
