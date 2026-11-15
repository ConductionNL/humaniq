<?php

/**
 * FieldReadAccess tests
 *
 * Pins the call into OpenRegister's property RBAC: canReadProperty() takes the
 * schema and property as one SchemaProperty and the stored object as a
 * PropertyAccessRequest. The earlier three-argument call was a type error
 * that the old catch-all turned into "OpenRegister could not be asked", so
 * every nulled field was carried forward and no governed field was ever
 * reported unreadable.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\FieldReadAccess;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\OpenRegister\Service\PropertyAccessRequest;
use OCA\OpenRegister\Service\SchemaProperty;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for FieldReadAccess.
 */
class FieldReadAccessTest extends TestCase {

	/**
	 * What the handler double was asked: property name => object.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $asked = [];

	/**
	 * A FieldReadAccess over a schema governing bsn and iban, and a handler
	 * that hides bsn from the caller.
	 *
	 * @return FieldReadAccess
	 */
	private function access(): FieldReadAccess {
		$schemas = new class {

			/**
			 * @param mixed $id The schema id.
			 *
			 * @return object
			 */
			public function find(mixed $id): object {
				return new class {

					/**
					 * @return array<string, array<string, mixed>>
					 */
					public function getPropertiesWithAuthorization(): array {
						return ['bsn' => ['read' => ['humaniq-hr']], 'iban' => ['read' => ['humaniq-hr']]];
					}
				};
			}
		};

		$test = $this;
		$handler = new class($test) {

			/**
			 * @param FieldReadAccessTest $test Records the questions asked.
			 */
			public function __construct(private FieldReadAccessTest $test) {
			}

			/**
			 * @param SchemaProperty        $property The schema and the property.
			 * @param PropertyAccessRequest $request  The stored object.
			 *
			 * @return bool
			 */
			public function canReadProperty(SchemaProperty $property, PropertyAccessRequest $request): bool {
				$this->test->asked[$property->name] = $request->object;
				return $property->name !== 'bsn';
			}
		};

		$container = new FakeContainer(
			[
				'OCA\OpenRegister\Db\SchemaMapper' => $schemas,
				'OCA\OpenRegister\Service\PropertyRbacHandler' => $handler,
			]
		);

		return new FieldReadAccess(container: $container, logger: new NullLogger());
	}//end access()

	/**
	 * The handler is asked once per governed property, with the stored
	 * object, and the hidden one is reported.
	 *
	 * @return void
	 */
	public function testTheHandlerIsAskedInItsCurrentShape(): void {
		$object = ['bsn' => '123456782', 'iban' => 'NL91ABNA0417164300'];

		$this->assertSame(['bsn'], $this->access()->unreadable(schemaId: 'employee', object: $object));
		$this->assertSame(['bsn' => $object, 'iban' => $object], $this->asked);
	}//end testTheHandlerIsAskedInItsCurrentShape()

	/**
	 * Only the unreadable field's stored value is carried forward, not every
	 * nulled one.
	 *
	 * @return void
	 */
	public function testOnlyTheUnreadableFieldIsCarriedForward(): void {
		$old = ['bsn' => '123456782', 'iban' => 'NL91ABNA0417164300'];
		$new = ['bsn' => null, 'iban' => null];

		$this->assertSame(['bsn' => '123456782'], $this->access()->carriedForward(schemaId: 'employee', old: $old, new: $new));
	}//end testOnlyTheUnreadableFieldIsCarriedForward()
}//end class
