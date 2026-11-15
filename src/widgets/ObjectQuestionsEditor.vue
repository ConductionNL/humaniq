<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ObjectQuestionsEditor: the questions of one OpenRegister object, edited with
 the library's CnFormBuilder. Reads the object's questions list and saves it
 back with a PATCH, leaving out rows without a key. With draftOnly the builder
 only shows while the object is a concept; otherwise lockedNote explains why
 the questions cannot change.
-->
<template>
	<div class="object-questions">
		<NcLoadingIcon v-if="loading" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-else-if="!draft" type="info">
			{{ lockedNote }}
		</NcNoteCard>
		<template v-else>
			<CnFormBuilder v-model="questions"
				:availableTypes="types"
				:description="description"
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
			<div class="object-questions__save">
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
	name: 'ObjectQuestionsEditor',

	components: { CnFormBuilder, NcButton, NcLoadingIcon, NcNoteCard },

	props: {
		/** The object's id. */
		objectId: { type: [String, Number], default: '' },
		/**
		 * The object's schema in the humaniq register. Not named `schema`: the
		 * widget host hands its own `schema` in as an attribute, which falls
		 * through to this component and would win over the widget's value.
		 */
		objectSchema: { type: String, required: true },
		/** The answer types the builder offers. */
		types: { type: Array, required: true },
		/** The builder's description, translated by the caller. */
		description: { type: String, default: '' },
		/** The message when the questions cannot be read, translated by the caller. */
		readError: { type: String, default: '' },
		/** Whether the questions can only change while the object's status is 'concept'. */
		draftOnly: { type: Boolean, default: false },
		/** The note shown instead of the builder once the object is no longer a draft. */
		lockedNote: { type: String, default: '' },
	},

	data() {
		return {
			questions: [],
			loading: true,
			saving: false,
			saved: false,
			error: '',
			draft: true,
		}
	},

	computed: {
		/**
		 * The object's OpenRegister URL.
		 *
		 * @return {string}
		 * @spec openspec/specs/portal-audiences/spec.md#REQ-PAU-002
		 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
		 */
		url() {
			return generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}', { register: 'humaniq', schema: this.objectSchema, id: this.objectId })
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the object's questions and, with `draftOnly`, whether it is still a draft.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-audiences/spec.md#REQ-PAU-002
		 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
		 */
		async load() {
			this.loading = true
			try {
				const response = await axios.get(this.url)
				this.questions = Array.isArray(response.data?.questions) ? response.data.questions : []
				this.draft = !this.draftOnly || (response.data?.status || 'concept') === 'concept'
			} catch {
				this.error = this.readError
			} finally {
				this.loading = false
			}
		},

		/**
		 * Store the questions on the object. A question needs a key, so
		 * rows without one are left out.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-audiences/spec.md#REQ-PAU-002
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
.object-questions {
	padding: 8px 0;

	&__save {
		display: flex;
		align-items: center;
		gap: 12px;
		margin-top: 12px;
	}
}
</style>
