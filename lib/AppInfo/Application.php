<?php

/**
 * Humaniq Application bootstrap
 *
 * Minimal IBootstrap entry point for the humaniq (HR / payroll) app. It registers the
 * two rule-engine occ commands (`humaniq:rules:audit` / `humaniq:rules:seed-testdata`)
 * and resolves OpenRegister's ObjectService for the compliance services. The app
 * stores no data of its own — all HR/labour objects live in the OpenRegister
 * `humaniq` register, imported by the InitializeRegister repair step.
 *
 * @category AppInfo
 * @package  OCA\Humaniq\AppInfo
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

namespace OCA\Humaniq\AppInfo;

use OCA\Humaniq\Command\RulesAuditCommand;
use OCA\Humaniq\Command\RulesSeedTestDataCommand;
use OCA\Humaniq\Lifecycle\ChangeApproverRoleGuard;
use OCA\Humaniq\Lifecycle\CompEffectiveDateGuard;
use OCA\Humaniq\Lifecycle\DecisionReasonGuard;
use OCA\Humaniq\Lifecycle\LeaveBuySellApprovalGuard;
use OCA\Humaniq\Lifecycle\LeaveTypeConditionGuard;
use OCA\Humaniq\Lifecycle\LeaveSettlementPeriodGuard;
use OCA\Humaniq\Lifecycle\NoSelfApprovalGuard;
use OCA\Humaniq\Lifecycle\PayrollRunApprovedGuard;
use OCA\Humaniq\Lifecycle\RightToWorkGuard;
use OCA\Humaniq\Lifecycle\RosterCompetenceGuard;
use OCA\Humaniq\Lifecycle\TimesheetNotEmptyGuard;
use OCA\Humaniq\Listener\ApprovalDecisionStampListener;
use OCA\Humaniq\Listener\ChangeRequestListener;
use OCA\Humaniq\Listener\EmployeeGuardedFieldListener;
use OCA\Humaniq\Listener\FieldAccessListener;
use OCA\Humaniq\Listener\FrequentAbsenceListener;
use OCA\Humaniq\Listener\HrLifecycleEventListener;
use OCA\Humaniq\Listener\LearniqCredentialListener;
use OCA\Humaniq\Listener\LeaveApprovalListener;
use OCA\Humaniq\Listener\ManagerDeputyListener;
use OCA\Humaniq\Listener\RegisterAgendaLeafListener;
use OCA\Humaniq\Listener\RegisterHoursLeafListener;
use OCA\Humaniq\Listener\ResourceBookingOverlapListener;
use OCA\Humaniq\Listener\RightToWorkCheckListener;
use OCA\Humaniq\Listener\ScenarioMutationListener;
use OCA\Humaniq\Listener\ExitInterviewListener;
use OCA\Humaniq\Listener\CandidateEvaluationStampListener;
use OCA\Humaniq\Listener\ReferralListener;
use OCA\Humaniq\Listener\ExpenseRouteListener;
use OCA\Humaniq\Listener\PayrollRunApprovedListener;
use OCA\Humaniq\Listener\CaoComponentOverrideListener;
use OCA\Humaniq\Listener\PayrollRunFindingStampListener;
use OCA\Humaniq\Listener\RecurringAllowanceStampListener;
use OCA\Humaniq\Listener\RelationsCaseListener;
use OCA\Humaniq\Listener\AnnouncementConfirmationListener;
use OCA\Humaniq\Service\AnnouncementService;
use OCA\Humaniq\Listener\SideActivityListener;
use OCA\Humaniq\Listener\TimeEntryStampListener;
use OCA\Humaniq\Listener\TimeEstimateListener;
use OCA\Humaniq\Listener\TimesheetAggregateListener;
use OCA\Humaniq\Listener\TimesheetApprovalListener;
use OCA\Humaniq\Listener\TimesheetProcessStampListener;
use OCA\Humaniq\Listener\TrainingRecordListener;
use OCA\Humaniq\Listener\TravelAmountListener;
use OCA\Humaniq\Listener\WorkingPatternOverlapListener;
use OCA\Humaniq\Payroll\PackRepository;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Service\TaxTableSetService;
use OCA\Humaniq\Service\HrLifecycleEventService;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\JurisdictionPackService;
use OCA\Humaniq\Service\ManagerDeputies;
use OCA\Humaniq\Service\RosterCheckService;
use OCA\Humaniq\Service\SideActivityRegister;
use OCA\Humaniq\Service\TimeEntryEventService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;

/**
 * The humaniq application bootstrap.
 *
 * @spec exclude composition root — wires guards, services and listeners owned by many capabilities; no single requirement owns the bootstrap itself
 */
