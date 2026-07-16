<?php

/**
 * Unit test guarding appinfo/info.xml well-formedness.
 *
 * `simplexml_load_file()` is exactly what Nextcloud's `app:enable` /
 * `OC_App::getAppInfo()` path uses to parse an app's info.xml. A malformed
 * info.xml (e.g. a literal `--` inside an XML comment, which is illegal per
 * the XML spec) makes the app UNINSTALLABLE while every other quality gate
 * stays green, because none of them parse this file. This test closes that
 * gap: it must go red the moment info.xml stops being valid XML.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit
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

namespace OCA\Hrmq\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for appinfo/info.xml validity.
 */
class AppInfoTest extends TestCase
{


    /**
     * info.xml must be well-formed XML, or Nextcloud cannot enable the app.
     *
     * @return void
     */
    public function testInfoXmlIsWellFormedXml(): void
    {
        $path = __DIR__.'/../../appinfo/info.xml';

        $this->assertFileExists($path, 'appinfo/info.xml must exist.');

        // Suppress libxml warnings so a parse failure surfaces as a clean
        // assertion failure instead of PHPUnit converting the warning into
        // an error with a noisy stack trace.
        $useInternalErrors = libxml_use_internal_errors(true);
        $result            = simplexml_load_file($path);
        $errors            = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($useInternalErrors);

        $errorMessages = array_map(
            static function ($error) {
                return trim($error->message).' (line '.$error->line.')';
            },
            $errors
        );

        $this->assertNotFalse(
            $result,
            "appinfo/info.xml failed to parse via simplexml_load_file() - the same call Nextcloud's "
            ."app:enable uses, so this makes the app uninstallable. Parser errors:\n"
            .implode("\n", $errorMessages)
        );
    }


}
