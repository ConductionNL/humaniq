<?php

/**
 * Unit tests for OfferSigningRecoveryService.
 *
 * Pins the two docudesk edge-case behaviours extracted out of
 * OfferEsignService (live-docudesk-defects 2026-07-16, to keep
 * OfferEsignService under phpmd's ExcessiveClassComplexity threshold):
 * candidate-email-to-Nextcloud-user resolution (defect-2) and best-effort
 * orphaned-signing-request recovery keyed on BOTH correlationId and
 * documentFileId, never guessing between ambiguous matches (defect-3).
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
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
 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\OfferSigningRecoveryService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for OfferSigningRecoveryService.
 *
 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md
 */
class OfferSigningRecoveryServiceTest extends TestCase {

	/**
	 * A known email resolves to the matching Nextcloud user id.
	 *
	 * @return void
	 */
	public function testResolveCandidateUserIdReturnsUidForKnownEmail(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sanne-nc');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('getByEmail')->with('sanne.voorbeeld@example.org')->willReturn([$user]);

		$service = new OfferSigningRecoveryService($userManager, $this->createMock(LoggerInterface::class));

		$this->assertSame('sanne-nc', $service->resolveCandidateUserId('sanne.voorbeeld@example.org'));

	}//end testResolveCandidateUserIdReturnsUidForKnownEmail()

	/**
	 * An email with no matching Nextcloud user resolves to null.
	 *
	 * @return void
	 */
	public function testResolveCandidateUserIdReturnsNullForUnknownEmail(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('getByEmail')->willReturn([]);

		$service = new OfferSigningRecoveryService($userManager, $this->createMock(LoggerInterface::class));

		$this->assertNull($service->resolveCandidateUserId('unknown@example.org'));

	}//end testResolveCandidateUserIdReturnsNullForUnknownEmail()

	/**
	 * A blank email resolves to null without even calling getByEmail().
	 *
	 * @return void
	 */
	public function testResolveCandidateUserIdReturnsNullForBlankEmail(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects($this->never())->method('getByEmail');

		$service = new OfferSigningRecoveryService($userManager, $this->createMock(LoggerInterface::class));

		$this->assertNull($service->resolveCandidateUserId('   '));

	}//end testResolveCandidateUserIdReturnsNullForBlankEmail()

	/**
	 * Exactly one candidate matching BOTH correlationId and documentFileId
	 * is recovered.
	 *
	 * @return void
	 */
	public function testRecoverOrphanedRequestIdRecoversTheSingleMatch(): void {
		$signingService = new class() {
			public function listRequests(string $callerUserId, bool $isAdmin): array {
				return [
					['id' => 'req-orphan', 'correlationId' => 'app-1', 'documentFileId' => '42', 'status' => 'PENDING'],
					['id' => 'req-unrelated', 'correlationId' => 'app-99', 'documentFileId' => '7', 'status' => 'PENDING'],
				];
			}
		};

		$service = new OfferSigningRecoveryService($this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class));

		$this->assertSame('req-orphan', $service->recoverOrphanedRequestId($signingService, 'app-1', 42));

	}//end testRecoverOrphanedRequestIdRecoversTheSingleMatch()

	/**
	 * No match at all resolves to null.
	 *
	 * @return void
	 */
	public function testRecoverOrphanedRequestIdReturnsNullWhenNoMatch(): void {
		$signingService = new class() {
			public function listRequests(string $callerUserId, bool $isAdmin): array {
				return [];
			}
		};

		$service = new OfferSigningRecoveryService($this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class));

		$this->assertNull($service->recoverOrphanedRequestId($signingService, 'app-1', 42));

	}//end testRecoverOrphanedRequestIdReturnsNullWhenNoMatch()

	/**
	 * Two candidates matching the SAME correlationId+documentFileId pair
	 * are never guessed between -- ambiguity resolves to null.
	 *
	 * @return void
	 */
	public function testRecoverOrphanedRequestIdReturnsNullWhenAmbiguous(): void {
		$signingService = new class() {
			public function listRequests(string $callerUserId, bool $isAdmin): array {
				return [
					['id' => 'req-a', 'correlationId' => 'app-1', 'documentFileId' => '42'],
					['id' => 'req-b', 'correlationId' => 'app-1', 'documentFileId' => '42'],
				];
			}
		};

		$service = new OfferSigningRecoveryService($this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class));

		$this->assertNull($service->recoverOrphanedRequestId($signingService, 'app-1', 42));

	}//end testRecoverOrphanedRequestIdReturnsNullWhenAmbiguous()

	/**
	 * A listRequests() failure (e.g. docudesk itself unreachable) is
	 * logged and resolves to null -- never an uncaught exception.
	 *
	 * @return void
	 */
	public function testRecoverOrphanedRequestIdReturnsNullWhenListRequestsThrows(): void {
		$signingService = new class() {
			public function listRequests(string $callerUserId, bool $isAdmin): array {
				throw new \RuntimeException('listRequests unavailable');
			}
		};

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$service = new OfferSigningRecoveryService($this->createMock(IUserManager::class), $logger);

		$this->assertNull($service->recoverOrphanedRequestId($signingService, 'app-1', 42));

	}//end testRecoverOrphanedRequestIdReturnsNullWhenListRequestsThrows()

}//end class