class Application extends App implements IBootstrap {

	/**
	 * The application id.
	 *
	 * @var string
	 */
	public const APP_ID = 'humaniq';

	/**
	 * Construct the application.
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);

	}//end __construct()

	/**
	 * Register services and commands.
	 *
	 * The two occ commands are registered here so the DI container can resolve
	 * them; their constructor dependencies (the compliance services) are
	 * autowired. OpenRegister's ObjectService is exposed under its fully-qualified
	 * class-string so the compliance services can `get()` it across the app
	 * boundary.
	 *
	 * @param IRegistrationContext $context Registration context.
	 *
	 * @return void
	 *
	 * @spec exclude composition root — registers services owned by many capabilities (rule engine, lifecycle guards, jurisdiction packs, hours process); each registration cites its own change in the adjacent comment
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			RulesAuditCommand::class,
			static function ($c): RulesAuditCommand {
				return new RulesAuditCommand($c->get(\OCA\Humaniq\Service\RuleAuditService::class));
			}
		);

		$context->registerService(
			RulesSeedTestDataCommand::class,
			static function ($c): RulesSeedTestDataCommand {
				return new RulesSeedTestDataCommand($c->get(\OCA\Humaniq\Service\RuleTestDataSeeder::class));
			}
		);

		// OpenRegister lifecycle guard for the Timesheet/Expense approve+reject
		// transitions (separation of duties — no self-approval). Registered keyed
		// by its FQCN so OpenRegister's LifecycleGuardRegistry resolves the
		// `requires` tag declared on the transitions. The guard has no app
		// dependencies, so a plain construction closure suffices.
		$context->registerService(
			NoSelfApprovalGuard::class,
			static function ($c): NoSelfApprovalGuard {
				return new NoSelfApprovalGuard();
			}
		);

		// OpenRegister lifecycle guard for the PensionFiling `controleren` transition
		// (pension-filing-upa-mvp) — denies review unless the referenced PayrollRun is
		// approved/posted/paid. Unlike NoSelfApprovalGuard this guard loads the
		// referenced run, so it needs the container (lazy ObjectService resolution)
		// and IAppConfig (register slug), both autowired here.
		// people-record-change-approval D2: only the approver role of a change
		// request's kind decides on it. Keyed by its FQCN for the `requires`
		// tag on EmployeeChangeRequest's goedkeuren and afwijzen.
		$context->registerService(
			ChangeApproverRoleGuard::class,
			static function ($c): ChangeApproverRoleGuard {
				return new ChangeApproverRoleGuard(
					administrations: $c->get(\OCA\Humaniq\Service\AdministrationService::class),
					groupManager: $c->get(\OCP\IGroupManager::class)
				);
			}
		);

		$context->registerService(
			PayrollRunApprovedGuard::class,
			static function ($c): PayrollRunApprovedGuard {
				return new PayrollRunApprovedGuard(
					container: $c,
					appConfig: $c->get(\OCP\IAppConfig::class)
				);
			}
		);

		// OpenRegister lifecycle guard for the Timesheet `submit` transition
		// (hours-process-redesign): an empty timesheet — no bookings, or zero
		// hours — cannot be submitted. Stateless (reads only the payload passed
		// to check()), constructed exactly like NoSelfApprovalGuard, keyed by
		// its FQCN so OpenRegister's LifecycleGuardRegistry resolves the
		// `requires` tag declared on the `submit` transition.
		$context->registerService(
			TimesheetNotEmptyGuard::class,
			static function ($c): TimesheetNotEmptyGuard {
				return new TimesheetNotEmptyGuard();
			}
		);

		// OpenRegister lifecycle guard for the LeaveRequest `submit` transition
		// (leave-against-a-department-schedule REQ-LVM-T02): a type that needs a
		// reason, a document or a shorter notice refuses the submit and names the
		// condition. It loads the administered LeaveTypes, so it takes the shared
		// register gateway; keyed by its FQCN so OpenRegister's
		// LifecycleGuardRegistry resolves the `requires` tag on that transition.
		$context->registerService(
			LeaveTypeConditionGuard::class,
			static function ($c): LeaveTypeConditionGuard {
				return new LeaveTypeConditionGuard(
					gateway: $c->get(\OCA\Humaniq\Service\HoursRegisterGateway::class)
				);
			}
		);

		// hours-process-redesign: the request-scoped internal-writer marker MUST
		// be shared — the aggregation service / repair step set it and the
		// pre-save listeners read it, so they have to see the same instance.
		// registerService() registers shared by default; the explicit
		// registration makes that load-bearing property visible.
		$context->registerService(
			InternalWriteMarker::class,
			static function ($c): InternalWriteMarker {
				return new InternalWriteMarker();
			}
		);

		// OpenRegister lifecycle guard for the CompAdjustment `effectuate` transition
		// (comp-cycles) — fail-closed on the adjustment's own effectiveDate. Stateless
		// (reads only the payload passed to check()), so it is constructed exactly
		// like NoSelfApprovalGuard, keyed by its FQCN so OpenRegister's
		// LifecycleGuardRegistry resolves the `requires` tag declared on the
		// `effectuate` transition.
		$context->registerService(
			CompEffectiveDateGuard::class,
			static function ($c): CompEffectiveDateGuard {
				return new CompEffectiveDateGuard();
			}
		);

		// OpenRegister lifecycle guard for the CompAdjustment `refuse` transition
		// (comp-collective-raise-and-step-increase D6): a refusal needs a reason
		// and a decider who did not propose it. Stateless, so it is built like
		// NoSelfApprovalGuard, which it chains.
		$context->registerService(
			DecisionReasonGuard::class,
			static function ($c): DecisionReasonGuard {
				return new DecisionReasonGuard(new NoSelfApprovalGuard());
			}
		);

		// OpenRegister lifecycle guard for the LeaveTransaction `approve` transition
		// (leave-buy-sell) — delegates to NoSelfApprovalGuard, then for a sell
		// resolves the referenced LeaveBalance and denies on insufficient
		// bovenwettelijkHours. Loads a cross-object balance, so it needs the
		// container (lazy ObjectService resolution) and IAppConfig (register slug),
		// the same shape as PayrollRunApprovedGuard.
		$context->registerService(
			LeaveBuySellApprovalGuard::class,
			static function ($c): LeaveBuySellApprovalGuard {
				return new LeaveBuySellApprovalGuard(
					container: $c,
					appConfig: $c->get(\OCP\IAppConfig::class)
				);
			}
		);

		// OpenRegister lifecycle guard for the LeaveTransaction `settle` transition
		// (leave-buy-sell) — fail-closed on the transaction's own settlementPeriod.
		// Stateless (reads only the payload passed to check()), constructed exactly
		// like CompEffectiveDateGuard, keyed by its FQCN so OpenRegister's
		// LifecycleGuardRegistry resolves the `requires` tag declared on the
		// `settle` transition.
		$context->registerService(
			LeaveSettlementPeriodGuard::class,
			static function ($c): LeaveSettlementPeriodGuard {
				return new LeaveSettlementPeriodGuard();
			}
		);

		// people-dossier-completeness D5: an onboarding case does not reach the
		// first working day (gereed_melden, starten) without a passing
		// right-to-work check dated on or before its start date. Keyed by its
		// FQCN for the `requires` tag on those Onboarding transitions.
		$context->registerService(
			RightToWorkGuard::class,
			static function ($c): RightToWorkGuard {
				return new RightToWorkGuard(
					gateway: $c->get(\OCA\Humaniq\Service\HoursRegisterGateway::class),
					rule: $c->get(\OCA\Humaniq\Service\RightToWorkService::class)
				);
			}
		);

		// OpenRegister lifecycle guard for the Roster `publiceren` transition
		// (REQ-ROST-C02, humaniq#512): a roster that puts someone on a shift
		// they are not qualified for on that date does not publish. It reuses
		// RosterCheckService's competence cross-check, so the refusal and
		// `occ humaniq:roster:check` read the same findings. Keyed by its FQCN
		// so OpenRegister's LifecycleGuardRegistry resolves the `requires` tag.
		$context->registerService(
			RosterCompetenceGuard::class,
			static function ($c): RosterCompetenceGuard {
				return new RosterCompetenceGuard(
					rosterCheck: $c->get(RosterCheckService::class)
				);
			}
		);

		// jurisdiction-packs (design.md D7): the pack resolver spans two homes —
		// bundled packs in lib/Standards/packs/ (universal facts live in code)
		// and uploaded packs as OpenRegister objects. lib/Payroll/ carries zero
		// Nextcloud dependencies by design, so the OpenRegister-backed source is
		// injected here through the pure PackSourceInterface seam. Without this
		// wiring an uploaded pack would validate and store but never resolve —
		// an orphaned capability.
		$context->registerService(
			PackRepository::class,
			static function ($c): PackRepository {
				return new PackRepository($c->get(JurisdictionPackService::class));
			}
		);

		// The façade must resolve through the SAME two-home repository, or the
		// engine and the upload surface would disagree about which pack is live.
		$context->registerService(
			PayrollCalculator::class,
			static function ($c): PayrollCalculator {
				return new PayrollCalculator($c->get(PackRepository::class));
			}
		);

		// OpenRegister's ObjectService is registered in the server container and the
		// compliance services resolve it lazily via $container->get('OCA\\OpenRegister
		// \\Service\\ObjectService'); no app-level alias is needed (a self-alias would
		// recurse). When OpenRegister is absent the lazy get() throws and fails soft.

	}//end register()

	/**
	 * Register an object-lifecycle listener that declares its interest up front.
	 *
	 * OpenRegister's `ObjectEventSubscription` records the register/schema slugs
	 * a listener reacts to and routes dispatches through a single shared proxy,
	 * so an uninterested listener is neither constructed nor invoked. When
	 * OpenRegister is absent — humaniq carries no hard dependency on it — this
	 * degrades to the plain global registration it replaced, which is exactly
	 * the behaviour every listener had before.
	 *
	 * This MUST be called from boot(), never from register(). Nextcloud enables
	 * each app's autoloader immediately before calling that app's own
	 * register(), so during register() OpenRegister's classes are only
	 * autoloadable to apps that register after it — the class_exists() guard
	 * below would silently resolve to false purely because of this app's
	 * position in the enabled-app list, and the unfiltered fallback would look
	 * identical to a working narrowing. boot() runs only after every app's
	 * register() has completed, so the guard resolves regardless of ordering.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 * @param string $event OpenRegister event class name.
	 * @param string $listener Listener class name.
	 * @param array<int,string>|null $registers Register slugs, or null for all.
	 * @param array<int,string>|null $schemas Schema slugs, or null for all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/time-entry-capture/spec.md#REQ-TEC-002
	 */
	private function registerFilteredObjectListener(
		IEventDispatcher $dispatcher,
		string $event,
		string $listener,
		?array $registers,
		?array $schemas,
	): void {
		$subscription = '\\OCA\\OpenRegister\\Event\\ObjectEventSubscription';
		if (class_exists($subscription) === true) {
			$subscription::subscribe(
				dispatcher: $dispatcher,
				event: $event,
				listener: $listener,
				registers: $registers,
				schemas: $schemas
			);
			return;
		}

		// Loud on purpose. This fallback is correct but UNFILTERED, and while it
		// was silent it was indistinguishable from a working narrowing.
		\OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning(
			'OpenRegister ObjectEventSubscription unavailable: ' . $listener
			. ' fell back to an UNFILTERED registration for ' . $event
			. ' and will be invoked on every object write instance-wide.',
			['app' => self::APP_ID]
		);

		$dispatcher->addServiceListener($event, $listener);

	}//end registerFilteredObjectListener()

