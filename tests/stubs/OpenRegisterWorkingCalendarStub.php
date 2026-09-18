<?php

/**
 * OpenRegister WorkingCalendarService NAME stub — test-only.
 *
 * `WorkingCalendarReader` establishes openregister's availability with
 * `class_exists()` before reaching for the working calendar (ADR-083), the way
 * every other duck-typed reach in humaniq does. In the standalone PHPUnit
 * suite the real class is absent, so the guard would refuse every call and the
 * resolved path could never be exercised: the tests would all measure a
 * missing app rather than the reader.
 *
 * THIS DECLARES A NAME AND NOTHING ELSE. It carries no `nonWorkingDates()`, so
 * a test that wants an answer injects its own double through the container,
 * and a test that wants the method-missing degradation gets it from this class
 * exactly as a real openregister that dropped the method would give it.
 *
 * Loaded ONLY from tests/bootstrap.php, behind a class_exists() check, so the
 * real openregister class always wins on a live instance. Never in
 * composer.json's autoload map.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * Name-only stand-in for openregister's working calendar service.
 */
class WorkingCalendarService {

}//end class
