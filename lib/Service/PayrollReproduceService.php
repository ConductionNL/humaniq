<?php

/**
 * Payroll Reproduce Service
 *
 * `occ hrmq:payroll:reproduce --payslip <uuid>` (audit-trail-payroll
 * design.md D1, REQ-AUDP-002, fixing hrmq#98): reloads a sealed Payslip's
 * stored `engineInputSnapshot` — NEVER the live Employee/EmploymentContract
 * state — resolves the exact jurisdiction-pack artefact that produced its
 * run, recomputes through `PayrollCalculator`, and compares every D2 output
 * component against the payslip's stored values cents-exact. This is the
 * "verifier re-runs and re-derives" mechanism the reproducibility guarantee
 * actually rests on: `engineInputSnapshot` alone is inert data until
 * something recomputes from it and confirms the figures still match.
 *
 * `PayrollCalculator` is never modified and stays pure (design.md, this
 * service only calls its existing public `calculate()`); post-tax folds this
 * service is not the engine's business (`retroAdjustment`/`leaveBuySell`/
 * `loonbeslag`) are read back from the SEALED payslip itself — they are
 * independently-settled figures the engine never computed and an Employee/
 * Contract edit cannot affect, so folding them back onto the recomputed
 * engine net is the correct like-for-like comparison, not a shortcut.
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
 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use OCA\Hrmq\Payroll\CalculationInput;
use OCA\Hrmq\Payroll\CalculationResult;
use OCA\Hrmq\Payroll\PackRepository;
use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Payroll\TaxTables;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Recomputes a sealed Payslip from its own stored `engineInputSnapshot` and
 * compares the result cents-exact against the stored figures.
 */
final class PayrollReproduceService {

	/**
	 * @var string
	 */
	private const PAYSLIP_SCHEMA = 'Payslip';

	/**
	 * @var string
	 */
	private const RUN_SCHEMA = 'PayrollRun';