	/**
	 * Boot the application — declare the filtered object-event subscriptions.
	 *
	 * @param IBootContext $context Boot context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/time-entry-capture/spec.md#REQ-TEC-002
	 * @spec openspec/specs/humaniq-timesheet-approval/spec.md#Requirement:-Process-fields-are-server-stamped-and-inert-to-client-input
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
	 */
	public function boot(IBootContext $context): void {
		$dispatcher = $context->getServerContainer()->get(IEventDispatcher::class);

		// payroll-pack-and-cao-updates D1: tables uploaded with a pack are the
		// second home TaxTables::load() consults for an id no bundled file owns.
		$this->registerTaxTableSource($context->getAppContainer());

		// The SERVER half of the `humaniq-hours` leaf (ADR-066). Its client half
		// is src/integrations/registerHoursLeaf.js, bound by the shared id.
		// Registering only the client half renders the surface but leaves it
		// invisible to every server-side consumer — an orphan registration
		// gate-24 R2 refuses. Registered by class name so the leaf is contributed
		// whenever OpenRegister asks, and skipped (with a warning) when
		// OpenRegister is absent and the event never fires.
		if (class_exists(\OCA\OpenRegister\Event\RegisterLeafProvidersEvent::class) === true) {
			$dispatcher->addServiceListener(
				\OCA\OpenRegister\Event\RegisterLeafProvidersEvent::class,
				RegisterHoursLeafListener::class
			);

			// The SERVER half of the `humaniq-agenda` leaf
			// (agenda-rostering-and-resource-booking REQ-AGD-006). Its client
			// half is src/integrations/registerAgendaLeaf.js, bound by the
			// shared id, and shipped in the `humaniq-leaves` bundle so the
			// surface is not dark on a consuming page. Inside the same
			// class_exists() guard on purpose: when humaniq's host has no
			// OpenRegister the leaf is not registered at all, so a host renders
			// no agenda panel rather than an empty one.
			$dispatcher->addServiceListener(
				\OCA\OpenRegister\Event\RegisterLeafProvidersEvent::class,
				RegisterAgendaLeafListener::class
			);
		}

		// payroll-run-as-a-flow (REQ-PRF-001): contribute the four payroll
		// orchestration nodes to OpenRegister's flow catalogue. Registered
		// here in boot() — never register() — for the same autoloader-ordering
		// reason registerFilteredObjectListener() documents: during register()
		// OpenRegister's classes are only autoloadable to apps that register
		// after it, so the class_exists() guard would silently resolve false
		// purely because of this app's position in the enabled-app list.
		if (class_exists(\OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::class) === true) {
			$dispatcher->addServiceListener(
				\OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::class,
				\OCA\Humaniq\Flow\HumaniqFlowNodeListener::class
			);
		}

		// Time-entry capture (time-entry-capture): on a Timesheet crossing into
		// `approved`, emit the `nl.conduction.hrmq.timeentry.approved` CloudEvent so a
		// finance app (shillinq) can consume the approved hours for invoice-from-time /
		// WBSO. The listener is a thin OR adapter over TimeEntryEventService; it filters
		// to the Timesheet schema and the approval edge, and is fire-and-forget so a
		// missing consumer never fails the approval write (REQ-TEC-002).
		//
		// That Timesheet-schema filter is now also declared at REGISTRATION
		// time (TimeEntryEventService::TIMESHEET_SLUG, shipped by humaniq's own
		// register fragment lib/Settings/register.d/hr-timesheet.json as slug
		// `Timesheet`), so an unrelated app's object update no longer
		// constructs the listener — nor performs the SchemaMapper lookup
		// resolveSchemaSlug() needs to reject it. OpenRegister matches declared
		// slugs case-insensitively, and the listener's own strtolower() guard
		// stays in place as defence in depth. No register is declared: the
		// listener never inspects one.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectUpdatedEvent::class,
			listener: TimesheetApprovalListener::class,
			registers: null,
			schemas: [TimeEntryEventService::TIMESHEET_SLUG]
		);

