<?php

/**
 * Contract of ShippedFlowPublisher
 *
 * The public surface of ShippedFlowPublisher that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

/**
 * Contract of ShippedFlowPublisher.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ShippedFlowPublisherInterface {
	/**
	 * Publish what needs publishing and report per flow.
	 *
	 * @return array<int, array{flow: string, status: string, detail: string}> One report per declared flow,
	 *                                                                          or one `skipped` report without OpenRegister.
	 *
	 * @spec openspec/changes/platform-hr-workflows/specs/hr-workflows/spec.md#REQ-HWF-001
	 */
	public function publishAll(): array;
}//end interface
