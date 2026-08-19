<?php

/**
 * Unit test guarding appinfo/info.xml well-formedness.
 *
 * A malformed info.xml (e.g. a literal `--` inside an XML comment, which is
 * illegal per the XML spec) makes the app UNINSTALLABLE while every other
 * quality gate stays green, because none of them parse this file. This test
 * closes that gap: it must go red the moment info.xml stops being valid XML.
 *
 * ⚠️ It parses with `simplexml_load_string(file_get_contents(...))`, NOT
 * `simplexml_load_file()`, and the difference is the whole reason this note
 * exists. It used to use the latter, on the stated grounds that it was "the
 * same call Nextcloud's app:enable uses". It is not: `OC\App\InfoParser`
 * (lib/private/App/InfoParser.php) reads the file and calls
 * `simplexml_load_string()`.
 *
 * That mattered the moment CI existed. `tests/bootstrap.php` requires the
 * server's `lib/base.php` whenever a full checkout is present — which is
 * always true under the shared quality workflow, where the app is mounted at
 * `server/apps/hrmq` — and base.php hardens libxml with
 * `libxml_set_external_entity_loader(static fn () => null)`. Under that loader
 * `simplexml_load_file()` resolves even the PRIMARY document through the
 * resolver and so returns false with "Failed to load external entity because
 * the resolver function returned null (line 0)", for a perfectly valid file.
 * `simplexml_load_string()` takes the bytes directly and is unaffected.
 *
 * So the test passed on every developer machine (no server checkout, no
 * hardening) and failed on all six PHPUnit legs of hrmq's first-ever CI run,
 * reporting "this makes the app uninstallable" — nine seconds after
 * `occ app:enable hrmq` had printed "hrmq 0.2.0 enabled" in the same job.
 * A false RED that named the opposite of the truth.
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
class AppInfoTest extends TestCase {

	/**
	 * info.xml must be well-formed XML, or Nextcloud cannot enable the app.
	 *
	 * @return void
	 */
	public function testInfoXmlIsWellFormedXml(): void {
		$path = __DIR__ . '/../../appinfo/info.xml';

		$this->assertFileExists($path, 'appinfo/info.xml must exist.');

		// Suppress libxml warnings so a parse failure surfaces as a clean
		// assertion failure instead of PHPUnit converting the warning into
		// an error with a noisy stack trace.
		$useInternalErrors = libxml_use_internal_errors(true);
		// Mirrors OC\App\InfoParser::parse() exactly — see the class docblock
		// for why load_file() cannot be used here.
		$result = simplexml_load_string(file_get_contents($path));
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($useInternalErrors);

		$errorMessages = array_map(
			static function ($error) {
				return trim($error->message) . ' (line ' . $error->line . ')';
			},
			$errors
		);

		$this->assertNotFalse(
			$result,
			'appinfo/info.xml failed to parse via simplexml_load_string() - the same call '
			. 'OC\\App\\InfoParser uses on app:enable, so this makes the app uninstallable. '
			. "Parser errors:\n"
			. implode("\n", $errorMessages)
		);
	}

}
