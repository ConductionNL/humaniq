<?php

/**
 * OpenRegister data-subject-request value object stubs — test-only.
 *
 * `AvgDsrService` calls `DataSubjectRequestService::findSubjectData(SubjectQuery
 * $query, ...)` and `erase(ErasureRequest $request)`. In the standalone
 * PHPUnit suite openregister is absent, so these declare the two value
 * objects with openregister's public fields and defaults.
 *
 * Loaded ONLY from tests/bootstrap.php, behind a class_exists() check, so the
 * real openregister classes always win on a live instance. Never in
 * composer.json's autoload map.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Gdpr
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

namespace OCA\OpenRegister\Service\Gdpr;

/**
 * Who a subject-data search is for, and how to match them.
 */
final class SubjectQuery {

	/**
	 * Constructor.
	 *
	 * @param string      $subjectId The subject value.
	 * @param string|null $type      Optional GdprEntity type filter.
	 * @param string      $mode      Match mode.
	 */
	public function __construct(
		public readonly string $subjectId,
		public readonly ?string $type = null,
		public readonly string $mode = 'exact',
	) {

	}//end __construct()
}//end class

/**
 * One erasure: the subject, type filter, erase mode and dry-run switch.
 */
final class ErasureRequest {

	/**
	 * Constructor.
	 *
	 * @param string      $subjectId The subject value.
	 * @param string|null $type      Optional GdprEntity type filter.
	 * @param string      $eraseMode The erase mode.
	 * @param bool        $dryRun    Whether to report without writing.
	 */
	public function __construct(
		public readonly string $subjectId,
		public readonly ?string $type = null,
		public readonly string $eraseMode = 'pseudonymise',
		public readonly bool $dryRun = false,
	) {

	}//end __construct()
}//end class
