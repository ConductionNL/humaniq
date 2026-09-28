<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 EmployeeHistoryWidget (people-employment-history REQ-EHI-001, REQ-EHI-003).

 Two views on EmployeeDetail, chosen by the manifest's `content.view`:
 - `history`: the employee's dated events from GET /api/employees/{id}/history,
   newest first, in the library's CnTimelineView, each linking to its record.
 - `employments`: the contracts active today from
   GET /api/employees/{id}/employments, side by side with scale, hours, CAO
   and type, their summed hours and FTE, and the year's leave balance once.
 Ordering, filtering and access all happen server side; this widget only
 renders.
-->
<template>
	<div class="employee-history">
		<NcLoadingIcon v-if="loading" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<CnTimelineView
			v-else-if="view === 'history'"
			:events="events"
			sort="desc"
			hideCounts
			:groupBy="byYear"
			:emptyLabel="t('humaniq', 'No history yet')">
			<template #event="{ event }">
				<router-link :to="{ name: event.route, params: { id: event.id } }" class="employee-history__event">
					<span class="employee-history__date">{{ dateRange(event) }}</span>
					<span class="employee-history__title">{{ kindLabel(event.kind) }}</span>
					<small v-if="event.detail" class="employee-history__detail">{{ event.detail }}</small>
				</router-link>
			</template>
		</CnTimelineView>
		<div v-else class="employee-history__employments">
			<p v-if="employments.contracts.length === 0">
				{{ t('humaniq', 'No contract is active today.') }}
			</p>
			<table v-else>
				<caption class="hidden-visually">
					{{ t('humaniq', 'Contracts active on {date}', { date: employments.date }) }}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('humaniq', 'Type') }}
						</th>
						<th scope="col">
							{{ t('humaniq', 'Collective agreement') }}
						</th>
						<th scope="col">
							{{ t('humaniq', 'Scale') }}
						</th>
						<th scope="col">
							{{ t('humaniq', 'Hours per week') }}
						</th>
						<th scope="col">
							{{ t('humaniq', 'FTE') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="contract in employments.contracts" :key="contract.id">
						<th scope="row">
							<router-link :to="{ name: 'EmploymentContractDetail', params: { id: contract.id } }">
								{{ contract.type || t('humaniq', 'Contract') }}
							</router-link>
						</th>
						<td>{{ contract.cao || '' }}</td>
						<td>{{ contract.caoSchaal || '' }}</td>
						<td>{{ number(contract.hoursPerWeek) }}</td>
						<td>{{ number(contract.fte) }}</td>
					</tr>
				</tbody>
				<tfoot>
					<tr>
						<th scope="row">
							{{ t('humaniq', 'Total') }}
						</th>
						<td />
						<td />
						<td>{{ number(employments.totalHoursPerWeek) }}</td>
						<td>{{ number(employments.totalFte) }}</td>
					</tr>
				</tfoot>
			</table>
			<p class="employee-history__note">
				{{ t('humaniq', 'FTE is counted on a full-time week of {hours} hours.', { hours: employments.fullTimeHoursPerWeek }) }}
			</p>
			<h4>{{ t('humaniq', 'Leave balance {year}', { year: employments.date.slice(0, 4) }) }}</h4>
			<p class="employee-history__note">
				{{ t('humaniq', 'Leave is kept per person, not per contract.') }}
			</p>
			<ul v-if="employments.leaveBalances.length > 0">
				<li v-for="balance in employments.leaveBalances" :key="balance.id || balance.leaveType">
					{{ t('humaniq', '{type}: {left} of {entitled} hours left', { type: balance.leaveType, left: number(remaining(balance)), entitled: number(entitled(balance)) }) }}
				</li>
			</ul>
			<p v-else>
				{{ t('humaniq', 'No leave balance for this year yet.') }}
			</p>
		</div>
	</div>
</template>

<script>
import { CnTimelineView } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'EmployeeHistoryWidget',

	components: { CnTimelineView, NcLoadingIcon, NcNoteCard },

	props: {
		/** The employee's id, handed in by CnDetailWidgetHost's detail context (the host draws the title chrome). */
		objectId: { type: [String, Number], default: '' },
		/** The manifest's `{ view: 'history' | 'employments' }`. */
		content: { type: Object, default: () => ({}) },
	},

	data() {
		return {
			loading: true,
			error: '',
			events: [],
			employments: { date: '', contracts: [], leaveBalances: [], totalHoursPerWeek: 0, totalFte: 0, fullTimeHoursPerWeek: 40 },
		}
	},

	computed: {
		/**
		 * Which view the manifest asked for: `employments` or `history`.
		 *
		 * @return {string}
		 *
		 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-001
		 */
		view() {
			return this.content?.view === 'employments' ? 'employments' : 'history'
		},
	},

	watch: {
		/**
		 * Reload when the page moves to another employee.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-001
		 */
		objectId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read this employee's history or employments.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-001
		 */
		async load() {
			if (!this.objectId) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const url = generateUrl('/apps/humaniq/api/employees/{id}/' + this.view, { id: this.objectId })
				const response = await axios.get(url)
				if (this.view === 'history') {
					this.events = response.data.events || []
				} else {
					this.employments = { ...this.employments, ...response.data }
				}
			} catch {
				this.error = this.t('humaniq', 'Could not load this part of the employee record. Try again.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The readable name of an event kind.
		 *
		 * @param {string} kind The kind.
		 * @return {string}
		 *
		 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-001
		 */
		kindLabel(kind) {
			const labels = {
				'contract-start': this.t('humaniq', 'Contract started'),
				'contract-end': this.t('humaniq', 'Contract ended'),
				'placement-start': this.t('humaniq', 'Placed in a unit'),
				'placement-end': this.t('humaniq', 'Placement ended'),
				'pay-change': this.t('humaniq', 'Pay changed'),
				leave: this.t('humaniq', 'Leave'),
				sickness: this.t('humaniq', 'Sick'),
				review: this.t('humaniq', 'Review finalised'),
			}
			return labels[kind] || kind
		},

		/**
		 * Group events by year.
		 *
		 * @param {object} event The event.
		 * @return {{key: string, label: string}}
		 *
		 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-001
		 */
		byYear(event) {
			const year = String(event.start).slice(0, 4)
			return { key: year, label: year }
		},

		/**
		 * The event's date, or its date range.
		 *
		 * @param {object} event The event.
		 * @return {string}
		 *
		 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-001
		 */
		dateRange(event) {
			const format = (value) => new Date(value + 'T00:00:00').toLocaleDateString()
			return event.end ? this.t('humaniq', '{start} to {end}', { start: format(event.start), end: format(event.end) }) : format(event.start)
		},

		/**
		 * A number in the reader's locale, at most two decimals.
		 *
		 * @param {number} value The number.
		 * @return {string}
		 *
		 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-003
		 */
		number(value) {
			return Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 2 })
		},

		/**
		 * Hours a balance entitles to.
		 *
		 * @param {object} balance The LeaveBalance.
		 * @return {number}
		 *
		 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-003
		 */
		entitled(balance) {
			return Number(balance.entitledHours || 0) + Number(balance.bovenwettelijkHours || 0)
		},

		/**
		 * Hours left on a balance.
		 *
		 * @param {object} balance The LeaveBalance.
		 * @return {number}
		 *
		 * @spec openspec/specs/employee-history/spec.md#REQ-EHI-003
		 */
		remaining(balance) {
			return this.entitled(balance) - Number(balance.usedHours || 0)
		},
	},
}
</script>

<style scoped>
.employee-history__event {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	color: inherit;
}

.employee-history__date,
.employee-history__detail,
.employee-history__note {
	color: var(--color-text-maxcontrast);
}

.employee-history__employments table {
	width: 100%;
	border-collapse: collapse;
}

.employee-history__employments th,
.employee-history__employments td {
	padding: calc(var(--default-grid-baseline) * 2);
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}
</style>
