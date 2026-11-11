<?php

/**
 * Humaniq Portal Contribution Provider
 *
 * Humaniq's contribution to the shared Portaliq external portal (hydra ADR-046 +
 * 2026-07-06 amendment, contribution contract v2). Portaliq — the one shared
 * external portal for people WITHOUT Nextcloud accounts — discovers this class
 * by convention FQCN (`OCA\{App}\Portal\PortalContributionProvider`) and
 * duck-types it via method_exists(), never instanceof. Therefore this class is
 * deliberately PLAIN: no portaliq imports, no `implements` clause, no info.xml
 * dependency, no constructor dependencies. Without portaliq installed it is
 * inert and humaniq behaves exactly as before (amendment A1).
 *
 * It declares these audiences, among them `external-employee` (payroll externals without an
 * NC account — payslips, contracts, own employee record, timesheets, expenses,
 * leave requests; create timesheet/expense/leave request) and `client` (the
 * client who reviews billable hours — read-only timesheets scoped by
 * clientRef). All scoping uses UUID domain-object references resolved from the
 * subject's server-managed claim map (`claims.humaniq.employeeId` /
 * `claims.humaniq.clientId`, amendment A4) — never Nextcloud user ids.
 *
 * hiring-portal-audiences adds `candidate` (the anonymous careers page and
 * apply form: the only anonymous entries humaniq contributes), `new-hire`
 * (preboarding: own record, onboarding case, papers and bank details) and
 * `former-employee` (read-only own payslips, annual statements and letters).
 * `manager` reads the timesheets of a cost centre.
 *
 * @category Portal
 * @package  OCA\Humaniq\Portal
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
 * @spec openspec/changes/portal-contribution/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Humaniq\Portal;

/**
 * Declares what external employees and clients may see and do in humaniq's
 * section of the shared external portal.
 *
 * The contribution is a declarative manifest (pure data — no I/O, no
 * callbacks). All subject identity (subjectRef, audience, organisation, trust,
 * claims) is derived server-side by portaliq's auth edge and MUST never be
 * trusted from the client (ADR-005). Portaliq stamps the collection scope
 * field server-side on every create, so no scoping property appears in any
 * create-action field whitelist.
 *
 * @spec openspec/changes/portal-contribution/tasks.md#task-1
 */
class PortalContributionProvider {
	/**
	 * The audiences this provider contributes to (contract v2, preferred).
	 *
	 * The registry probes for this method first; the audience vocabulary is an
	 * open string set (amendment A2). humaniq serves external employees (the HR
	 * self-service story) and clients (billable-hours review).
	 *
	 * @return array<int, string> The audience identifiers.
	 *
	 * hiring-portal-audiences D1 adds a candidate (the careers page), a new
	 * hire (preboarding) and a former employee (their own paperwork).
	 *
	 * @spec openspec/changes/portal-contribution/tasks.md#task-2
	 * @spec openspec/specs/portal-audiences/spec.md#REQ-PAU-001
	 */
	public function getAudiences(): array {
		return [
			'external-employee',
			'client',
			'manager',
			'candidate',
			'new-hire',
			'former-employee',
		];

	}//end getAudiences()

	/**
	 * The primary audience this provider contributes to (contract v1 fallback).
	 *
	 * Kept alongside getAudiences() so the provider also works against a v1
	 * registry that predates multi-audience support. A v1 registry only sees
	 * the external-employee contribution — the client view requires v2.
	 *
	 * @return string The primary audience identifier.
	 *
	 * @spec openspec/changes/portal-contribution/tasks.md#task-2
	 */
	public function getAudience(): string {
		return 'external-employee';
	}//end getAudience()