		$this->registerHoursListeners($dispatcher);

		$this->registerLeaveListeners($dispatcher);
		$this->registerAbsenceListeners($dispatcher);
		$this->registerTravelListeners($dispatcher);
		$this->registerTrainingListeners($dispatcher);
		$this->registerDossierListeners($dispatcher);
		$this->registerSideActivityListeners($dispatcher);
		$this->registerRelationsCaseListeners($dispatcher);
		$this->registerExitInterviewListeners($dispatcher);
		$this->registerCandidateAssessmentListeners($dispatcher);
		$this->registerAnnouncementListener($dispatcher);
		$this->registerScenarioMutationListener($dispatcher);
		$this->registerChangeRequestListeners($dispatcher);
		$this->registerFieldAccessListener($dispatcher);
		$this->registerApprovalsInboxListeners($dispatcher);
		$this->registerOvertimeCreditListener($dispatcher);
		$this->registerExpensePayrollListeners($dispatcher);
		$this->registerHrLifecycleEventListener($dispatcher);

	}//end boot()

	/**
	 * Register the hours-process listeners (hours-process-redesign).
	 *
	 * Decisions 5, 4 and 3 in one place: pre-save stamping and the mutability
	 * guard for TimeEntry writes, pre-save process-field inertness and
	 * lifecycle-edge stamping for Timesheet writes, and the post-save aggregate
	 * recompute of the parent Timesheet.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec exclude composition root — registers three listeners each citing its own decision in the adjacent comment
	 */
	private function registerHoursListeners(IEventDispatcher $dispatcher): void {
		// hours-process-redesign Decision 5 + 3: pre-save stamping + mutability
		// guard for TimeEntry writes (employeeId/userId/administrationId/
		// costCenter stamps, hours derivation, timesheet find-or-create, and
		// the refusal of writes whose parent timesheet is not draft/rejected —
		// the delete guard included).
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class, ObjectDeletingEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: TimeEntryStampListener::class,
				registers: null,
				schemas: [TimeEntryStampListener::TIMEENTRY_SLUG]
			);
		}

		// hours-process-redesign Decision 4: pre-save process-field inertness +
		// lifecycle-edge stamping for Timesheet writes. Because the stamp lands
		// INSIDE the carrying write, the post-save ObjectUpdatedEvent that
		// TimesheetApprovalListener (above) consumes carries real provenance.
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: TimesheetProcessStampListener::class,
				registers: null,
				schemas: [TimesheetProcessStampListener::TIMESHEET_SLUG]
			);
		}

		// hours-process-redesign Decision 3: post-save aggregate recompute of
		// the parent Timesheet on every TimeEntry create/update/delete (both
		// parents on a reparent). Reacts only to timeentry events and writes
		// only Timesheet objects — no cycle.
		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: TimesheetAggregateListener::class,
				registers: null,
				schemas: [TimesheetAggregateListener::TIMEENTRY_SLUG]
			);
		}

		// working-hours-per-person REQ-WHP-001: refuse a WorkingPattern whose
		// period overlaps one the same employee already has. Two patterns give
		// two answers to "how many hours on this Tuesday", and the resolution
		// query would take whichever the store returned first — a number that
		// moves with query ordering and never looks wrong.
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: WorkingPatternOverlapListener::class,
				registers: null,
				schemas: [WorkingPatternOverlapListener::WORKINGPATTERN_SLUG]
			);
		}

		// agenda-rostering-and-resource-booking REQ-AGD-004: refuse a
		// ResourceBooking that would take a resource past its quantity over an
		// overlapping period, or that books a resource out of service. In the
		// write path rather than in a later report, because a room booked twice
		// for one hoorzitting is a person standing in a corridor.
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: ResourceBookingOverlapListener::class,
				registers: null,
				schemas: [ResourceBookingOverlapListener::RESOURCEBOOKING_SLUG]
			);
		}

		// estimate-spent-and-remaining-on-an-hours-leaf REQ-HL-EST-001/-004:
		// refuse a second estimate for one object and role, and refuse a
		// booking that would carry an ENFORCED estimate past its ceiling. In
		// the write path because the leaf and the consuming app's own screen
		// both book through OpenRegister's object API, and the refusal has to
		// read the same from either.
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: TimeEstimateListener::class,
				registers: null,
				schemas: [TimeEstimateListener::TIMEESTIMATE_SLUG, TimeEstimateListener::TIMEENTRY_SLUG]
			);
		}

	}//end registerHoursListeners()


	/**
	 * Register the leave-balance projection listeners.
	 *
	 * leave-approval-posts-to-the-balance: post-save recompute of
	 * `LeaveBalance.usedHours` from the approved LeaveRequests behind it. Before
	 * this, usedHours had no writer at all. LeaveAccrualJob seeds it to 0.0 on
	 * create and LeaveBuySellSettlementService writes only bovenwettelijkHours,
	 * so the calculated `remainingHours` reported the full entitlement forever
	 * and three labour rule checks could never fire (REQ-LEAVE-POST-001).
	 *
	 * Registered on EVERY leaverequest create and update rather than the
	 * approval edge alone: the projection is a recompute, so leaving `approved`
	 * has to restate the balance just as entering it does. Reacts only to
	 * leaverequest events and writes only LeaveBalance objects, so there is no
	 * cycle.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LEAVE-POST-001
	 */
	private function registerLeaveListeners(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: LeaveApprovalListener::class,
				registers: null,
				schemas: [LeaveApprovalListener::LEAVEREQUEST_SLUG]
			);
		}

	}//end registerLeaveListeners()

	/**
	 * Register the frequent-absence listener (absence-deadlines-and-signals
	 * D4): a created or reopened SickLeaveCase is counted against the
	 * administration's threshold. It writes only the case it was given, under
	 * InternalWriteMarker, so its own update is not counted again.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-003
	 */
	private function registerAbsenceListeners(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: FrequentAbsenceListener::class,
				registers: null,
				schemas: [FrequentAbsenceListener::SICKLEAVECASE_SLUG]
			);
		}

	}//end registerAbsenceListeners()

	/**
	 * expenses-travel-calculation D1 and D2: a travel claim gets its amount
	 * from the distance and a commuting arrangement its monthly allowance,
	 * stamped before the write is saved.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
	 */
	private function registerTravelListeners(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: TravelAmountListener::class,
				registers: null,
				schemas: [TravelAmountListener::EXPENSE_SLUG, TravelAmountListener::COMMUTE_SLUG]
			);
		}

	}//end registerTravelListeners()

	/**
	 * talent-training-and-lms D1, D2 and D4: an attended training gets its
	 * dates and administration before it is saved and grants its competence
	 * after, and a learniq credential becomes a training record.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-001
	 */
	private function registerTrainingListeners(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class, ObjectCreatedEvent::class, ObjectUpdatedEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: TrainingRecordListener::class,
				registers: null,
				schemas: [TrainingRecordListener::TRAINING_SLUG]
			);
		}

		// D4: a credential learniq issues comes back as a training record.
		// Scoped to learniq's register, so humaniq never hears another app's
		// credentials, and a no-op on an instance without learniq.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectCreatedEvent::class,
			listener: LearniqCredentialListener::class,
			registers: LearniqCredentialListener::LEARNIQ_REGISTERS,
			schemas: [LearniqCredentialListener::CREDENTIAL_SLUG]
		);

	}//end registerTrainingListeners()

	/**
	 * people-dossier-completeness D4: a right-to-work check is decided by the
	 * stated rule before it is saved, and a pass ticks the onboarding case's
	 * WID check after.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-003
	 */
	private function registerDossierListeners(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class, ObjectCreatedEvent::class, ObjectUpdatedEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: RightToWorkCheckListener::class,
				registers: null,
				schemas: [RightToWorkCheckListener::CHECK_SLUG]
			);
		}

	}//end registerDossierListeners()

	/**
	 * people-secondment-and-side-activities D3 and D4: a side activity report
	 * is placed on its employee before it is saved, every change keeps the
	 * employee's attestation in step with the register, and a hand edit of
	 * that attestation is put back.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
	 */
	private function registerSideActivityListeners(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatingEvent::class, ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: SideActivityListener::class,
				registers: null,
				schemas: [SideActivityRegister::ACTIVITY_SLUG]
			);
		}

		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectUpdatingEvent::class,
			listener: SideActivityListener::class,
			registers: null,
			schemas: [SideActivityRegister::EMPLOYEE_SLUG]
		);

	}//end registerSideActivityListeners()

	/**
	 * people-employee-relations-cases D2 and D3: a relations case carries the
	 * accounts its authorization matches on, and a closed one its retention
	 * date, stamped before the write is saved.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/employee-relations-cases/spec.md#REQ-ERC-002
	 */
	private function registerRelationsCaseListeners(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: RelationsCaseListener::class,
				registers: null,
				schemas: [RelationsCaseListener::CASE_SLUG]
			);
		}

	}//end registerRelationsCaseListeners()

	/**
	 * hiring-offboarding-completion D1: an exit interview takes its leaver,
	 * administration and department from its case before it is created, and
	 * stamps the case's exit interview date after.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
	 */
	private function registerExitInterviewListeners(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatingEvent::class, ObjectCreatedEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: ExitInterviewListener::class,
				registers: null,
				schemas: [ExitInterviewListener::SLUG]
			);
		}

	}//end registerExitInterviewListeners()

	/**
	 * hiring-candidate-assessment D1, D4: the evaluator stamp on a candidate
	 * evaluation, and a referral's checks, the application it creates and the
	 * status it follows.
	 *
	 * @param IEventDispatcher $dispatcher The dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-001
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-003
	 */
	private function registerCandidateAssessmentListeners(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: CandidateEvaluationStampListener::class,
				registers: null,
				schemas: [CandidateEvaluationStampListener::SLUG]
			);
		}

		foreach ([ObjectCreatingEvent::class, ObjectCreatedEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: ReferralListener::class,
				registers: null,
				schemas: [ReferralListener::SLUG]
			);
		}

		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectUpdatedEvent::class,
			listener: ReferralListener::class,
			registers: null,
			schemas: [ReferralListener::APPLICATION_SLUG]
		);

	}//end registerCandidateAssessmentListeners()

	/**
	 * self-service-announcements-and-digest D1: one confirmation per employee
	 * per announcement, placed on the employee before it is saved.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
	 */
	private function registerAnnouncementListener(IEventDispatcher $dispatcher): void {
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectCreatingEvent::class,
			listener: AnnouncementConfirmationListener::class,
			registers: null,
			schemas: [AnnouncementService::CONFIRMATION_SLUG]
		);

	}//end registerAnnouncementListener()

	/**
	 * payroll-pack-and-cao-updates D1: install the uploaded tax tables as the
	 * source TaxTables::load() asks for an id no bundled file owns. A factory,
	 * so booting never constructs the service or reaches OpenRegister.
	 *
	 * @param ContainerInterface $container The app container.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
	 * @SuppressWarnings(PHPMD.StaticAccess) TaxTables is a pure value-object factory with static load/fromDocument/isBundled, the precedent PayrollRunService and NlPayrollChecks already use.
	 */
	private function registerTaxTableSource(ContainerInterface $container): void {
		TaxTables::useSource(
			static function () use ($container): TaxTableSetService {
				return $container->get(TaxTableSetService::class);
			}
		);

	}//end registerTaxTableSource()

	/**
	 * time-hours-and-overtime-to-payroll D5: when a payroll run moves from
	 * draft to approved, the overtime it settled as time off is credited to
	 * the time-off-in-lieu balance.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
	 */
	private function registerOvertimeCreditListener(IEventDispatcher $dispatcher): void {
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectUpdatedEvent::class,
			listener: PayrollRunApprovedListener::class,
			registers: null,
			schemas: ['payrollrun']
		);
	}//end registerOvertimeCreditListener()

	/**
	 * payroll-expenses-and-allowances D1, D3: the claim route and the allowance drafter, before save;
	 * payroll-run-checks D5: the reviewer who acknowledges a finding; payroll-cao-components D2: a
	 * contract's CAO component overrides.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 */
	private function registerExpensePayrollListeners(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			foreach ([ExpenseRouteListener::class => ExpenseRouteListener::SLUG, RecurringAllowanceStampListener::class => RecurringAllowanceStampListener::SLUG, PayrollRunFindingStampListener::class => PayrollRunFindingStampListener::SLUG, CaoComponentOverrideListener::class => CaoComponentOverrideListener::SLUG] as $listener => $slug) {
				$this->registerFilteredObjectListener(dispatcher: $dispatcher, event: $event, listener: $listener, registers: null, schemas: [$slug]);
			}
		}
	}//end registerExpensePayrollListeners()

	/**
	 * self-service-approvals-inbox D1 and D2: a deputy record is judged before
	 * it is saved, and a leave request, expense claim or leave trade is
	 * stamped with its submit and decision moments so the inbox can show
	 * what a manager decided.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
	 */
	private function registerApprovalsInboxListeners(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: ManagerDeputyListener::class,
				registers: null,
				schemas: [ManagerDeputies::SLUG]
			);
		}

		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectUpdatingEvent::class,
			listener: ApprovalDecisionStampListener::class,
			registers: null,
			schemas: ApprovalDecisionStampListener::SLUGS
		);

	}//end registerApprovalsInboxListeners()

	/**
	 * platform-hr-lifecycle-events D1: after a contract, onboarding or
	 * offboarding case, placement, leave request or sickness case is saved,
	 * the HR moments it marks are sent as CloudEvents and typed events.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	private function registerHrLifecycleEventListener(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: HrLifecycleEventListener::class,
				registers: null,
				schemas: HrLifecycleEventService::SLUGS
			);
		}

	}//end registerHrLifecycleEventListener()

	/**
	 * reporting-personnel-budget-and-scenarios D4: a fixed formation scenario
	 * accepts no new or changed mutations.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-002
	 */
	private function registerScenarioMutationListener(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: ScenarioMutationListener::class,
				registers: null,
				schemas: [ScenarioMutationListener::MUTATION_SLUG]
			);
		}

	}//end registerScenarioMutationListener()

	/**
	 * people-record-change-approval D1, D3 and D4: a change request is placed,
	 * decided and applied on its own object events, and a direct update of a
	 * guarded employee field is refused.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-002
	 */
	private function registerChangeRequestListeners(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class, ObjectCreatedEvent::class, ObjectUpdatedEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: ChangeRequestListener::class,
				registers: null,
				schemas: [ChangeRequestListener::REQUEST_SLUG]
			);
		}

		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectUpdatingEvent::class,
			listener: EmployeeGuardedFieldListener::class,
			registers: null,
			schemas: [EmployeeGuardedFieldListener::EMPLOYEE_SLUG]
		);

	}//end registerChangeRequestListeners()

	/**
	 * Register the field access listener (compliance-roles-and-field-access
	 * D3 to D5): before every create and update of the four schemas with
	 * field-level authorization it stamps the account uids the rules match on
	 * and keeps a protected value that the save would otherwise wipe.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-002
	 */
	private function registerFieldAccessListener(IEventDispatcher $dispatcher): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: FieldAccessListener::class,
				registers: null,
				schemas: FieldAccessListener::SLUGS
			);
		}

	}//end registerFieldAccessListener()

}//end class
