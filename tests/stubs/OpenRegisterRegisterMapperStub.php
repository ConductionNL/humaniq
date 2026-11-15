<?php

/**
 * OpenRegister RegisterMapper NAME stub — test-only.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `RegisterSlugLookup::slugFromRegisterTable()` establishes availability with
 * `class_exists('OCA\OpenRegister\Db\RegisterMapper')`, the same ADR-083
 * pattern the sibling `OpenRegisterSchemaMapperStub` docblock explains. In the
 * standalone PHPUnit suite (a bare php:8.3-cli container with no Nextcloud and
 * no OpenRegister) the real class is absent, so that guard would refuse every
 * call and no test could reach the branch that decides what an instance with a
 * pre-contract OpenRegister does.
 *
 * THIS DECLARES A NAME AND NOTHING ELSE. It is never constructed and never
 * stands in for behaviour: the tests inject their own fake register mapper
 * through the container, and the lookup reaches it duck-typed with
 * `method_exists()`.
 *
 * Loaded ONLY from tests/bootstrap.php, behind a class_exists() check, so the
 * real OpenRegister class always wins on a live instance. Never in
 * composer.json's autoload map.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Name-only marker for the real OpenRegister RegisterMapper.
 */
class RegisterMapper {

}//end class