	/**
	 * Build the declarative portal manifest for one resolved subject.
	 *
	 * The subject array is server-derived by portaliq (subjectRef UUID,
	 * audience, organisation, trust level low|substantial|high, claim map).
	 * Returns null when humaniq has nothing for the subject — any audience other
	 * than external-employee or client (fail-closed; the registry already
	 * filters by audience, but a provider must not rely on that).
	 *
	 * Manifest vocabulary (amendment A2–A6): `collections` are read surfaces
	 * portaliq serves from OpenRegister, scoped by `scopeField` == the claim
	 * selected by `scopeClaim` (bare names resolve under `claims.humaniq.*`);
	 * `actions` of type `create` expose strict field whitelists — status and
	 * approval-stamp fields are excluded because the declarative
	 * x-openregister-lifecycle owns every transition, and the scoping
	 * `employeeId` is excluded because portaliq stamps it server-side.
	 * `minTrust` is `low` everywhere in Wave 1 (employer-issued password
	 * accounts); the raise plan is documented in this change's design.md.
	 *
	 * @param array<string, mixed> $subject The resolved portal subject.
	 *
	 * @return array<string, mixed>|null The manifest, or null when not contributing.
	 *
	 * @spec openspec/changes/portal-contribution/tasks.md#task-3
	 */
	public function getContribution(array $subject): ?array {
		$audience = ($subject['audience'] ?? '');
		$manifest = match ($audience) {
			'external-employee' => $this->externalEmployeeManifest(),
			'client' => $this->clientManifest(),
			'manager' => $this->managerManifest(),
			default => $this->recruitingManifest($audience),
		};
		if ($manifest === null) {
			return null;
		}

		return $this->withPages(manifest: $manifest);
	}//end getContribution()

	/**
	 * Give every page the audience's menu group, declaring the default pages where none are.
	 *
	 * Portaliq's group contract: pages with the same `group` share one
	 * heading in the site's menu, instead of the app's name. A page carries a
	 * group only when it is declared, so an audience without pages gets the
	 * ones portaliq would make (the create action for the collection's schema,
	 * the list, the selected row) and the screens stay as they were.
	 *
	 * @param array<string, mixed> $manifest The audience's manifest; its `label` is the group.
	 *
	 * @return array<string, mixed> The manifest with grouped pages.
	 *
	 * @spec openspec/changes/portal-pages-in-dutch-groups/specs/portal-contribution/spec.md#requirement-every-portal-page-names-its-menu-group-in-dutch
	 */
	private function withPages(array $manifest): array {
		$group = (string)$manifest['label'];
		$pages = ($manifest['pages'] ?? []);
		if ($pages === []) {
			$pages = self::collectionPages($manifest);
		}

		$manifest['pages'] = array_map(
			static fn (array $page): array => (['group' => $group] + $page),
			$pages
		);

		return $manifest;
	}//end withPages()

	/**
	 * One page per listable collection: its create action (when one targets the
	 * collection's schema), the collection and its detail.
	 *
	 * @param array<string, mixed> $manifest The manifest without pages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function collectionPages(array $manifest): array {
		$pages = [];
		foreach ($manifest['collections'] as $collection) {
			if (($collection['listable'] ?? true) !== true) {
				continue;
			}

			$blocks   = self::createActionBlocks($manifest['actions'], $collection);
			$blocks[] = ['type' => 'collection', 'collection' => (string)$collection['id']];
			$blocks[] = ['type' => 'detail', 'collection' => (string)$collection['id']];
			$pages[]  = ['id' => (string)$collection['id'], 'label' => (string)$collection['label'], 'blocks' => $blocks];
		}

		return $pages;

	}//end collectionPages()

	/**
	 * The action block of the first create action on the collection's schema, if any.
	 *
	 * @param array<int, array<string, mixed>> $actions    The manifest's actions.
	 * @param array<string, mixed>             $collection The collection.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function createActionBlocks(array $actions, array $collection): array {
		foreach ($actions as $action) {
			if (($action['type'] ?? '') === 'create' && ($action['schema'] ?? '') === ($collection['schema'] ?? '')) {
				return [['type' => 'action', 'action' => (string)$action['id']]];
			}
		}

		return [];

	}//end createActionBlocks()

	/**
	 * The manifests of the recruiting and leaver audiences
	 * (hiring-portal-audiences D1), or null for an audience humaniq does not
	 * serve.
	 *
	 * @param string $audience The audience.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-audiences/spec.md#REQ-PAU-001
	 */
	private function recruitingManifest(string $audience): ?array {
		if ($audience === 'candidate') {
			return $this->candidateManifest();
		}

		if ($audience === 'new-hire') {
			return $this->newHireManifest();
		}

		if ($audience === 'former-employee') {
			return $this->formerEmployeeManifest();
		}

		return null;
	}//end recruitingManifest()

