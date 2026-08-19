<?php

/**
 * OpenRegister ObjectService NAME stub — test-only.
 *
 * Five classes establish OpenRegister's availability with `class_exists()`
 * rather than through `SettingsService::isOpenRegisterAvailable()`:
 * RosterCheckService, RuleAuditService, RuleTestDataSeeder and the two
 * Lifecycle guards. None of them injects SettingsService, and adding a
 * constructor dependency to a lifecycle guard purely to ask a yes/no question
 * is the wrong trade — ADR-083 lists `class_exists` as an accepted way to
 * establish availability precisely because it answers the same question the DI
 * container would otherwise have answered fatally.
 *
 * In the standalone PHPUnit suite (a bare php:8.3-cli container with no
 * Nextcloud and no OpenRegister) the real class is absent, so that guard would
 * refuse every call and those tests would fail on a missing app rather than on
 * their subject.
 *
 * THIS DECLARES A NAME AND NOTHING ELSE. It is never constructed and never
 * stands in for behaviour: the tests keep injecting their own doubles through
 * the container, exactly as before. If it ever grows a method it has stopped
 * being a marker and started being a fake, and a fake that nobody wrote
 * deliberately is how a suite ends up testing its own stub.
 *
 * Loaded ONLY from tests/bootstrap.php, behind a class_exists() check, so the
 * real OpenRegister class always wins on a live instance. Never in
 * composer.json's autoload map — a stub on an autoloaded path can shadow the
 * real class in production, which is the hazard flagged for the OCP dev-stubs.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Name-only marker for the real OpenRegister ObjectService.
 */
class ObjectService {

}//end class
