<?php

/**
 * Payroll Controller
 *
 * Backs the `PayrollRunDetail` manifest page action "(Her)berekenen"
 * (payroll-core-engine design.md D6): a single POST endpoint that resolves
 * the posted `runId` through OpenRegister's ObjectService under the caller's
 * ambient RBAC BEFORE any computation (the DocumentController no-admin-idor
 * pattern — an unknown or unauthorized runId never reaches the engine, and
 * both collapse to the same 404 so existence is never leaked), refuses
 * non-draft runs (400 — approved/posted/paid runs are booked truth consumed
 * by glpost/netpay), then delegates the actual recalculation to
 * `PayrollRunService::recalculateRun()`. ONE endpoint, no CRUD (ADR-022 —
 * the run pages read/write the register declaratively via the object store).
 *
 * payroll-mutation-reports (2026-07-14) adds `mutations()`, backing the
 * `PayrollRunDetail` "Mutatieoverzicht" action (design.md D6): the SAME
 * no-admin-idor RBAC-resolve-first pattern as `calculate()`, PLUS an
 * explicit admin/HR authorization check (payroll figures are sensitive —
 * a non-admin caller gets 403 before any RBAC resolve, so an unauthorized
 * principal cannot even probe for run existence), then delegates to
 * `PayrollMutationService::diff()` + `persist()`.
 *
 * Also backs the "Simuleer loonstrook" pro-forma surface (proforma-payslip
 * design.md D4): `proforma()` resolves the caller's access to the humaniq
 * payroll register/schema (a capability probe, not a row) through the same
 * ObjectService + ambient-RBAC idiom BEFORE delegating to the stateless
 * `ProformaPayslipService::simulate()` — unauthorized/unavailable collapse
 * to the same 404, and nothing is ever persisted.
 *
 * retro-adjustments (design.md D8) adds `adjust()`, backing the
 * `PayrollAdjustmentDetail` "Herrekenen" action: the SAME no-admin-idor
 * RBAC-resolve-first pattern as `calculate()` (an unresolvable/unauthorized
 * `adjustmentId` never reaches the recompute), refuses recompute of an
 * already-`applied` adjustment (400 — settled deltas are sealed), then
 * delegates to `RetroAdjustmentService::recomputeAdjustment()`. The sealed
 * original Payslip/PayrollRun the adjustment corrects are never written by
 * this endpoint.
 *
 * wkr-administration (design.md D6) adds `wkrAssess()`, backing the
 * `WkrAssessmentDetail` "Beoordelen" action: the SAME admin/HR gate as
 * `mutations()` (payroll figures are sensitive), then the posted
 * `assessmentId` must resolve through ObjectService under the caller's
 * ambient RBAC (the `authorizeRun()` no-admin-idor pattern) before its
 * `administrationId`/`year` are read and delegated to
 * `WkrService::assess()` — the recompute upserts the SAME assessment in
 * place (idempotent), it never creates a second one.
 *
 * single-person-modes (design.md D5) adds `dgaStatus()`, backing the
 * `MijnGebruikelijkLoon` self-service page's warning banner: a
 * `#[NoAdminRequired]`, read-only, stateless `GET /api/payroll/dga-status`
 * that resolves the caller's OWN `Employee` via `nextcloudUserId` (the
 * `mijn-hr-self-service` link), 404s identically when no such Employee exists
 * OR it is not a DGA (both collapse to the same status — existence/DGA-ness
 * is never leaked, the `proforma()` posture), and otherwise REUSES
 * `NlDgaChecks`'s existing `nl-gebruikelijkloon-norm` predicate for the `met`
 * verdict — zero new tax logic, zero new persistence, computed fresh on
 * every call.
 *
 * @category Controller
 * @package  OCA\Humaniq\Controller
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
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-008
 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-008
 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-002
 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-004
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-007
 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-005
 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Service\PayrollMutationService;
use OCA\Humaniq\Service\PayrollRunService;
use OCA\Humaniq\Service\ProformaPayslipService;
use OCA\Humaniq\Service\RetroAdjustmentService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\WkrService;
use OCA\Humaniq\Standards\Checks\NlDgaChecks;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Guarded endpoint that (re)calculates one draft payroll run, and the
 * persist-nothing pro-forma simulation endpoint.
 */
class PayrollController extends Controller {

