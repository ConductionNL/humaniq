<?php

/**
 * PHPUnit bootstrap for the hrmq unit suite.
 *
 * Registers Composer's autoloader (which maps OCA\Hrmq\ to lib/ and
 * OCA\Hrmq\Tests\ to tests/) and the OCP namespace from the nextcloud/ocp
 * dev dependency, so the unit suite runs standalone in a bare php:8.3-cli
 * container — no installed Nextcloud server required. When a full server
 * checkout is present (app mounted under custom_apps), its base.php is
 * loaded opportunistically for integration-leaning tests.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

// Define that we're running PHPUnit.
if (defined('PHPUNIT_RUN') === false) {
    define('PHPUNIT_RUN', 1);
}

// Include Composer's autoloader and register the OCP namespace for standalone runs.
$autoloader = require __DIR__.'/../vendor/autoload.php';
if (is_dir(__DIR__.'/../vendor/nextcloud/ocp/OCP') === true) {
    $autoloader->addPsr4('OCP\\', __DIR__.'/../vendor/nextcloud/ocp/OCP/');
    $autoloader->addPsr4('NCU\\', __DIR__.'/../vendor/nextcloud/ocp/NCU/');
}

// Bootstrap Nextcloud when a full server environment is available. The include
// is wrapped in a try/catch so unit tests still run in standalone mode (e.g. a
// bare CI container without an installed Nextcloud).
if (file_exists(__DIR__.'/../../../lib/base.php') === true) {
    try {
        require_once __DIR__.'/../../../lib/base.php';
    } catch (\Throwable $e) {
        // Nextcloud not fully installed — unit tests continue with vendor stubs only.
    }
}
