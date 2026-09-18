<?php

/**
 * Leave Type Resolver
 *
 * Turns whatever a `LeaveRequest` carries in `leaveType` into the `LeaveType`
 * object it means.
 *
 * WHY A RESOLVER AND NOT A REFERENCE
 * ----------------------------------
 * `leaveType` was a closed enum: `holiday`, `sick`, `unpaid`, `special`,
 * `care`, `parental`. Every request already stored holds one of those strings.
 * Rewriting the property to a uuid reference would have left each of those
 * requests pointing at nothing, and OpenRegister resolves a relation that
 * points at nothing to null without complaining, so the requests would have
 * kept rendering with an empty kind of leave and nothing would have errored.
 *
 * So the property stays a code, an administered `LeaveType` carries that code,
 * and this class matches the two: uuid first, for anything written as a
 * reference, then code. A request from before the schema existed resolves to
 * the type whose code it already holds, which is exactly what REQ-LVM-T01
 * asks for.
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
 * @spec openspec/specs/leave-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Resolves a stored leave-type value against the administered types.
 *
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-T01
 */
class LeaveTypeResolver {

	/**
	 * The type one request means, or null when nothing matches it.
	 *
	 * @param array<string, mixed> $request The LeaveRequest payload.
	 * @param array<array<string, mixed>> $types The administered LeaveType objects.
	 *
	 * @return array<string, mixed>|null The type, or null.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-T01
	 */
	public function resolve(array $request, array $types): ?array {
		$stored = trim((string)($request['leaveType'] ?? ''));
		if ($stored === '') {
			return null;
		}

		foreach ($types as $type) {
			if (trim((string)($type['id'] ?? '')) === $stored) {
				return $type;
			}
		}

		foreach ($types as $type) {
			if (trim((string)($type['code'] ?? '')) === $stored) {
				return $type;
			}
		}

		return null;
	}//end resolve()

	/**
	 * The types a new request may be given, which is the active ones.
	 *
	 * A retired type is absent here and still resolves in {@see resolve()}, so
	 * withdrawing a kind of leave never makes an old request unreadable.
	 *
	 * @param array<array<string, mixed>> $types The administered LeaveType objects.
	 *
	 * @return array<int, array<string, mixed>> The offerable types.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-T01
	 */
	public function offerable(array $types): array {
		$offerable = [];
		foreach ($types as $type) {
			if (($type['active'] ?? true) === false) {
				continue;
			}

			$offerable[] = $type;
		}

		return $offerable;
	}//end offerable()

	/**
	 * Whether approving a request of this type moves a leave balance.
	 *
	 * An unresolved type draws from the balance, which is the behaviour every
	 * request had before types existed: a kind of leave nobody administered is
	 * not a reason to stop counting somebody's holiday.
	 *
	 * @param array<string, mixed>|null $type The resolved LeaveType, or null.
	 *
	 * @return bool True when the approval should post to the balance.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-T01
	 */
	public function drawsFromBalance(?array $type): bool {
		if ($type === null) {
			return true;
		}

		return (($type['drawsFromBalance'] ?? true) !== false);
	}//end drawsFromBalance()
}//end class
