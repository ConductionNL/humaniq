<?php

/**
 * Unit tests for OfferApplicationRepository::loadAll().
 *
 * Pins the sync-all backlog scan: every Application row comes back as a plain
 * array (arrays as-is, a JsonSerializable entity serialised, anything else an
 * empty array), an answer that is no list gives no rows, and a failed lookup
 * is logged and gives no rows.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/offer-esign/spec.md#REQ-OFFR-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\OfferApplicationRepository;
use OCA\Humaniq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for OfferApplicationRepository::loadAll().
 */
class OfferApplicationRepositoryLoadAllTest extends TestCase {

	/**
	 * Every row comes back as a plain array, in order.
	 *
	 * @return void
	 */
	public function testEveryRowComesBackAsAPlainArray(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'app-2'];
			}
		};

		$this->assertSame([['id' => 'app-1'], ['id' => 'app-2'], []], $this->repository([['id' => 'app-1'], $entity, 42])->loadAll());

	}//end testEveryRowComesBackAsAPlainArray()

	/**
	 * An answer that is no list gives no rows.
	 *
	 * @return void
	 */
	public function testAnAnswerThatIsNoListGivesNoRows(): void {
		$this->assertSame([], $this->repository('not a list')->loadAll());

	}//end testAnAnswerThatIsNoListGivesNoRows()

	/**
	 * A failed lookup is logged and gives no rows.
	 *
	 * @return void
	 */
	public function testAFailedLookupIsLoggedAndGivesNoRows(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('OfferApplicationRepository: kon Application niet laden: register down');

		$this->assertSame([], $this->repository(null, $logger)->loadAll());

	}//end testAFailedLookupIsLoggedAndGivesNoRows()

	/**
	 * The repository over a fake ObjectService answering one findAll() result.
	 *
	 * @param mixed                $answer The findAll() answer, null to make it throw.
	 * @param LoggerInterface|null $logger The logger, null for a silent mock.
	 *
	 * @return OfferApplicationRepository
	 */
	private function repository(mixed $answer, ?LoggerInterface $logger = null): OfferApplicationRepository {
		$objects = new class($answer) {
			/**
			 * @param mixed $answer The findAll() answer, null to make it throw.
			 */
			public function __construct(private readonly mixed $answer) {
			}//end __construct()

			/**
			 * @param mixed $register The register.
			 *
			 * @return self
			 */
			public function setRegister(mixed $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param mixed $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(mixed $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return mixed
			 *
			 * @throws \RuntimeException When the test makes the lookup fail.
			 */
			public function findAll(array $config = []): mixed {
				if ($this->answer === null) {
					throw new \RuntimeException('register down');
				}

				return $this->answer;
			}//end findAll()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		return new OfferApplicationRepository($container, $settings, ($logger ?? $this->createMock(LoggerInterface::class)));

	}//end repository()

}//end class
