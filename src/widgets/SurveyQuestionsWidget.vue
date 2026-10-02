<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SurveyQuestionsWidget (talent-engagement-surveys D1, REQ-SRV-001).

 HR builds a survey's questions with the library's CnFormBuilder. The list is
 stored as Survey.questions; employees answer it from their invitation. The
 questions can change only while the survey is a draft, so every answer to an
 open survey belongs to the same questions.
-->
<template>
	<div class="survey-questions">
		<NcLoadingIcon v-if="loading" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-else-if="!draft" type="info">
			{{ t('humaniq', 'The questions of an open or closed survey cannot change, so every answer belongs to the same questions.') }}
		</NcNoteCard>
		<template v-else>
			<CnFormBuilder v-model="questions"
				:availableTypes="types"
				:description="t('humaniq', 'Employees answer these anonymously. A recommend question (0 to 10) gives the eNPS.')"
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
			<div class="survey-questions__save">
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
	name: 'SurveyQuestionsWidget',

	components: { CnFormBuilder, NcButton, NcLoadingIcon, NcNoteCard },

	props: {
		/** The survey's id, handed in by CnDetailWidgetHost's detail context. */
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			questions: [],
			draft: true,
			loading: true,
			saving: false,
			saved: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The kinds of question a survey can ask.
		 *
		 * @return {Array<{type: string, label: string}>}
		 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
		 */
		types() {
			return [
				{ type: 'scale', label: this.t('humaniq', 'Scale from 1 to 5') },
				{ type: 'enum', label: this.t('humaniq', 'Choice') },
				{ type: 'textarea', label: this.t('humaniq', 'Free text') },
				{ type: 'recommend', label: this.t('humaniq', 'Recommend (0 to 10)') },
			]
		},

		/**
		 * The survey's OpenRegister URL.
		 *
		 * @return {string}
		 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
		 */
		url() {
			return generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}', { register: 'humaniq', schema: 'Survey', id: this.objectId })
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the survey's questions and whether it is still a draft.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
		 */
		async load() {
			this.loading = true
			try {
				const response = await axios.get(this.url)
				this.questions = Array.isArray(response.data?.questions) ? response.data.questions : []
				this.draft = (response.data?.status || 'concept') === 'concept'
			} catch {
				this.error = this.t('humaniq', 'The questions of this survey could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Store the questions on the survey. A question needs a key, so
		 * rows without one are left out.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
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
.survey-questions {
	padding: 8px 0;

	&__save {
		display: flex;
		align-items: center;
		gap: 12px;
		margin-top: 12px;
	}
}
</style>
