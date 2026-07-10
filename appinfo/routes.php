<?php
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

/*
 * HRMQ routes. The app is OpenRegister-backed: the Timesheet/Expense pages are
 * declarative manifest pages that read and write the hrmq register directly via
 * the @conduction/nextcloud-vue object store, so there are no domain CRUD
 * routes here — only the SPA shell + the bundled-manifest endpoint (ADR-024 §4).
 */

return [
    'routes' => [
        // SPA shell — boots the Vue manifest renderer.
        ['name' => 'page#index',    'url' => '/',             'verb' => 'GET'],
        // ADR-024 §4 — manifest endpoint (bundled blob).
        ['name' => 'page#manifest', 'url' => '/api/manifest', 'verb' => 'GET'],
        // SPA catch-all — Vue history mode; specific routes MUST precede this.
        ['name' => 'page#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
