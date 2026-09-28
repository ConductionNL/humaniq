/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import { createApp, h } from 'vue'
import CompCycleRunDialog from './CompCycleRunDialog.vue'

/**
 * Open the "Propose a raise" dialog for the employees selected on the
 * Employees list.
 *
 * Registered as a `kind: 'handler'` bulk action, because the library's
 * `open-modal` bulk type (CnIndexPage `openBulkModal`) only emits an
 * `open-modal` event that no page renderer forwards to CnAppRoot's modal host
 * in @conduction/nextcloud-vue 2.57.1. A handler is resolved and called, so it
 * mounts the same dialog the cycle page uses, with the selection it was given,
 * and removes it again on close.
 *
 * @param {{selectedIds: Array<string>}} scope The selection strip's payload.
 * @return {void}
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 */
export function proposeRaiseForSelection(scope) {
	const selectedIds = Array.isArray(scope?.selectedIds) ? scope.selectedIds.map(String) : []
	if (selectedIds.length === 0) {
		return
	}

	const host = document.createElement('div')
	document.body.appendChild(host)
	const app = createApp({
		render: () => h(CompCycleRunDialog, {
			step: 'propose',
			selectedIds,
			onClose: () => {
				app.unmount()
				host.remove()
			},
		}),
	})
	app.mount(host)
}
