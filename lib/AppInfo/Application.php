<?php

/**
 * Hrmq Application bootstrap
 *
 * Minimal IBootstrap entry point for the hrmq (HR / payroll) app. It registers the
 * two rule-engine occ commands (`hrmq:rules:audit` / `hrmq:rules:seed-testdata`)
 * and resolves OpenRegister's ObjectService for the compliance services. The app
 * stores no data of its own — all HR/labour objects live in the OpenRegister
 * `hrmq` register, imported by the InitializeRegister repair step.
 *
 * @category AppInfo
 * @package  OCA\Hrmq\AppInfo
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
 * @spec openspec/changes/hrm-rule-engine/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\AppInfo;

use OCA\Hrmq\Command\RulesAuditCommand;
use OCA\Hrmq\Command\RulesSeedTestDataCommand;
use OCA\Hrmq\Lifecycle\CompEffectiveDateGuard;
use OCA\Hrmq\Lifecycle\LeaveBuySellApprovalGuard;
use OCA\Hrmq\Payroll\PackRepository;
use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Service\JurisdictionPackService;
use OCA\Hrmq\Lifecycle\LeaveSettlementPeriodGuard;
use OCA\Hrmq\Lifecycle\NoSelfApprovalGuard;
use OCA\Hrmq\Lifecycle\PayrollRunApprovedGuard;
use OCA\Hrmq\Listener\TimesheetApprovalListener;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The hrmq application bootstrap.
 */
class Application extends App implements IBootstrap
{

    /**
     * The application id.
     *
     * @var string
     */
    public const APP_ID = 'hrmq';


    /**
     * Construct the application.
     */
    public function __construct()
    {
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
     */
    public function register(IRegistrationContext $context): void
    {
        $context->registerService(
            RulesAuditCommand::class,
            static function ($c): RulesAuditCommand {
                return new RulesAuditCommand($c->get(\OCA\Hrmq\Service\RuleAuditService::class));
            }
        );

        $context->registerService(
            RulesSeedTestDataCommand::class,
            static function ($c): RulesSeedTestDataCommand {
                return new RulesSeedTestDataCommand($c->get(\OCA\Hrmq\Service\RuleTestDataSeeder::class));
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
        //
        // Time-entry capture (time-entry-capture): on a Timesheet crossing into
        // `approved`, emit the `nl.conduction.hrmq.timeentry.approved` CloudEvent so a
        // finance app (shillinq) can consume the approved hours for invoice-from-time /
        // WBSO. The listener is a thin OR adapter over TimeEntryEventService; it filters
        // to the Timesheet schema and the approval edge, and is fire-and-forget so a
        // missing consumer never fails the approval write (REQ-TEC-002).
        $context->registerEventListener(
            ObjectUpdatedEvent::class,
            TimesheetApprovalListener::class
        );

    }//end register()


    /**
     * Boot the application. Nothing to do — the app is config + commands only.
     *
     * @param IBootContext $context Boot context.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function boot(IBootContext $context): void
    {

    }//end boot()


}//end class
