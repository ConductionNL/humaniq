<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcDialog
		:name="title"
		:open="true"
		size="normal"
		@update:open="onOpen">
		<div class="hq-cycle-run" data-testid="hq-cycle-run">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcSelect
				v-if="picksCycle"
				v-model="chosenCycle"
				:options="cycles"
				label="name"
				:inputLabel="t('humaniq', 'Cycle')"
				:placeholder="t('humaniq', 'Choose an open cycle')"
				:loading="loadingCycles"
				data-testid="hq-cycle-run-cycle"
				@update:modelValue="runPreview" />

			<p v-if="picksCycle">
				{{ t('humaniq', 'Employees selected: {count}', { count: selectedIds.length }) }}
			</p>

			<NcLoadingIcon v-if="loading" :name="t('humaniq', 'Loading')" />

			<template v-if="done">
				<p role="status" data-testid="hq-cycle-run-done">
					{{ doneText }}
				</p>
			</template>

			<template v-else-if="step === 'approve'">
				<p>{{ t('humaniq', 'You approve every proposed change in this cycle that you did not propose yourself. Your own proposals stay open for a colleague.') }}</p>
				<NcTextField
					v-model="reason"
					:label="t('humaniq', 'Reason for the employees (optional)')"
					data-testid="hq-cycle-run-reason" />
			</template>

			<template v-else-if="preview && step === 'propose'">
				<ul class="hq-cycle-run__counts" data-testid="hq-cycle-run-preview">
					<li>{{ t('humaniq', 'Proposals to create: {count}', { count: preview.wouldCreate }) }}</li>
					<li v-if="preview.alreadyPresent > 0">
						{{ t('humaniq', 'Already proposed: {count}', { count: preview.alreadyPresent }) }}
					</li>
					<li v-if="preview.skipped.length > 0">
						{{ t('humaniq', 'Left out: {count}', { count: preview.skipped.length }) }}
					</li>
				</ul>
				<table v-if="preview.rows.length > 0" class="hq-cycle-run__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('humaniq', 'Employee') }}
							</th>
							<th scope="col">
								{{ t('humaniq', 'Now') }}
							</th>
							<th scope="col">
								{{ t('humaniq', 'Proposed') }}
							</th>
							<th scope="col">
								{{ t('humaniq', 'From') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in preview.rows" :key="row.employeeId">
							<td>{{ row.name || row.employeeId }}</td>
							<td>{{ money(row.currentSalary) }}</td>
							<td>
								{{ money(row.proposedSalary) }}
								<span v-if="row.toStep !== null">{{ t('humaniq', 'step {from} to {to}', { from: row.fromStep, to: row.toStep }) }}</span>
							</td>
							<td>{{ row.effectiveDate }}</td>
						</tr>
					</tbody>
				</table>
			</template>

			<template v-else-if="preview && step === 'effectuate'">
				<ul class="hq-cycle-run__counts" data-testid="hq-cycle-run-preview">
					<li>{{ t('humaniq', 'Salaries to write: {count}', { count: dueCount }) }}</li>
					<li v-if="notDueCount > 0">
						{{ t('humaniq', 'Not due yet, staying approved: {count}', { count: notDueCount }) }}
					</li>
					<li v-if="otherCount > 0">
						{{ t('humaniq', 'Not approved or not writable: {count}', { count: otherCount }) }}
					</li>
				</ul>
			</template>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="close">
				{{ done ? t('humaniq', 'Close') : t('humaniq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!done"
				variant="primary"
				:disabled="!canConfirm"
				data-testid="hq-cycle-run-confirm"
				@click="confirm">
				{{ confirmLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { dispatchAction } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'

/**
 * CompCycleRunDialog: run one step of a compensation cycle for many people.
 *
 * Opened from the cycle page (propose, approve, effectuate) and from a
 * selection on the Employees list (propose for the selected people into an
 * open cycle). Propose and effectuate first show a dry run of what will be
 * written; the confirmed call goes through the library's api-call dispatch so
 * the page refreshes and the toast matches every other action.
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-002
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-003
 */
export default {
	name: 'CompCycleRunDialog',

	components: { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField },

	props: {
		/** Which step: 'propose', 'approve' or 'effectuate'. */
		step: { type: String, default: 'propose' },
		/** The cycle; empty on the cycle page (read from the route) and for a selection (chosen here). */
		cycleId: { type: String, default: '' },
		/** Employees picked on the Employees list; replaces the cycle's scope. */
		selectedIds: { type: Array, default: () => [] },
	},

	emits: ['close'],

	data() {
		return {
			loading: false,
			loadingCycles: false,
			error: '',
			preview: null,
			done: null,
			reason: '',
			cycles: [],
			chosenCycle: null,
		}
	},

	computed: {
		/**
		 * @return {boolean} Whether the person picks the cycle here.
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		picksCycle() {
			return this.selectedIds.length > 0 && this.cycleId === ''
		},

		/**
		 * @return {string} The cycle the step runs on.
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		targetCycleId() {
			if (this.cycleId !== '') {
				return this.cycleId
			}
			if (this.picksCycle) {
				return this.chosenCycle?.id ?? ''
			}
			return String(this.$route?.params?.id ?? '')
		},

		/**
		 * @return {string} The dialog title.
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		title() {
			if (this.step === 'approve') {
				return t('humaniq', 'Approve all proposed changes')
			}
			if (this.step === 'effectuate') {
				return t('humaniq', 'Put approved changes into effect')
			}
			return this.picksCycle ? t('humaniq', 'Propose a raise') : t('humaniq', 'Propose for everyone in scope')
		},

		/**
		 * @return {string} The confirm button text.
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		confirmLabel() {
			if (this.step === 'approve') {
				return t('humaniq', 'Approve all')
			}
			if (this.step === 'effectuate') {
				return t('humaniq', 'Put into effect')
			}
			return t('humaniq', 'Create proposals')
		},

		/**
		 * @return {boolean} Whether confirming would do something.
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		canConfirm() {
			if (this.loading || this.targetCycleId === '') {
				return false
			}
			if (this.step === 'approve') {
				return true
			}
			if (this.step === 'effectuate') {
				return this.preview !== null && this.dueCount > 0
			}
			return this.preview !== null && this.preview.wouldCreate > 0
		},

		/**
		 * @return {number} Adjustments the effectuation would write.
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		dueCount() {
			return this.preview?.counts?.['would-apply'] ?? 0
		},

		/**
		 * @return {number} Approved adjustments that are not due yet.
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		notDueCount() {
			return this.preview?.counts?.['refused-not-due'] ?? 0
		},

		/**
		 * @return {number} Every other outcome, already-effective excluded.
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		otherCount() {
			const counts = this.preview?.counts ?? {}
			return Object.keys(counts)
				.filter((status) => !['would-apply', 'refused-not-due', 'already-effective'].includes(status))
				.reduce((sum, status) => sum + counts[status], 0)
		},

		/**
		 * @return {string} What the confirmed call did.
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		doneText() {
			const d = this.done ?? {}
			if (this.step === 'approve') {
				return t('humaniq', 'Approved: {approved}. Left for a colleague: {own}. Refused: {failed}.', {
					approved: d.approved ?? 0,
					own: d.refusedSelfApproval ?? 0,
					failed: d.failed ?? 0,
				})
			}
			if (this.step === 'effectuate') {
				return t('humaniq', 'Salaries written: {count}', { count: d.counts?.applied ?? 0 })
			}
			return t('humaniq', 'Proposals created: {count}', { count: d.created ?? 0 })
		},
	},

	/**
	 * Load the cycles for a selection, or run the preview on the cycle page.
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
	 */
	mounted() {
		if (this.picksCycle) {
			this.loadCycles()
			return
		}
		this.runPreview()
	},

	methods: {
		t,

		/**
		 * Load the open cycles a selection can be proposed into.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		async loadCycles() {
			this.loadingCycles = true
			try {
				const url = generateUrl('/apps/openregister/api/objects/humaniq/CompReviewCycle')
				const { data } = await axios.get(url, { params: { status: 'open', _limit: 100 } })
				const rows = Array.isArray(data?.results) ? data.results : (Array.isArray(data) ? data : [])
				this.cycles = rows
					.filter((row) => ['collective', 'step-increase'].includes(row.kind))
					.map((row) => ({ id: row.id ?? row['@self']?.id, name: row.name }))
			} catch {
				this.error = t('humaniq', 'Could not load the open cycles. Try again.')
			} finally {
				this.loadingCycles = false
			}
		},

		/**
		 * Ask the server what the step would do, writing nothing.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		async runPreview() {
			this.preview = null
			this.error = ''
			if (this.step === 'approve' || this.targetCycleId === '') {
				return
			}
			this.loading = true
			try {
				const { data } = await axios.post(generateUrl(this.endpoint()), this.payload(true))
				this.preview = data
			} catch (e) {
				this.error = e?.response?.data?.message || e?.response?.data?.error || t('humaniq', 'Could not work out what this would do. Try again.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Run the step for real.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		async confirm() {
			this.loading = true
			const result = await dispatchAction({
				type: 'api-call',
				url: this.endpoint(),
				method: 'POST',
				payload: this.payload(false),
				successMessage: t('humaniq', 'Done. The cycle has been updated.'),
				errorMessage: t('humaniq', 'This did not work. Nothing was changed.'),
			}, { translate: (key) => key })
			this.loading = false
			if (result?.ok) {
				this.done = result.data ?? {}
			}
		},

		/**
		 * @return {string} The endpoint for this step.
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		endpoint() {
			return `/apps/humaniq/api/comp/cycles/${this.step}`
		},

		/**
		 * @param {boolean} dryRun Whether this is the preview.
		 * @return {object} The request body.
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		payload(dryRun) {
			const body = { cycleId: this.targetCycleId }
			if (this.step === 'approve') {
				if (this.reason.trim() !== '') {
					body.decisionReason = this.reason.trim()
				}
				return body
			}
			body.dryRun = dryRun
			if (this.picksCycle) {
				body.employeeIds = this.selectedIds
			}
			return body
		},

		/**
		 * @param {number|null} cents An amount in cents.
		 * @return {string} The amount in euros.
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		money(cents) {
			if (typeof cents !== 'number') {
				return ''
			}
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR' }).format(cents / 100)
		},

		/**
		 * @param {boolean} open The dialog's open state.
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		onOpen(open) {
			if (!open) {
				this.close()
			}
		},

		/**
		 * Tell the host to close the dialog.
		 *
		 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-001
		 */
		close() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.hq-cycle-run {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.hq-cycle-run__counts {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
	list-style: disc;
}

.hq-cycle-run__table {
	width: 100%;
	border-collapse: collapse;
}

.hq-cycle-run__table th,
.hq-cycle-run__table td {
	padding: calc(var(--default-grid-baseline) * 1) calc(var(--default-grid-baseline) * 2);
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}
</style>
