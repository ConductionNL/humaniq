/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * Register attendance for every training record selected on the Trainings
 * list, through OpenRegister's transition endpoint, one record at a time.
 *
 * The transition endpoint is the one CnLifecycleActions posts to on the
 * detail page, so the lifecycle check and TrainingRecordListener (which
 * fills in the dates and grants the competence) run exactly as they do there.
 * A record that cannot make the transition (already attended, or not
 * attended and asked to be not attended again) is counted, not retried.
 *
 * @param {{selectedIds: Array<string>}} scope The selection strip's payload.
 * @param {string} action The lifecycle action.
 * @return {Promise<{done: number, failed: number}>} How many records moved.
 *
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-001
 */
export async function registerTrainingAttendance(scope, action) {
	const selectedIds = Array.isArray(scope?.selectedIds) ? scope.selectedIds.map(String) : []
	let done = 0
	let failed = 0
	for (const id of selectedIds) {
		try {
			await axios.post(generateUrl('/apps/openregister/api/objects/{id}/transition', { id }), { action })
			done++
		} catch {
			failed++
		}
	}

	if (done > 0) {
		showSuccess(t('humaniq', 'Trainings registered: {count}.', { count: done }))
	}
	if (failed > 0) {
		showError(t('humaniq', 'Trainings not registered, because they already have this status or could not be changed: {count}.', { count: failed }))
	}
	if (done === 0 && failed === 0) {
		showError(t('humaniq', 'Select the trainings to register first.'))
	}

	return { done, failed }
}

/**
 * The "Register as attended" bulk action on the Trainings list.
 *
 * @param {{selectedIds: Array<string>}} scope The selection strip's payload.
 * @return {Promise<{done: number, failed: number}>} How many records moved.
 *
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-001
 */
export function registerTrainingAttended(scope) {
	return registerTrainingAttendance(scope, 'registreren-gevolgd')
}

/**
 * The "Register as not attended" bulk action on the Trainings list.
 *
 * @param {{selectedIds: Array<string>}} scope The selection strip's payload.
 * @return {Promise<{done: number, failed: number}>} How many records moved.
 *
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-001
 */
export function registerTrainingNotAttended(scope) {
	return registerTrainingAttendance(scope, 'registreren-niet-gevolgd')
}