	/**
	 * The external-employee manifest: HR self-service over the subject's own
	 * records, scoped by the `employeeId` claim (the UUID of their Employee
	 * domain object — the Employee schema has no Nextcloud-user link by
	 * design, amendment A4).
	 *
	 * @return array<string, mixed> The manifest.
	 *
	 * @spec openspec/changes/portal-contribution/tasks.md#task-3
	 */
	private function externalEmployeeManifest(): array {
		return [
			'label' => 'Werk en uren',
			'collections' => [
				self::ownEmployeeRecord('Mijn personeelsgegevens'),
				self::ownCollection('payslips', 'Payslip') + ['label' => 'Mijn loonstroken', 'listable' => true],
				self::ownCollection('employmentContracts', 'EmploymentContract') + ['label' => 'Mijn arbeidscontracten', 'listable' => true],
				self::ownCollection('timesheets', 'Timesheet') + ['label' => 'Mijn urenstaten', 'listable' => true],
				self::ownCollection('expenses', 'Expense') + ['label' => 'Mijn declaraties', 'listable' => true],
				self::ownCollection('leaveRequests', 'LeaveRequest') + ['label' => 'Mijn verlofaanvragen', 'listable' => true],
			],
			'actions' => [
				self::createTimesheetAction(),
				self::createExpenseAction(),
				self::createLeaveRequestAction(),
			],
			'notifications' => [],
		];

	}//end externalEmployeeManifest()

	/**
	 * A collection of the subject's own records of one schema: scoped by the
	 * record's `employeeId` to the subject's `employeeId` claim, at low trust.
	 *
	 * @param string $id     The collection id.
	 * @param string $schema The schema.
	 *
	 * @return array<string, mixed> The collection, before its label and listing flag.
	 *
	 * @spec openspec/changes/portal-contribution/tasks.md#task-3
	 */
	private static function ownCollection(string $id, string $schema): array {
		return [
			'id' => $id,
			'register' => 'humaniq',
			'schema' => $schema,
			'scopeField' => 'employeeId',
			'scopeClaim' => 'employeeId',
			'minTrust' => 'low',
		];

	}//end ownCollection()

	/**
	 * The subject's own Employee record: scoped by its `id` to the subject's
	 * `employeeId` claim, at low trust, read but not listed.
	 *
	 * @param string $label The collection label.
	 *
	 * @return array<string, mixed> The collection.
	 *
	 * @spec openspec/changes/portal-contribution/tasks.md#task-3
	 */
	private static function ownEmployeeRecord(string $label): array {
		return [
			'id' => 'myEmployeeRecord',
			'register' => 'humaniq',
			'schema' => 'Employee',
			'scopeField' => 'id',
			'scopeClaim' => 'employeeId',
			'minTrust' => 'low',
			'label' => $label,
			'listable' => false,
		];

	}//end ownEmployeeRecord()

	/**
	 * The external employee's create-timesheet action and its field whitelist.
	 *
	 * @return array<string, mixed> The action.
	 */
	private static function createTimesheetAction(): array {
		return [
			'id' => 'createTimesheet',
			'type' => 'create',
			'label' => 'Uren schrijven',
			'register' => 'humaniq',
			'schema' => 'Timesheet',
			'fields' => [
				'period',
				'hours',
				'description',
				'projectId',
				'costCenter',
				'billable',
				'clientRef',
			],
		];

	}//end createTimesheetAction()

	/**
	 * The external employee's create-expense action and its field whitelist.
	 *
	 * @return array<string, mixed> The action.
	 */
	private static function createExpenseAction(): array {
		return [
			'id' => 'createExpense',
			'type' => 'create',
			'label' => 'Een declaratie indienen',
			'register' => 'humaniq',
			'schema' => 'Expense',
			'fields' => [
				'title',
				'description',
				'amount',
				'currency',
				'category',
				'expenseDate',
			],
		];

	}//end createExpenseAction()

	/**
	 * The external employee's create-leave-request action and its field whitelist.
	 *
	 * @return array<string, mixed> The action.
	 */
	private static function createLeaveRequestAction(): array {
		return [
			'id' => 'createLeaveRequest',
			'type' => 'create',
			'label' => 'Verlof aanvragen',
			'register' => 'humaniq',
			'schema' => 'LeaveRequest',
			'fields' => [
				'leaveType',
				'startDate',
				'endDate',
				'hours',
				'reason',
			],
		];

	}//end createLeaveRequestAction()