	/**
	 * Max Employee rows loaded when resolving the caller's own record for
	 * `dgaStatus()` — the fleet's `findAll(['limit' => N])`-then-filter-in-PHP
	 * convention (AdministrationService/RuleAuditService precedent); a
	 * single-person administratie realistically never approaches this.
	 *
	 * @var int
	 */
	private const EMPLOYEE_LOOKUP_LIMIT = 10000;

	/**
	 * @param IRequest $request The request object.
	 * @param ContainerInterface $container DI container for the RBAC-guarded ObjectService resolve.
	 * @param PayrollRunService $payrollRunService The draft-run generation service.
	 * @param PayrollMutationService $payrollMutationService The run-to-run diff service.
	 * @param ProformaPayslipService $proformaPayslipService The persist-nothing pro-forma simulation service.
	 * @param RetroAdjustmentService $retroAdjustmentService The TWK delta computation + settlement service.
	 * @param WkrService $wkrService The WKR vrije-ruimte assessment roll-up service.
	 * @param SettingsService $settingsService The register-slug source.
	 * @param IUserSession $userSession The current user session (admin/HR check).
	 * @param IGroupManager $groupManager To check the caller's admin membership (admin/HR gate).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly PayrollRunService $payrollRunService,
		private readonly PayrollMutationService $payrollMutationService,
		private readonly ProformaPayslipService $proformaPayslipService,
		private readonly RetroAdjustmentService $retroAdjustmentService,
		private readonly WkrService $wkrService,
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `POST /api/payroll/calculate` — recalculate one draft PayrollRun. The
	 * posted `runId` must resolve through ObjectService under the caller's
	 * RBAC before anything computes (unknown/unauthorized -> 404); non-draft
	 * runs are refused (400) before the service is invoked.
	 *
	 * @param string|null $runId The PayrollRun id (row-scoped, `@objectId` from the manifest action).
	 *
	 * @return JSONResponse The recalculation outcome, 400 on a missing runId or non-draft run, 404 when the run does not resolve.
	 *
	 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-008
	 */
	#[NoAdminRequired]
	public function calculate(?string $runId = null): JSONResponse {
		$runId = trim((string)$runId);
		if ($runId === '') {
			return new JSONResponse(['error' => 'runId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): the run must resolve through
		// OpenRegister's ObjectService under the caller's RBAC before any
		// computation — an unresolvable/unauthorized id never reaches the
		// engine.
		$run = $this->authorizeRun($runId);
		if ($run === null) {
			return new JSONResponse(['error' => 'Loonrun niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$status = (string)($run['status'] ?? '');
		if ($status !== 'draft') {
			return new JSONResponse(
				['error' => 'Loonrun heeft status "' . $status . '" — alleen concept-runs kunnen herberekend worden.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$result = $this->payrollRunService->recalculateRun($runId);

		if ((string)$result['status'] === 'failed') {
			return new JSONResponse($result, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result);
	}//end calculate()

	/**
	 * `POST /api/payroll/mutations` — generate + persist the run-to-run
	 * payroll mutation report (payroll-mutation-reports design.md D6).
	 * Non-admin/HR callers are refused with 403 BEFORE any RBAC resolve (an
	 * unauthorized principal cannot even probe run existence via this
	 * endpoint — payroll figures are sensitive). `toRunId` (and `fromRunId`
	 * if given) must then resolve through ObjectService under the caller's
	 * ambient RBAC (unknown/unauthorized -> 404, the `calculate()`
	 * no-admin-idor pattern) before the service runs; a cross-administration
	 * pair is refused with 400.
	 *
	 * @param string|null $toRunId The PayrollRun being reviewed (row-scoped, `@objectId` from the manifest action).
	 * @param string|null $fromRunId The baseline PayrollRun, or null to auto-resolve the prior period.
	 *
	 * @return JSONResponse {reportId, report} on success; 400 on a missing/invalid toRunId or cross-administration pair, 403 for a non-admin caller, 404 when a run does not resolve.
	 *
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-008
	 */
	#[NoAdminRequired]
	public function mutations(?string $toRunId = null, ?string $fromRunId = null): JSONResponse {
		if ($this->isAdminOrHr() === false) {
			return new JSONResponse(['error' => 'Alleen beheerders/HR mogen mutatierapporten genereren.'], Http::STATUS_FORBIDDEN);
		}

		$toRunId = trim((string)$toRunId);
		if ($toRunId === '') {
			return new JSONResponse(['error' => 'toRunId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): both runs must resolve
		// through ObjectService under the caller's RBAC before any diff runs.
		if ($this->authorizeRun($toRunId) === null) {
			return new JSONResponse(['error' => 'Loonrun niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$fromRunId = trim((string)$fromRunId);
		if ($fromRunId !== '' && $this->authorizeRun($fromRunId) === null) {
			return new JSONResponse(['error' => 'Loonrun niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$outcome = $this->payrollMutationService->diff($toRunId, ($fromRunId === '' ? null : $fromRunId));
		if ((string)$outcome['status'] !== 'ok') {
			return new JSONResponse(['error' => (string)$outcome['message']], Http::STATUS_BAD_REQUEST);
		}

		$persisted = $this->payrollMutationService->persist($outcome['report']);
		if ((string)$persisted['status'] !== 'ok') {
			return new JSONResponse(['error' => (string)$persisted['message']], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['reportId' => $persisted['reportId'], 'report' => $outcome['report']]);
	}//end mutations()

	/**
	 * Whether the current caller is a Nextcloud admin — the admin/HR gate for
	 * `mutations()` (design.md D6: payroll figures are sensitive, so this
	 * endpoint additionally requires the caller be an admin/HR principal,
	 * unlike `calculate()`). No dedicated "HR" Nextcloud group exists in this
	 * app yet, so the gate is the standard admin-group check; introducing a
	 * separate HR group is a named fast-follow, not a blocker for this
	 * change.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-mutation-reports/specs/payroll-mutation-reports/spec.md#REQ-MUT-008
	 */
	private function isAdminOrHr(): bool {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			return false;
		}

		return $this->groupManager->isAdmin($uid);
	}//end isAdminOrHr()

	/**
	 * `POST /api/payroll/proforma` — the persist-nothing "Simuleer
	 * loonstrook" pro-forma simulation (proforma-payslip design.md D4). The
	 * caller's access to the humaniq payroll register/schema is resolved
	 * through ObjectService under ambient RBAC BEFORE any computation (a
	 * capability probe, not a row lookup — there is no `runId`); a caller who
	 * could not see a real Payslip gets a 404, so unauthorized/unavailable
	 * collapse to the same status. Only then is the hypothetical input
	 * delegated to `ProformaPayslipService::simulate()`. No object is ever
	 * read for its data or written.
	 *
	 * @param mixed $gross Bruto maandsalaris (euro), required, numeric.
	 * @param string|null $table Loonheffingstabel `wit`/`groen` (default `wit`).
	 * @param mixed $loonheffingskorting Whether the loonheffingskorting applies (default true).
	 * @param string|null $dateOfBirth ISO-8601 date of birth, or null (below-AOW).
	 * @param string|null $period Wage period `YYYY-MM` (default the current month).
	 * @param mixed $parttime Part-time factor (default 1.0).
	 * @param mixed $bijzonder One-off bijzondere beloning, euro (default 0).
	 * @param string|null $aof Aof-tariefklasse override `laag`/`hoog`.
	 * @param mixed $whk Whk-percentage override.
	 *
	 * @return JSONResponse The full breakdown, 400 on malformed input, 404 when the caller cannot resolve the payroll register.
	 *
	 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-002
	 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-004
	 */
	#[NoAdminRequired]
	public function proforma(
		mixed $gross = null,
		?string $table = null,
		mixed $loonheffingskorting = null,
		?string $dateOfBirth = null,
		?string $period = null,
		mixed $parttime = null,
		mixed $bijzonder = null,
		?string $aof = null,
		mixed $whk = null,
	): JSONResponse {
		// No-admin-idor guard applied to a capability rather than a row
		// (design.md D4): unresolvable/unauthorized register access never
		// reaches the engine, and both collapse to the same 404.
		if ($this->authorizeProformaAccess() === false) {
			return new JSONResponse(['error' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		try {
			$breakdown = $this->proformaPayslipService->simulate(
				[
					'gross' => $gross,
					'table' => $table,
					'loonheffingskorting' => $loonheffingskorting,
					'dateOfBirth' => $dateOfBirth,
					'period' => $period,
					'parttime' => $parttime,
					'bijzonder' => $bijzonder,
					'aof' => $aof,
					'whk' => $whk,
				]
			);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($breakdown);
	}//end proforma()

	/**
	 * Resolve-first capability probe (design.md D4): whether the caller's
	 * ambient RBAC can reach the humaniq payroll register/schema at all — the
	 * same "anyone who can see a real Payslip" boundary as `authorizeRun()`,
	 * applied to a capability instead of an id. Reads `limit=1` and never
	 * inspects the returned row's content — this is a probe, not a data
	 * read, and it never writes.
	 *
	 * @return bool True when the caller's RBAC can resolve the payroll register/schema.
	 *
	 * @spec openspec/changes/proforma-payslip/specs/proforma-payslip/spec.md#REQ-PRO-004
	 */
	private function authorizeProformaAccess(): bool {
		try {
			$this->objectService()
				->setRegister($this->settingsService->getRegisterSlug())
				->setSchema('PayrollRun')
				->findAll(['limit' => 1]);
		} catch (\Throwable $e) {
			$this->logger->info('PayrollController: proforma-toegang kon niet worden geresolved: ' . $e->getMessage());
			return false;
		}

		return true;
	}//end authorizeProformaAccess()

	/**
	 * `GET /api/payroll/dga-status` — the self-service gebruikelijkloon
	 * compliance status for the caller's OWN DGA record (single-person-modes
	 * design.md D5). Read-only, stateless, persists nothing:
	 *
	 * 1. Resolves the caller's own `Employee` via `nextcloudUserId` (the
	 *    `mijn-hr-self-service` link). No `Employee` found, or the resolved
	 *    `Employee` is not a DGA (`isDga` not `true`) → **404** — both cases
	 *    collapse to the SAME status so the response never distinguishes
	 *    "no Employee" from "Employee, not a DGA" (D5.1, the `proforma()`
	 *    resolve-first-then-404 posture; existence/DGA-ness is never leaked).
	 * 2. REUSES `NlDgaChecks`'s existing `nl-gebruikelijkloon-norm` predicate
	 *    for the `met` verdict — zero new tax logic; the norm comparison is
	 *    never reimplemented here. The `grossAnnualSalaryCents`/`jaarnormCents`
	 *    display figures are derived from the SAME annualisation
	 *    (`grossMonthlySalary × 12`) and the SAME loaded tables'
	 *    `gebruikelijkloon().jaarnormCents` the predicate uses — read fresh on
	 *    every call, no caching, no register write, EVER.
	 *
	 * @return JSONResponse `{isDga, grossAnnualSalaryCents, jaarnormCents, met, justification}` for the caller's own DGA record, or 404 when no own DGA Employee resolves.
	 *
	 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-006
	 */
	#[NoAdminRequired]
	public function dgaStatus(): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null || $userId === '') {
			return new JSONResponse(['error' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$employee = $this->resolveOwnEmployee($userId);
		if ($employee === null || ($employee['isDga'] ?? false) !== true) {
			// D5.1: "no Employee" and "Employee, not a DGA" collapse to the
			// SAME 404 — the response never reveals which case occurred.
			return new JSONResponse(['error' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		// REUSE the shipped predicate for the verdict — never a reimplemented
		// norm comparison (acceptance criterion: NlDgaChecks' predicate gains
		// exactly this one new caller).
		$met = NlDgaChecks::meetsGebruikelijkloonNorm($employee);

		$grossMonthly = ($employee['grossMonthlySalary'] ?? null);
		$grossAnnualCents = is_numeric($grossMonthly) === true ? (int)round(((float)$grossMonthly) * 12 * 100) : 0;

		// The SAME loaded-tables gebruikelijkloon norm the predicate reads —
		// the ONE new call site of TaxTables::gebruikelijkloon() (this
		// self-service wrapper), for display alongside the verdict.
		$jaarnormCents = 0;
		$ids = TaxTables::availableIds();
		if ($ids !== []) {
			$jaarnormCents = TaxTables::load(max($ids))->gebruikelijkloon()['jaarnormCents'];
		}

		$justification = trim((string)($employee['gebruikelijkloonJustification'] ?? ''));

		return new JSONResponse(
			[
				'isDga' => true,
				'grossAnnualSalaryCents' => $grossAnnualCents,
				'jaarnormCents' => $jaarnormCents,
				'met' => $met,
				'justification' => ($justification === '' ? null : $justification),
			]
		);

	}//end dgaStatus()

	/**
	 * Resolve the caller's OWN `Employee` record by matching
	 * `nextcloudUserId === $userId` (the `mijn-hr-self-service` durable
	 * account link, single-person-modes design.md D5). Loads under the
	 * caller's ambient RBAC and filters in PHP (the
	 * AdministrationService/RuleAuditService `findAll`-then-filter precedent);
	 * returns null when nothing matches OR the caller's RBAC denies the read
	 * (both collapse to the same null → the caller's 404, never leaking
	 * existence). The first matching record wins.
	 *
	 * @param string $userId The caller's Nextcloud user id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-006
	 */
	private function resolveOwnEmployee(string $userId): ?array {
		try {
			$rows = $this->objectService()
				->setRegister($this->settingsService->getRegisterSlug())
				->setSchema('Employee')
				->findAll(['limit' => self::EMPLOYEE_LOOKUP_LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->info('PayrollController: eigen werknemer kon niet worden geresolved: ' . $e->getMessage());
			return null;
		}

		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$employee = $this->toArray($row);
			if (trim((string)($employee['nextcloudUserId'] ?? '')) === $userId) {
				return $employee;
			}
		}

		return null;
	}//end resolveOwnEmployee()

	/**
	 * `POST /api/payroll/adjust` -- recompute one PayrollAdjustment (the
	 * `PayrollAdjustmentDetail` "Herrekenen" action, retro-adjustments
	 * design.md D8). The posted `adjustmentId` must resolve through
	 * ObjectService under the caller's RBAC before anything recomputes
	 * (unknown/unauthorized -> 404, the `calculate()` no-admin-idor
	 * precedent); an already-`applied` adjustment is refused (400 -- its
	 * settled delta is sealed). Never writes the sealed original Payslip/
	 * PayrollRun the adjustment corrects.
	 *
	 * @param string|null $adjustmentId The PayrollAdjustment id (row-scoped, `@objectId` from the manifest action).
	 *
	 * @return JSONResponse The recompute outcome, 400 on a missing adjustmentId or an already-applied adjustment, 404 when it does not resolve.
	 *
	 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-007
	 */
	#[NoAdminRequired]
	public function adjust(?string $adjustmentId = null): JSONResponse {
		$adjustmentId = trim((string)$adjustmentId);
		if ($adjustmentId === '') {
			return new JSONResponse(['error' => 'adjustmentId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): the adjustment must resolve
		// through OpenRegister's ObjectService under the caller's RBAC before
		// any recompute -- an unresolvable/unauthorized id never reaches it.
		$adjustment = $this->authorizeAdjustment($adjustmentId);
		if ($adjustment === null) {
			return new JSONResponse(['error' => 'Correctie niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		if ((string)($adjustment['status'] ?? '') === 'applied') {
			return new JSONResponse(
				['error' => 'Correctie is al toegepast — herrekenen is niet meer mogelijk.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$result = $this->retroAdjustmentService->recomputeAdjustment($adjustmentId);

		if ((string)$result['status'] === 'failed') {
			return new JSONResponse($result, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result);
	}//end adjust()

	/**
	 * Resolve the posted adjustmentId through OpenRegister's ObjectService
	 * under the caller's ambient RBAC (default $_rbac=true) -- the
	 * no-admin-idor guard for `adjust()` (the `authorizeRun()` precedent).
	 * Returns null when the adjustment does not exist OR the caller's RBAC
	 * denies it (both collapse to the same 404 so existence is never
	 * leaked).
	 *
	 * @param string $adjustmentId The PayrollAdjustment id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-007
	 */
	private function authorizeAdjustment(string $adjustmentId): ?array {
		try {
			$adjustment = $this->objectService()->find(
				id: $adjustmentId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'PayrollAdjustment'
			);
		} catch (\Throwable $e) {
			$this->logger->info('PayrollController: correctie ' . $adjustmentId . ' kon niet worden opgehaald: ' . $e->getMessage());
			return null;
		}

		if ($adjustment === null) {
			return null;
		}

		return $this->toArray($adjustment);
	}//end authorizeAdjustment()

	/**
	 * `POST /api/payroll/wkr-assess` -- (re)compute the WKR vrije-ruimte
	 * assessment (the `WkrAssessmentDetail` "Beoordelen" action,
	 * wkr-administration design.md D6). Non-admin/HR callers are refused with
	 * 403 BEFORE any RBAC resolve (the `mutations()` precedent -- WKR figures
	 * are payroll-sensitive). The posted `assessmentId` must then resolve
	 * through ObjectService under the caller's ambient RBAC (unknown/
	 * unauthorized -> 404, the `calculate()`/`adjust()` no-admin-idor
	 * pattern) before its `administrationId`/`year` are read and delegated to
	 * `WkrService::assess()`, which upserts the SAME assessment in place.
	 *
	 * @param string|null $assessmentId The WkrAssessment id (row-scoped, `@objectId` from the manifest action).
	 *
	 * @return JSONResponse The recompute outcome, 400 on a missing assessmentId, 403 for a non-admin caller, 404 when it does not resolve.
	 *
	 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-005
	 */
	#[NoAdminRequired]
	public function wkrAssess(?string $assessmentId = null): JSONResponse {
		if ($this->isAdminOrHr() === false) {
			return new JSONResponse(['error' => 'Alleen beheerders/HR mogen WKR-beoordelingen (her)berekenen.'], Http::STATUS_FORBIDDEN);
		}

		$assessmentId = trim((string)$assessmentId);
		if ($assessmentId === '') {
			return new JSONResponse(['error' => 'assessmentId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): the assessment must resolve
		// through OpenRegister's ObjectService under the caller's RBAC before
		// any recompute -- an unresolvable/unauthorized id never reaches it.
		$assessment = $this->authorizeAssessment($assessmentId);
		if ($assessment === null) {
			return new JSONResponse(['error' => 'WKR-beoordeling niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$administrationId = (string)($assessment['administrationId'] ?? '');
		$year = (int)($assessment['year'] ?? 0);

		$result = $this->wkrService->assess($administrationId, $year);

		if ((string)$result['status'] === 'failed') {
			return new JSONResponse($result, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($result);
	}//end wkrAssess()

	/**
	 * Resolve the posted assessmentId through OpenRegister's ObjectService
	 * under the caller's ambient RBAC (default $_rbac=true) -- the
	 * no-admin-idor guard for `wkrAssess()` (the `authorizeAdjustment()`
	 * precedent). Returns null when the assessment does not exist OR the
	 * caller's RBAC denies it (both collapse to the same 404 so existence is
	 * never leaked).
	 *
	 * @param string $assessmentId The WkrAssessment id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-005
	 */
	private function authorizeAssessment(string $assessmentId): ?array {
		try {
			$assessment = $this->objectService()->find(
				id: $assessmentId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'WkrAssessment'
			);
		} catch (\Throwable $e) {
			$this->logger->info('PayrollController: WKR-beoordeling ' . $assessmentId . ' kon niet worden opgehaald: ' . $e->getMessage());
			return null;
		}

		if ($assessment === null) {
			return null;
		}

		return $this->toArray($assessment);
	}//end authorizeAssessment()

	/**
	 * Resolve the posted runId through OpenRegister's ObjectService under the
	 * caller's ambient RBAC (default $_rbac=true) — the no-admin-idor guard
	 * for this endpoint (the DocumentController::authorizeContract pattern).
	 * Returns null when the run does not exist OR the caller's RBAC denies it
	 * (both collapse to the same 404 so existence is never leaked).
	 *
	 * @param string $runId The PayrollRun id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-008
	 */
	private function authorizeRun(string $runId): ?array {
		try {
			$run = $this->objectService()->find(
				id: $runId,
				register: $this->settingsService->getRegisterSlug(),
				schema: 'PayrollRun'
			);
		} catch (\Throwable $e) {
			$this->logger->info('PayrollController: loonrun ' . $runId . ' kon niet worden opgehaald: ' . $e->getMessage());
			return null;
		}

		if ($run === null) {
			return null;
		}

		return $this->toArray($run);
	}//end authorizeRun()

	/**
	 * @return mixed The OpenRegister ObjectService, resolved with the caller's ambient RBAC (default $_rbac=true).
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. Unguarded, an
		// instance without OpenRegister gets a container exception naming a
		// class the admin has never heard of; guarded, it is told which app to
		// install — which is rule 3's promise that the app still explains
		// itself.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * Normalise an ObjectService row (entity or array) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

}//end class
