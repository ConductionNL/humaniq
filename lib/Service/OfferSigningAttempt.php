<?php

/**
 * Offer Signing Attempt
 *
 * One attempt to raise the docudesk signing request for an offer letter: the
 * Application it is for, the stored letter file, and the candidate's
 * Nextcloud account once resolved. Built and filled by
 * OfferEsignService::createSigningRequest() and read by its steps only.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/offer-esign/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The values the steps of one offer signing request share.
 *
 * @spec openspec/specs/offer-esign/spec.md
 */
final class OfferSigningAttempt {

	/**
	 * The Application id.
	 *
	 * @var string
	 */
	public string $applicationId = '';

	/**
	 * The Application row, as last saved.
	 *
	 * @var array<string, mixed>
	 */
	public array $application = [];

	/**
	 * The Nextcloud file id of the stored offer letter.
	 *
	 * @var int
	 */
	public int $fileId = 0;

	/**
	 * The file name of the stored offer letter.
	 *
	 * @var string
	 */
	public string $fileName = '';

	/**
	 * The candidate's Nextcloud user id.
	 *
	 * @var string
	 */
	public string $candidateUserId = '';
}//end class
