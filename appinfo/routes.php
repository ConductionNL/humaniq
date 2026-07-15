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
        // hrmq-docudesk-documents — guarded trigger for the EmploymentContractDetail
        // "Genereer arbeidsovereenkomst" manifest api-call action (design.md D7).
        ['name' => 'document#generate', 'url' => '/api/documents/generate', 'verb' => 'POST'],
        // payroll-core-engine — guarded trigger for the PayrollRunDetail
        // "(Her)berekenen" manifest api-call action (design.md D6).
        ['name' => 'payroll#calculate', 'url' => '/api/payroll/calculate', 'verb' => 'POST'],
        // payroll-mutation-reports — guarded, admin/HR-only trigger for the
        // PayrollRunDetail "Mutatieoverzicht" manifest api-call action
        // (design.md D6).
        ['name' => 'payroll#mutations', 'url' => '/api/payroll/mutations', 'verb' => 'POST'],
        // proforma-payslip — persist-nothing "Simuleer loonstrook" pro-forma
        // simulation, RBAC-gated capability probe (design.md D4).
        ['name' => 'payroll#proforma', 'url' => '/api/payroll/proforma', 'verb' => 'POST'],
        // retro-adjustments — guarded "Herrekenen" trigger for the
        // PayrollAdjustmentDetail manifest api-call action (design.md D8).
        ['name' => 'payroll#adjust', 'url' => '/api/payroll/adjust', 'verb' => 'POST'],
        // SPA catch-all — Vue history mode; specific routes MUST precede this.
        ['name' => 'page#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