	/**
	 * Max objects loaded per type.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * The D2 output components compared cents-exact, mapped
	 * `payslipField => CalculationResult property`. `nettoPay` is handled
	 * separately (post-tax folds).
	 *
	 * @var array<string, string>
	 */
	private const COMPARED_COMPONENTS = [
		'grossPay' => 'grossPayCents',
		'loonheffing' => 'loonheffingCents',
		'arbeidskorting' => 'arbeidskortingCents',
		'volksverzekeringen' => 'volksverzekeringenCents',
		'werknemersverzekeringen' => 'werknemersverzekeringenCents',
		'zvw' => 'zvwCents',
		'vakantiegeldReserved' => 'vakantiegeldReservedCents',
	];

	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settingsService Register slug.
	 * @param PayrollCalculator $calculator The pure gross-to-net calculator (never modified by this service).
	 * @param LoggerInterface $logger Logger.
	 * @param PackRepository $packs The jurisdiction-pack resolver.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly PayrollCalculator $calculator,
		private readonly LoggerInterface $logger,
		private readonly PackRepository $packs = new PackRepository(),
	) {

	}//end __construct()

	/**
	 * Reproduce one Payslip from its stored `engineInputSnapshot` and
	 * compare cents-exact against its stored figures (REQ-AUDP-002).
	 *
	 * @param string $payslipId The Payslip id (uuid).
	 *
	 * @return array<string, mixed> `{payslipId, status: reproduced|mismatch|refused, message, mismatches}`.
	 *
	 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
	 */
	public function reproduce(string $payslipId): array {
		$payslipId = trim($payslipId);

		$payslip = $this->findById(self::PAYSLIP_SCHEMA, $payslipId);
		if ($payslip === null) {
			return $this->refused($payslipId, 'Loonstrook ' . $payslipId . ' niet gevonden.');
		}

		$artefact = $this->resolveArtefact($payslip);
		if (is_string($artefact) === true) {
			return $this->refused($payslipId, $artefact);
		}

		[$input, $tables] = $artefact;

		$result = $this->calculator->calculate($input, $tables);
		$mismatches = $this->compareComponents($payslip, $result);

		if ($mismatches !== []) {
			return [
				'payslipId' => $payslipId,
				'status' => 'mismatch',
				'message' => 'Reproductie mislukt: ' . count($mismatches) . ' component(en) wijken af van de gearchiveerde waarden.',
				'mismatches' => $mismatches,
			];
		}

		return [
			'payslipId' => $payslipId,
			'status' => 'reproduced',
			'message' => 'Elke component is byte-voor-byte gereproduceerd vanuit de opgeslagen engineInputSnapshot.',
			'mismatches' => [],
		];

	}//end reproduce()

	/**
	 * Resolve the exact engine artefact (jurisdiction pack + tax tables) and
	 * decoded `CalculationInput` a Payslip's stored snapshot names — every
	 * precondition check and lookup that precedes the actual recomputation
	 * (REQ-AUDP-002), split out of `reproduce()` to keep it small.
	 *
	 * @param array<string, mixed> $payslip The Payslip row.
	 *
	 * @return array{0: CalculationInput, 1: TaxTables}|string A `[input, tables]` pair, or a refusal message string.
	 */
	private function resolveArtefact(array $payslip): array|string {
		$run = $this->resolveRun($payslip);
		if (is_string($run) === true) {
			return $run;
		}

		[$run, $snapshot] = $run;

		return $this->resolveInputAndTables($run, $snapshot);
	}//end resolveArtefact()

	/**
	 * Resolve the PayrollRun a Payslip's snapshot needs, plus the snapshot
	 * itself — the `payrollRunId`/`engineInputSnapshot` presence check, the
	 * run lookup, and the `engineVersion` format check (REQ-AUDP-002).
	 *
	 * `engineInputSnapshot` comes back as EITHER a string or an
	 * already-decoded array (see `resolveInputAndTables()`'s docblock) — the
	 * presence check here only needs "is there something here", the actual
	 * shape is `CalculationInput`'s problem.
	 *
	 * @param array<string, mixed> $payslip The Payslip row.
	 *
	 * @return array{0: array<string, mixed>, 1: mixed}|string A `[run, snapshot]` pair, or a refusal message string.
	 */
	private function resolveRun(array $payslip): array|string {
		$runId = trim((string)($payslip['payrollRunId'] ?? ''));
		$snapshot = ($payslip['engineInputSnapshot'] ?? null);

		if ($runId === '' || empty($snapshot) === true) {
			return 'Loonstrook heeft geen engineInputSnapshot (payrollRunId: ' . ($runId === '' ? 'null' : $runId) . ') — niets te reproduceren (handmatig ingevoerde loonstrook, of ouder dan audit-trail-payroll).';
		}

		$run = $this->findById(self::RUN_SCHEMA, $runId);
		if ($run === null) {
			return 'De loonrun ' . $runId . ' waarnaar deze loonstrook verwijst bestaat niet meer.';
		}

		// strpos() on an empty haystack also returns false, so this single
		// check already covers BOTH "no engineVersion at all" and "an
		// engineVersion without the {packId}@{packVersion} separator" -- no
		// separate emptiness check needed.
		$storedEngineVersion = trim((string)($run['engineVersion'] ?? ''));
		if (strpos($storedEngineVersion, '@') === false) {
			return 'Loonrun heeft geen (of een legacy, pre-jurisdiction-packs) engineVersion "' . $storedEngineVersion . '" — kan het exacte artefact niet ondubbelzinnig herleiden.';
		}

		return [$run, $snapshot];
	}//end resolveRun()

	/**
	 * Decode the snapshot, resolve the SAME jurisdiction-pack artefact that
	 * produced the run (refusing on drift), and load its tax tables
	 * (REQ-AUDP-002).
	 *
	 * Live-verified against 8080: `OCA\OpenRegister\Db\MagicMapper::rowToObjectEntity()`
	 * blanket `json_decode()`s any string column value that happens to parse
	 * as valid JSON, regardless of the schema's declared `type: string` — so
	 * `engineInputSnapshot` comes back as an already-decoded array through
	 * every real `ObjectService` read, and `CalculationInput::fromDecoded()`
	 * is used; a literal string (only ever seen from a direct-SQL read or a
	 * hand-built test fixture) still decodes via `fromCanonicalJson()`.
	 *
	 * @param array<string, mixed> $run The resolved PayrollRun row.
	 * @param mixed $snapshot The Payslip's `engineInputSnapshot` — string OR already-decoded array.
	 *
	 * @return array{0: CalculationInput, 1: TaxTables}|string A `[input, tables]` pair, or a refusal message string.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) CalculationInput::fromCanonicalJson()/fromDecoded()/TaxTables::load() are pure value-object factory methods, the TaxTables::load() precedent already used unguarded in PayrollRunService.
	 */
	private function resolveInputAndTables(array $run, mixed $snapshot): array|string {
		$storedEngineVersion = trim((string)($run['engineVersion'] ?? ''));

		try {
			$input = (is_array($snapshot) === true)
				? CalculationInput::fromDecoded($snapshot)
				: CalculationInput::fromCanonicalJson(trim((string)$snapshot));
		} catch (\InvalidArgumentException $e) {
			return 'engineInputSnapshot kon niet gedecodeerd worden: ' . $e->getMessage();
		}

		try {
			$pack = $this->packs->resolve($input->jurisdiction, $input->period);
		} catch (\Throwable $e) {
			return 'Geen jurisdictiepack beschikbaar voor ' . $input->jurisdiction . ' ' . $input->period . ' — artefact niet herleidbaar: ' . $e->getMessage();
		}

		if ($pack->engineVersion() !== $storedEngineVersion) {
			return 'Het artefact is gedreven: de run is berekend met "' . $storedEngineVersion . '", het momenteel beschikbare pack voor ' . $input->jurisdiction . ' ' . substr($input->period, 0, 4) . ' is "' . $pack->engineVersion() . '" — reproductie met hetzelfde artefact is niet mogelijk.';
		}

		try {
			$tables = TaxTables::load($pack->tablesId());
		} catch (\Throwable $e) {
			return 'Belastingtabellen "' . $pack->tablesId() . '" konden niet geladen worden: ' . $e->getMessage();
		}

		return [$input, $tables];
	}//end resolveInputAndTables()

	/**
	 * Compare every D2 output component (cents-exact) against the payslip's
	 * stored values (REQ-AUDP-002) — one uniform loop over
	 * `expectedComponents()`'s recomputed figures, `nettoPay` included.
	 *
	 * @param array<string, mixed> $payslip The Payslip row.
	 * @param CalculationResult $result The freshly recomputed result.
	 *
	 * @return array<int, array{component: string, stored: mixed, recomputed: float}>
	 */
	private function compareComponents(array $payslip, CalculationResult $result): array {
		$mismatches = [];
		foreach ($this->expectedComponents($payslip, $result) as $field => $recomputed) {
			$stored = ($payslip[$field] ?? null);

			if (is_numeric($stored) === false || $this->centsEqual((float)$stored, $recomputed) === false) {
				$mismatches[] = [
					'component' => $field,
					'stored' => (is_numeric($stored) === true ? (float)$stored : $stored),
					'recomputed' => $recomputed,
				];
			}
		}

		return $mismatches;
	}//end compareComponents()

	/**
	 * The recomputed euro value expected for every compared component,
	 * `nettoPay` included.
	 *
	 * `nettoPay` folds the already-sealed, engine-independent post-tax
	 * components (`retroAdjustment`/`leaveBuySell`/`loonbeslag`) back onto
	 * the recomputed engine net — those deltas are settled elsewhere
	 * (PayrollAdjustment/LeaveTransaction/Loonbeslag), never affected by an
	 * Employee/Contract edit, so re-adding the SAME stored deltas is the
	 * correct like-for-like comparison, not a shortcut around the
	 * reproducibility check.
	 *
	 * @param array<string, mixed> $payslip The Payslip row (for the post-tax fold amounts).
	 * @param CalculationResult $result The freshly recomputed result.
	 *
	 * @return array<string, float> Recomputed euro value keyed by Payslip field name.
	 */
	private function expectedComponents(array $payslip, CalculationResult $result): array {
		$expected = [];
		foreach (self::COMPARED_COMPONENTS as $field => $resultProperty) {
			$expected[$field] = $this->euros((int)$result->{$resultProperty});
		}

		$expected['nettoPay'] = $this->euros(
			$result->nettoPayCents
				+ $this->centsOf($payslip['retroAdjustment'] ?? null)
				+ $this->centsOf($payslip['leaveBuySell'] ?? null)
				- $this->centsOf($payslip['loonbeslag'] ?? null)
		);

		return $expected;
	}//end expectedComponents()

	/**
	 * A `refused` outcome — nothing to reproduce, never a silent pass.
	 *
	 * @param string $payslipId The Payslip id.
	 * @param string $message Why reproduction was refused.
	 *
	 * @return array<string, mixed>
	 */
	private function refused(string $payslipId, string $message): array {
		return [
			'payslipId' => $payslipId,
			'status' => 'refused',
			'message' => $message,
			'mismatches' => [],
		];

	}//end refused()

	/**
	 * A stored euro amount as integer cents, treating null/non-numeric as 0
	 * (the payslip's own null-means-not-applicable convention for
	 * retroAdjustment/leaveBuySell/loonbeslag).
	 *
	 * @param mixed $value The stored field value.
	 *
	 * @return int
	 */
	private function centsOf(mixed $value): int {
		if (is_numeric($value) === false) {
			return 0;
		}

		return (int)round(((float)$value) * 100);
	}//end centsOf()

	/**
	 * @param int $cents Amount in integer cents.
	 *
	 * @return float Amount in euros, rounded to 2 decimals.
	 */
	private function euros(int $cents): float {
		return round(($cents / 100), 2);
	}//end euros()

	/**
	 * Compare two euro amounts at cent precision.
	 *
	 * @param float $a Left amount.
	 * @param float $b Right amount.
	 *
	 * @return bool
	 */
	private function centsEqual(float $a, float $b): bool {
		return (int)round($a * 100) === (int)round($b * 100);
	}//end centsEqual()

	/**
	 * Find one object by id (uuid) within a schema.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id The object id (uuid).
	 *
	 * @return array<string, mixed>|null
	 */
	private function findById(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		foreach ($this->loadAll($schema) as $row) {
			if ($this->idOf($row) === $id) {
				return $row;
			}
		}

		return null;
	}//end findById()

	/**
	 * Load every object of a schema, normalised to plain arrays.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()->setRegister($this->register())->setSchema($schema)->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('PayrollReproduceService: kon ' . $schema . ' niet laden: ' . $e->getMessage());
			return [];
		}

		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$out[] = $this->toArray($row);
		}

		return $out;
	}//end loadAll()

	/**
	 * Normalise an ObjectService row (entity or array) to a plain array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === false) {
			return [];
		}

		if (method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		if (method_exists($row, 'getObject') === true) {
			return (array)$row->getObject();
		}

		return [];
	}//end toArray()

	/**
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? $row['@self']['id'] ?? '');
	}//end idOf()

	/**
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured hrmq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()

}//end class
