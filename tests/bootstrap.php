<?php

/**
 * PHPUnit bootstrap for hrmq unit tests.
 *
 * The compliance-predicate layer under lib/Standards/ is pure PHP with no
 * Nextcloud (OCP) framework dependency, so this bootstrap does not require a
 * running Nextcloud server or a Composer vendor tree for the app under test — it
 * simply registers a PSR-4 autoloader mapping `OCA\Hrmq\` to `lib/`. When a
 * Composer autoloader is present (a full dev checkout) it is loaded first so the
 * same suite also runs under `composer test:unit`.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

define('PHPUNIT_RUN', 1);

// Prefer Composer's autoloader when the app has a vendor tree (full dev checkout).
$composerAutoload = __DIR__.'/../vendor/autoload.php';
if (is_file($composerAutoload) === true) {
    require $composerAutoload;
}

// Always register a minimal PSR-4 autoloader for the app namespace so the pure
// compliance-predicate classes resolve even without a Composer vendor tree.
spl_autoload_register(
    static function (string $class): void {
        $prefix = 'OCA\\Hrmq\\';
        if (str_starts_with($class, $prefix) === false) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path     = __DIR__.'/../lib/'.str_replace('\\', '/', $relative).'.php';
        if (is_file($path) === true) {
            require $path;
        }
    }
);
