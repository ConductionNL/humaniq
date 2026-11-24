<?php

/**
 * Test stub of filinq's DocumentTextSource
 *
 * Only `resolveText()`, answering the text a test sets per file id. The two
 * classes beside it are filinq's own, vendored verbatim from filinq
 * development (f95c1ed2, filinq#2320), so humaniq's Read CV is tested
 * against the real profile rules (hiring-cv-profile-extraction 1.2).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Filinq\Service;

/**
 * The text of a file, as a test sets it.
 */
class DocumentTextSource {

	/**
	 * Text per file id.
	 *
	 * @var array<int, string>
	 */
	public array $texts = [];

	/**
	 * The text of a file, '' when the test set none.
	 *
	 * @param int|null $fileId The file id.
	 *
	 * @return array{text: string, ocrConfidence: float}
	 */
	public function resolveText(?int $fileId): array {
		return ['text' => ($this->texts[(int)$fileId] ?? ''), 'ocrConfidence' => 1.0];
	}//end resolveText()
}//end class
