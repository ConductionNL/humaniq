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
use OCA\Humaniq\Lifecycle\CompEffectiveDateGuard;
use OCA\Humaniq\Lifecycle\LeaveBuySellApprovalGuard;
use OCA\Humaniq\Lifecycle\LeaveSettlementPeriodGuard;
use OCA\Humaniq\Lifecycle\NoSelfApprovalGuard;
use OCA\Humaniq\Lifecycle\PayrollRunApprovedGuard;
use OCA\Humaniq\Lifecycle\TimesheetNotEmptyGuard;
use OCA\Humaniq\Listener\TimeEntryStampListener;
use OCA\Humaniq\Listener\TimesheetAggregateListener;
use OCA\Humaniq\Listener\TimesheetApprovalListener;
use OCA\Humaniq\Listener\TimesheetProcessStampListener;
use OCA\Humaniq\Payroll\PackRepository;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\JurisdictionPackService;
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
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
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
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/humaniq-timesheet-approval/spec.md#Requirement:-Process-fields-are-server-stamped-and-inert-to-client-input
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
	 */
	public function boot(IBootContext $context): void {
		$dispatcher = $context->getServerContainer()->get(IEventDispatcher::class);

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

	}//end boot()

}//end class
