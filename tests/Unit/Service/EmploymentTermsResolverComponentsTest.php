<?php

/**
 * EmploymentTermsResolver::resolveComponents: which CAO components a contract gets.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit
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
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-001
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Humaniq\Service\EmploymentTermsResolver;
use OCA\Humaniq\Standards\CaoRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The contract names its components; an override needs a reason and may not
 * be worse than the agreement.
 */
class EmploymentTermsResolverComponentsTest extends TestCase {

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		CaoRegistry::reset();
	}//end setUp()

	/**
	 * A contract under the example agreement naming both its components.
	 *
	 * @param array<string, mixed> $extra Extra contract fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function contract(array $extra = []): array {
		return array_merge(['cao' => 'cao-voorbeeld', 'caoComponents' => ['ploegentoeslag', 'nachttoeslag']], $extra);
	}//end contract()

	/**
	 * The agreement's components resolve with source cao.
	 *
	 * @return void
	 */
	public function testTheAgreementsComponentsResolve(): void {
		$resolved = (new EmploymentTermsResolver())->resolveComponents(self::contract());

		$this->assertSame(['ploegentoeslag', 'nachttoeslag'], array_column($resolved['components'], 'key'));
		$this->assertSame(['cao', 'cao'], array_column($resolved['components'], 'source'));
		$this->assertSame(10.0, $resolved['components'][0]['pct']);
		$this->assertSame([], $resolved['unresolved']);
	}//end testTheAgreementsComponentsResolve()

	/**
	 * An override below the agreement is refused.
	 *
	 * @return void
	 */
	public function testAnOverrideBelowTheAgreementIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('below');
		(new EmploymentTermsResolver())->resolveComponents(self::contract(['caoComponentOverrides' => ['ploegentoeslag' => ['pct' => 8]], 'caoComponentOverrideReason' => 'lower by agreement']));
	}//end testAnOverrideBelowTheAgreementIsRefused()

	/**
	 * An override above the agreement without a reason is refused.
	 *
	 * @return void
	 */
	public function testAnOverrideWithoutAReasonIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('reason');
		(new EmploymentTermsResolver())->resolveComponents(self::contract(['caoComponentOverrides' => ['ploegentoeslag' => ['pct' => 15]]]));
	}//end testAnOverrideWithoutAReasonIsRefused()

	/**
	 * A night window below the agreement's is refused too.
	 *
	 * @return void
	 */
	public function testAWindowBelowTheAgreementIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$windows = [['days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'from' => '00:00', 'to' => '06:00', 'pct' => 30]];
		(new EmploymentTermsResolver())->resolveComponents(self::contract(['caoComponentOverrides' => ['nachttoeslag' => ['windows' => $windows]], 'caoComponentOverrideReason' => 'x']));
	}//end testAWindowBelowTheAgreementIsRefused()

	/**
	 * An override above the agreement with a reason is the contract's figure.
	 *
	 * @return void
	 */
	public function testAnOverrideWithAReasonImproves(): void {
		$resolved = (new EmploymentTermsResolver())->resolveComponents(self::contract(['caoComponentOverrides' => ['ploegentoeslag' => ['pct' => 15]], 'caoComponentOverrideReason' => 'agreed at hiring']));

		$this->assertSame(15.0, $resolved['components'][0]['pct']);
		$this->assertSame('contract-override', $resolved['components'][0]['source']);
		$this->assertSame('cao', $resolved['components'][1]['source']);
	}//end testAnOverrideWithAReasonImproves()

	/**
	 * A placeholder agreement resolves nothing: the named component is
	 * unresolved, never a guessed percentage; an override with a reason pays
	 * it meanwhile; a key the agreement lacks is unknown.
	 *
	 * @return void
	 */
	public function testAPlaceholderResolvesNothingUnlessOverridden(): void {
		$resolver = new EmploymentTermsResolver();
		$placeholder = $resolver->resolveComponents(['cao' => 'cao-metaal-techniek', 'caoComponents' => ['ploegentoeslag', 'bestaat-niet']]);
		$this->assertSame([], $placeholder['components']);
		$this->assertSame(['ploegentoeslag', 'bestaat-niet'], $placeholder['unresolved']);
		$this->assertSame(['bestaat-niet'], $placeholder['unknown']);

		$overridden = $resolver->resolveComponents(['cao' => 'cao-metaal-techniek', 'caoComponents' => ['ploegentoeslag'], 'caoComponentOverrides' => ['ploegentoeslag' => ['pct' => 13.3]], 'caoComponentOverrideReason' => 'the CAO text, art. 12']);
		$this->assertSame('percentage-of-wage', $overridden['components'][0]['kind']);
		$this->assertSame(13.3, $overridden['components'][0]['pct']);
		$this->assertSame([], $overridden['unresolved']);
	}//end testAPlaceholderResolvesNothingUnlessOverridden()

	/**
	 * A contract naming no component, or no agreement, gets nothing.
	 *
	 * @return void
	 */
	public function testNoComponentsNamedIsEmpty(): void {
		$resolver = new EmploymentTermsResolver();
		$this->assertSame(['components' => [], 'unresolved' => [], 'unknown' => []], $resolver->resolveComponents(['cao' => 'cao-voorbeeld']));
		$this->assertSame(['ploegentoeslag'], $resolver->resolveComponents(['caoComponents' => ['ploegentoeslag']])['unknown']);
	}//end testNoComponentsNamedIsEmpty()

}//end class
