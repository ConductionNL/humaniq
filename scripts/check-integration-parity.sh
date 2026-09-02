#!/usr/bin/env bash
#
# check-integration-parity.sh — hydra gate-24 (`integration-parity`) entry point.
#
# gate-24 invokes exactly this path. Without it the gate reports SKIPPED even
# though this repo registers a leaf, and a skipped gate correlated nothing.
#
# This wrapper deliberately does NOT skip when something is missing. A gate
# whose absence looks exactly like its success is worse than no gate, so every
# way it can fail to check something exits non-zero with a named reason.
#
# Exit codes:
#   0 — every parity rule that had subject matter passed
#   1 — at least one parity violation, or the checker could not be run
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
JS_CHECK="${SCRIPT_DIR}/check-integration-parity.js"

if [ ! -f "${JS_CHECK}" ]; then
	echo "✗ integration parity: checker missing at ${JS_CHECK} — the gate's own machinery is absent, so NOTHING was correlated. Refusing to report a pass." >&2
	exit 1
fi

if ! command -v node >/dev/null 2>&1; then
	echo "✗ integration parity: node is required to run ${JS_CHECK} but was not found on PATH — NOTHING was correlated. Refusing to report a pass." >&2
	exit 1
fi

exec node "${JS_CHECK}" "${REPO_ROOT}"
