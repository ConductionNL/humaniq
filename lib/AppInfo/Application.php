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
use OCA\Hrmq\Lifecycle\NoSelfApprovalGuard;
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

        // OpenRegister's ObjectService is registered in the server container and the
        // compliance services resolve it lazily via $container->get('OCA\\OpenRegister
        // \\Service\\ObjectService'); no app-level alias is needed (a self-alias would
        // recurse). When OpenRegister is absent the lazy get() throws and fails soft.

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
