<?php

/**
 * OpenRegister SchemaMapper NAME stub — test-only.
 *
 * AssetDialectMigrationService::schemaMapper() establishes availability with
 * `class_exists('OCA\OpenRegister\Db\SchemaMapper')`, the same ADR-083
 * pattern the sibling `OpenRegisterObjectServiceStub` docblock explains — it
 * answers the same question the DI container would otherwise have answered
 * fatally, without adding a constructor dependency purely to ask a yes/no
 * question.
 *
 * In the standalone PHPUnit suite (a bare php:8.3-cli container with no
 * Nextcloud and no OpenRegister) the real class is absent, so that guard
 * would refuse every call and
 * `AssetDialectMigrationServiceTest::withAssetHardValidationDisabled()`
 * would fail on a missing app rather than on its subject.
 *
 * THIS DECLARES A NAME AND NOTHING ELSE. It is never constructed and never
 * stands in for behaviour: the tests keep injecting their own fake
 * SchemaMapper through the container, exactly as before.
 *
 * Loaded ONLY from tests/bootstrap.php, behind a class_exists() check, so the
 * real OpenRegister class always wins on a live instance. Never in
 * composer.json's autoload map.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Name-only marker for the real OpenRegister SchemaMapper.
 */
class SchemaMapper {

}//end class