	/**
	 * The client manifest: a read-only view over the timesheets whose billable
	 * hours the client reviews, scoped by `Timesheet.clientRef` == the
	 * `clientId` claim (the UUID of the client contact/organisation domain
	 * object). The approve/reject action is deliberately absent — lifecycle
	 * transitions by externals require the bearer-forwarded endpoint action
	 * type (amendment A6), whose receiver-side verification humaniq does not
	 * implement in Wave 1.
	 *
	 * @return array<string, mixed> The manifest.
	 *
	 * @spec openspec/changes/portal-contribution/tasks.md#task-3
	 */
	private function clientManifest(): array {
		return [
			'label' => 'Werk en uren',
			'collections' => [
				[
					'id' => 'clientTimesheets',
					'register' => 'humaniq',
					'schema' => 'Timesheet',
					'scopeField' => 'clientRef',
					'scopeClaim' => 'clientId',
					'minTrust' => 'low',
					'label' => 'Te beoordelen urenstaten',
					'listable' => true,
				],
			],
			'actions' => [],
			'notifications' => [],
		];

	}//end clientManifest()

	/**
	 * The manager manifest: an external team lead / department manager (no
	 * Nextcloud account) reviews and approves/rejects the timesheets for their
	 * cost centre, scoped by `Timesheet.costCenter` == the `costCenter` claim.
	 *
	 * The read is field-projected — only the review-relevant fields leave humaniq;
	 * `costCenter` (the scope key), `billable`, `projectId` and `submittedAt`
	 * stay internal.
	 *
	 * APPROVE/REJECT is deliberately NOT wired as a portal `type: update`
	 * transition. Portaliq's claim-scoped update DOES support this (ownership is
	 * re-verified by the resolved costCenter claim), but humaniq's Timesheet carries
	 * a declarative lifecycle hook that requires an authenticated Nextcloud user
	 * to change `status` ("U moet ingelogd zijn om goed te keuren of af te
	 * keuren"). Portal writes bypass OpenRegister RBAC but NOT lifecycle hooks, so
	 * an external approver (no NC account by premise) is stopped at the hook. The
	 * external approve/reject therefore needs either the A6 bearer-forward action
	 * (humaniq's own endpoint runs the transition with app context) or a
	 * portal-subject-aware lifecycle hook — tracked as a follow-up. Until then
	 * this manifest is READ-ONLY, matching the client manifest's stance.
	 *
	 * @return array<string, mixed> The manifest.
	 *
	 * @spec openspec/changes/portal-contribution/tasks.md#task-3
	 */
	private function managerManifest(): array {
		return [
			'label' => 'Werk en uren',
			'collections' => [self::teamTimesheetsCollection()],
			'actions' => [],
			'pages' => [
				[
					'id' => 'timesheets',
					'label' => 'Urenstaten van uw team',
					'icon' => 'ClockCheck',
					'blocks' => [
						[
							'type' => 'richText',
							'markdown' => 'Bekijk de ingediende urenstaten van uw kostenplaats.',
						],
						['type' => 'collection', 'collection' => 'teamTimesheets'],
					],
				],
			],
			'notifications' => [],
		];

	}//end managerManifest()

	/**
	 * The manager's team timesheets: scoped by `costCenter` to the subject's
	 * `costCenter` claim, projected to the review fields.
	 *
	 * @return array<string, mixed> The collection.
	 *
	 * @spec openspec/changes/portal-contribution/tasks.md#task-3
	 */
	private static function teamTimesheetsCollection(): array {
		return [
			'id' => 'teamTimesheets',
			'register' => 'humaniq',
			'schema' => 'Timesheet',
			'scopeField' => 'costCenter',
			'scopeClaim' => 'costCenter',
			'minTrust' => 'low',
			'label' => 'Urenstaten van uw team',
			'listable' => true,
			// Read-side projection (the DATA authority): only review
			// fields leave humaniq. costCenter (the scope key), billable,
			// projectId and submittedAt are dropped.
			'fields' => [
				'employeeId',
				'period',
				'hours',
				'status',
				'description',
			],
		] + self::teamTimesheetsLayout();

	}//end teamTimesheetsCollection()

	/**
	 * How the team timesheets are listed, shown and sorted.
	 *
	 * @return array<string, mixed>
	 */
	private static function teamTimesheetsLayout(): array {
		return [
			'columns' => [
				['field' => 'employeeId', 'label' => 'Medewerker'],
				['field' => 'period', 'label' => 'Periode'],
				['field' => 'hours', 'label' => 'Uren'],
				['field' => 'status', 'label' => 'Status', 'render' => 'badge'],
			],
			'detail' => ['layout' => 'card', 'fields' => ['employeeId', 'period', 'hours', 'status', 'description']],
			'defaultSort' => ['field' => 'period', 'direction' => 'desc'],
		];

	}//end teamTimesheetsLayout()

