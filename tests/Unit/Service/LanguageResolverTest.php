<?php

/**
 * Unit tests for LanguageResolver (setup-wizard-six-steps, REQ-FTS-010).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#requirement-the-taal-step-shall-set-humaniqs-fallback-language-req-fts-010
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\LanguageResolver;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The employee's own language, else the Taal step's fallback, else Dutch.
 */
#[CoversClass(LanguageResolver::class)]
class LanguageResolverTest extends TestCase {

	/**
	 * A resolver over one user's Nextcloud language and the stored fallback.
	 *
	 * @param string       $userLanguage The user's `core/lang`, empty for none.
	 * @param string       $fallback     The stored `default_language`.
	 * @param IFactory|null $factory     The l10n factory.
	 *
	 * @return LanguageResolver
	 */
	private function resolver(string $userLanguage, string $fallback, ?IFactory $factory = null): LanguageResolver {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $userId, string $app, string $key, mixed $default): string => ($userId === 'jdevries' && $app === 'core' && $key === 'lang') ? $userLanguage : (string)$default
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('humaniq', 'default_language', '')->willReturn($fallback);

		return new LanguageResolver($config, $appConfig, $factory ?? $this->createMock(IFactory::class));
	}

	public function testAnEmployeeWithoutALanguageGetsTheFallback(): void {
		$this->assertSame('en', $this->resolver('', 'en')->languageFor(userId: 'jdevries'));
	}

	public function testTheEmployeesOwnLanguageWins(): void {
		$this->assertSame('nl', $this->resolver('nl', 'en')->languageFor(userId: 'jdevries'));
	}

	public function testAnUnansweredTaalStepFallsBackToDutch(): void {
		$this->assertSame('nl', $this->resolver('', '')->languageFor(userId: 'jdevries'));
	}

	public function testAnUnknownStoredFallbackIsNotUsed(): void {
		$this->assertSame('nl', $this->resolver('', 'franchise')->fallback());
	}

	public function testAnEmployeeWithoutAnAccountGetsTheFallback(): void {
		$this->assertSame('en', $this->resolver('de', 'en')->languageFor(userId: ''));
	}

	public function testTheCatalogueIsServedInTheResolvedLanguage(): void {
		$l10n = $this->createMock(IL10N::class);
		$factory = $this->createMock(IFactory::class);
		$factory->expects($this->once())->method('get')->with('humaniq', 'en')->willReturn($l10n);

		$this->assertSame($l10n, $this->resolver('', 'en', $factory)->l10nFor(userId: 'jdevries'));
	}
}
