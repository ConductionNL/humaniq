<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 VacancyQuestionsWidget (hiring-portal-audiences D3, REQ-PTA-002).

 HR adds their own questions to a vacancy's application form with the
 library's CnFormBuilder. The list is stored as Vacancy.questions and portaliq
 shows it on the public apply form; the answers land on
 job-application.answers. Reads and patches the vacancy through OpenRegister.
-->
<template>
	<div class="vacancy-questions">
		<NcLoadingIcon v-if="loading" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<template v-else>
			<CnFormBuilder v-model="questions"
				:availableTypes="types"
				:description="t('humaniq', 'Candidates answer these on the application form of this vacancy.')"
				:paletteTitle="t('humaniq', 'Answer types')"
				:fieldsTitle="t('humaniq', 'Questions')"
				:editorTitle="t('humaniq', 'Question')"
				:emptyLabel="t('humaniq', 'No questions yet. Pick an answer type to add one.')"
				:noSelectionLabel="t('humaniq', 'Select a question to edit it.')"
				:untitledKey="t('humaniq', '(no key)')"
				:keyLabel="t('humaniq', 'Key')"
				:typeLabel="t('humaniq', 'Answer type')"
				:labelLabel="t('humaniq', 'Question')"
				:placeholderLabel="t('humaniq', 'Example answer')"
				:requiredLabel="t('humaniq', 'Required')"
				:optionsLabel="t('humaniq', 'Choices, one per line')"
				:moveUpLabel="t('humaniq', 'Move up')"
				:moveDownLabel="t('humaniq', 'Move down')"
				:deleteLabel="t('humaniq', 'Delete')"
				hidePreview />
			<div class="vacancy-questions__save">
				<NcButton variant="primary" :disabled="saving" @click="save">
					<template #icon>
						<NcLoadingIcon v-if="saving" />
					</template>
					{{ t('humaniq', 'Save questions') }}
				</NcButton>
				<span v-if="saved" role="status">{{ t('humaniq', 'Saved') }}</span>
			</div>
		</template>
	</div>
</template>

<script>
import { CnFormBuilder } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'VacancyQuestionsWidget',

	components: { CnFormBuilder, NcButton, NcLoadingIcon, NcNoteCard },

	props: {
		/** The vacancy's id, handed in by CnDetailWidgetHost's detail context. */
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			questions: [],
			loading: true,
			saving: false,
			saved: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The answer types a candidate can be asked for.
		 *
		 * @return {Array<{type: string, label: string}>}
		 * @spec openspec/specs/portal-audiences/spec.md#REQ-PTA-002
		 */
		types() {
			return [
				{ type: 'string', label: this.t('humaniq', 'Short answer') },
				{ type: 'textarea', label: this.t('humaniq', 'Long answer') },
				{ type: 'enum', label: this.t('humaniq', 'Choice') },
				{ type: 'boolean', label: this.t('humaniq', 'Yes or no') },
				{ type: 'number', label: this.t('humaniq', 'Number') },
			]
		},

		/**
		 * The vacancy's OpenRegister URL.
		 *
		 * @return {string}
		 * @spec openspec/specs/portal-audiences/spec.md#REQ-PTA-002
		 */
		url() {
			return generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}', { register: 'humaniq', schema: 'Vacancy', id: this.objectId })
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the vacancy's questions.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-audiences/spec.md#REQ-PTA-002
		 */
		async load() {
			this.loading = true
			try {
				const response = await axios.get(this.url)
				this.questions = Array.isArray(response.data?.questions) ? response.data.questions : []
			} catch {
				this.error = this.t('humaniq', 'The questions of this vacancy could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Store the questions on the vacancy. A question needs a key, so
		 * rows without one are left out.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-audiences/spec.md#REQ-PTA-002
		 */
		async save() {
			this.saving = true
			this.saved = false
			this.error = ''
			try {
				const questions = this.questions.filter((question) => String(question.key || '').trim() !== '')
				await axios.patch(this.url, { questions })
				this.questions = questions
				this.saved = true
			} catch {
				this.error = this.t('humaniq', 'The questions could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.vacancy-questions {
	padding: 8px 0;

	&__save {
		display: flex;
		align-items: center;
		gap: 12px;
		margin-top: 12px;
	}
}
</style>