	/**
	 * The candidate manifest (hiring-portal-audiences D2): the only anonymous
	 * entries humaniq contributes. The published vacancies, projected so the
	 * administration and publish date never leave humaniq, and the apply
	 * action with a whitelist that keeps status, retention, offer and
	 * administration out of a visitor's hands; the lifecycle's initial state
	 * makes every portal application `nieuw`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/portal-audiences/spec.md#REQ-PAU-001
	 */
	private function candidateManifest(): array {
		return [
			'label' => 'Vacatures',
			'collections' => [
				[
					'id' => 'openVacancies',
					'register' => 'humaniq',
					'schema' => 'Vacancy',
					'anonymous' => true,
					'minTrust' => 'low',
					'filter' => ['status' => 'gepubliceerd'],
					'fields' => ['title', 'description', 'department', 'closingDate', 'questions'],
					'label' => 'Vacatures',
					'listable' => true,
				],
			],
			'actions' => [self::applyToVacancyAction()],
			'notifications' => [],
		];

	}//end candidateManifest()

	/**
	 * The anonymous apply-to-vacancy action and its field whitelist.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/portal-contribution/tasks.md#task-3
	 */
	private static function applyToVacancyAction(): array {
		return [
			'id' => 'applyToVacancy',
			'type' => 'create',
			'label' => 'Solliciteren',
			'register' => 'humaniq',
			'schema' => 'job-application',
			'anonymous' => true,
			'minTrust' => 'low',
			'fields' => [
				'vacancyId',
				'candidateName',
				'email',
				'phone',
				'motivation',
				'talentPoolOptIn',
				'answers',
			],
		];

	}//end applyToVacancyAction()

	/**
	 * The new-hire manifest (hiring-portal-audiences D4): the hire's own
	 * employee record and onboarding case, papers uploaded onto that case, and
	 * three of their own fields at substantial trust, because a bank account
	 * and a BSN are what a fraudster wants. HR still ticks the checklist.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/portal-audiences/spec.md#REQ-PAU-003
	 */
	private function newHireManifest(): array {
		return [
			'label' => 'Uw nieuwe baan',
			'collections' => [
				self::ownEmployeeRecord('Mijn gegevens'),
				self::ownCollection('myOnboarding', 'Onboarding') + [
					'fields' => ['startDate', 'status', 'contractSigned', 'widCheckDone', 'bsnValidated', 'ibanVerified', 'itProvisioned', 'pensioenAangemeld'],
					'filesUpload' => true,
					'label' => 'Voor uw eerste werkdag',
					'listable' => true,
				],
			],
			'actions' => [self::updateMyDetailsAction()],
			'notifications' => [],
		];

	}//end newHireManifest()

	/**
	 * The new hire's update of their own bank account and BSN, at substantial trust.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/portal-contribution/tasks.md#task-3
	 */
	private static function updateMyDetailsAction(): array {
		return [
			'id' => 'updateMyDetails',
			'type' => 'update',
			'label' => 'Mijn bankrekening en BSN doorgeven',
			'register' => 'humaniq',
			'schema' => 'Employee',
			'scopeField' => 'id',
			'scopeClaim' => 'employeeId',
			'minTrust' => 'substantial',
			'fields' => [
				'iban',
				'tenaamstelling',
				'bsn',
			],
		];

	}//end updateMyDetailsAction()

	/**
	 * The former-employee manifest (hiring-portal-audiences D5): read-only, the
	 * leaver's own payslips, annual statements and generated letters. How long
	 * the account lives is portaliq's account policy.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/portal-audiences/spec.md#REQ-PAU-004
	 */
	private function formerEmployeeManifest(): array {
		return [
			'label' => 'Uw vroegere baan',
			'collections' => [
				self::ownCollection('payslips', 'Payslip') + ['label' => 'Mijn loonstroken', 'listable' => true],
				self::ownCollection('annualStatements', 'Jaaropgaaf') + ['label' => 'Mijn jaaropgaven', 'listable' => true],
				self::ownCollection('myDocuments', 'HrGeneratedDocument') + [
					'filter' => ['status' => 'generated'],
					'label' => 'Mijn brieven en verklaringen',
					'listable' => true,
				],
			],
			'actions' => [],
			'notifications' => [],
		];

	}//end formerEmployeeManifest()
}//end class
