<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ApprovalsInboxWidget (self-service-approvals-inbox REQ-API-001, REQ-API-002).

 One approvals inbox on the My approvals dashboard, in one of two states
 chosen by the manifest's `state`:
 - `open`: every leave request, timesheet, expense claim and leave trade
   waiting for the caller, as manager or as a deputy today, oldest first,
   with approve and reject in place.
 - `decided`: what the caller decided in the last 90 days, each with its
   timeline: submitted when and by whom, decided when, by whom and why.
 Rows come from GET /api/approvals, composed and filtered server side under
 the caller's own rights. Approve and reject post OpenRegister's lifecycle
 transition, the one the detail pages post, so every guard still applies.
-->
<template>
	<CnWidgetWrapper :title="title" :widgetId="widgetId" :documentationUrl="documentationUrl">
		<div class="approvals-inbox">
			<NcSelect
				v-model="kind"
				class="approvals-inbox__kind"
				:options="kindOptions"
				label="label"
				:inputLabel="t('humaniq', 'Kind of request')"
				:placeholder="t('humaniq', 'All kinds')"
				:clearable="true"
				@update:modelValue="load" />

			<NcLoadingIcon v-if="loading" />
			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<p v-else-if="requests.length === 0" class="approvals-inbox__empty">
				{{ isOpen ? t('humaniq', 'Nothing waits for you.') : t('humaniq', 'You decided nothing in the last 90 days.') }}
			</p>
			<table v-else>
				<caption class="hidden-visually">
					{{ isOpen ? t('humaniq', 'Requests waiting for your decision') : t('humaniq', 'Requests you decided') }}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('humaniq', 'Request') }}
						</th>
						<th scope="col">
							{{ t('humaniq', 'Employee') }}
						</th>
						<th scope="col">
							{{ t('humaniq', 'Dates') }}
						</th>
						<th v-if="isOpen" scope="col">
							{{ t('humaniq', 'Waiting') }}
						</th>
						<th v-else scope="col">
							{{ t('humaniq', 'Timeline') }}
						</th>
						<th v-if="isOpen" scope="col">
							{{ t('humaniq', 'Decision') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="request in requests" :key="request.schema + request.id">
						<th scope="row">
							<router-link :to="{ name: request.route, params: { id: request.id } }">
								{{ kindLabel(request.kind) }}
							</router-link>
							<small v-if="request.onBehalfOf" class="approvals-inbox__muted">
								{{ t('humaniq', 'For {manager}', { manager: request.onBehalfOf }) }}
							</small>
						</th>
						<td>{{ request.employee }}</td>
						<td>{{ dates(request) }}</td>
						<td v-if="isOpen">
							{{ waiting(request) }}
						</td>
						<td v-else>
							<ol class="approvals-inbox__timeline">
								<li v-for="step in request.timeline" :key="step.event">
									{{ stepLabel(step) }}
								</li>
							</ol>
						</td>
						<td v-if="isOpen" class="approvals-inbox__actions">
							<NcButton variant="primary" :disabled="busy === request.id" @click="decide(request, 'approve')">
								{{ t('humaniq', 'Approve') }}
							</NcButton>
							<NcButton :disabled="busy === request.id" @click="decide(request, 'reject')">
								{{ t('humaniq', 'Reject') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'

export default {
	name: 'ApprovalsInboxWidget',

	components: { CnWidgetWrapper, NcButton, NcLoadingIcon, NcNoteCard, NcSelect },

	props: {
		/** Widget title, shown in the wrapper header. */
		title: { type: String, default: '' },
		/** Widget id, merged in by CnWidgetGrid. */
		widgetId: { type: String, default: '' },
		/** Optional docs link in the wrapper's menu. */
		documentationUrl: { type: String, default: '' },
		/** `open` or `decided`, from the manifest's content. */
		state: { type: String, default: 'open' },
	},

	data() {
		return {
			loading: true,
			error: '',
			requests: [],
			kind: null,
			busy: '',
		}
	},

	computed: {
		/**
		 * Whether this is the open view.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
		 */
		isOpen() {
			return this.state !== 'decided'
		},

		/**
		 * The kinds to filter on.
		 *
		 * @return {Array<{id: string, label: string}>}
		 *
		 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
		 */
		kindOptions() {
			return ['leave', 'hours', 'expense', 'leave-trade'].map((id) => ({ id, label: this.kindLabel(id) }))
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the caller's inbox.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const params = { state: this.isOpen ? 'open' : 'decided' }
				if (this.kind) {
					params.kind = this.kind.id
				}
				const response = await axios.get(generateUrl('/apps/humaniq/api/approvals'), { params })
				this.requests = response.data.requests || []
			} catch {
				this.error = this.t('humaniq', 'Could not load your approvals. Try again.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Approve or reject one request through its lifecycle transition.
		 *
		 * @param {object} request The row.
		 * @param {string} action approve or reject.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
		 */
		async decide(request, action) {
			this.busy = request.id
			try {
				await axios.post(generateUrl('/apps/openregister/api/objects/{id}/transition', { id: request.id }), { action })
				showSuccess(action === 'approve' ? this.t('humaniq', 'Approved.') : this.t('humaniq', 'Rejected.'))
				await this.load()
			} catch (e) {
				showError(e?.response?.data?.error || e?.response?.data?.message || this.t('humaniq', 'This request could not be changed.'))
			} finally {
				this.busy = ''
			}
		},

		/**
		 * The readable name of a kind of request.
		 *
		 * @param {string} kind The kind.
		 * @return {string}
		 *
		 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
		 */
		kindLabel(kind) {
			return {
				leave: this.t('humaniq', 'Leave'),
				hours: this.t('humaniq', 'Hours'),
				expense: this.t('humaniq', 'Expense claim'),
				'leave-trade': this.t('humaniq', 'Buy or sell leave'),
			}[kind] || kind
		},

		/**
		 * The request's dates, as one range or one day.
		 *
		 * @param {object} request The row.
		 * @return {string}
		 *
		 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
		 */
		dates(request) {
			if (!request.from) {
				return ''
			}
			return (request.to && request.to !== request.from) ? this.t('humaniq', '{from} to {to}', { from: request.from, to: request.to }) : String(request.from)
		},

		/**
		 * How long the request has waited.
		 *
		 * @param {object} request The row.
		 * @return {string}
		 *
		 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
		 */
		waiting(request) {
			if (request.waitingDays === null || request.waitingDays === undefined) {
				return ''
			}
			return this.t('humaniq', 'Days waiting: {count}', { count: request.waitingDays })
		},

		/**
		 * One step of a decided request's timeline.
		 *
		 * @param {{event: string, at: string, by: ?string, reason: ?string}} step The step.
		 * @return {string}
		 *
		 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
		 */
		stepLabel(step) {
			const at = (step.at || '').slice(0, 10)
			const by = step.by || ''
			if (step.event === 'submitted') {
				return this.t('humaniq', 'Submitted on {date} by {user}', { date: at, user: by })
			}
			if (step.event === 'rejected') {
				return step.reason
					? this.t('humaniq', 'Rejected on {date} by {user}: {reason}', { date: at, user: by, reason: step.reason })
					: this.t('humaniq', 'Rejected on {date} by {user}', { date: at, user: by })
			}
			return this.t('humaniq', 'Approved on {date} by {user}', { date: at, user: by })
		},
	},
}
</script>

<style scoped>
.approvals-inbox table {
	width: 100%;
	border-collapse: collapse;
}

.approvals-inbox th,
.approvals-inbox td {
	padding: calc(var(--default-grid-baseline) * 2);
	text-align: start;
	vertical-align: top;
	border-bottom: 1px solid var(--color-border);
}

.approvals-inbox__kind {
	max-width: 320px;
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.approvals-inbox__muted,
.approvals-inbox__empty {
	display: block;
	color: var(--color-text-maxcontrast);
}

.approvals-inbox__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}

.approvals-inbox__timeline {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 4);
}
</style>
