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
        // wkr-administration — guarded, admin/HR-only trigger for the
        // WkrAssessmentDetail "Beoordelen" manifest api-call action
        // (design.md D6).
        ['name' => 'payroll#wkrAssess', 'url' => '/api/payroll/wkr-assess', 'verb' => 'POST'],
        // jurisdiction-packs — admin-only pack upload. ONE endpoint, no CRUD
        // (ADR-022). Every blocking gate lives in PackValidator (design.md
        // D11); this route adds only the admin check.
        ['name' => 'jurisdictionPack#upload', 'url' => '/api/payroll/packs', 'verb' => 'POST'],
        // rostering — guarded trigger for the RosterDetail "ATW-controle"
        // manifest api-call action (design.md D5).
        ['name' => 'roster#check', 'url' => '/api/roster/check', 'verb' => 'POST'],
        // comp-cycles — guarded trigger for the CompAdjustmentDetail
        // "Effectueren" manifest api-call action (design.md D6).
        ['name' => 'comp#effectuate', 'url' => '/api/comp/effectuate', 'verb' => 'POST'],
        // multi-administratie — guarded per-user active-administration
        // selection + context for the switcher (design.md D4/D5). BEFORE the
        // SPA catch-all per REQ-MULTI-003.
        ['name' => 'administration#setActive', 'url' => '/api/administration/active', 'verb' => 'POST'],
        ['name' => 'administration#context', 'url' => '/api/administration/context', 'verb' => 'GET'],
        // leave-buy-sell — guarded "Verrekenen" trigger for the
        // LeaveTransactionDetail manifest api-call action (design.md D4/D6):
        // settle is deliberately NOT a bare lifecycleActions button. BEFORE
        // the SPA catch-all per REQ-BUYSELL-004.
        ['name' => 'leave#settle', 'url' => '/api/leave/settle', 'verb' => 'POST'],
        // loonbeslag — guarded, admin/HR-only activate/settle/withdraw
        // triggers for the LoonbeslagDetail manifest api-call actions
        // (design.md D5/D6): Loonbeslag.status carries no
        // x-openregister-lifecycle map. BEFORE the SPA catch-all.
        ['name' => 'loonbeslag#activate', 'url' => '/api/loonbeslag/activate', 'verb' => 'POST'],
        ['name' => 'loonbeslag#settle', 'url' => '/api/loonbeslag/settle', 'verb' => 'POST'],
        ['name' => 'loonbeslag#withdraw', 'url' => '/api/loonbeslag/withdraw', 'verb' => 'POST'],
        // SPA catch-all — Vue history mode; specific routes MUST precede this.
        ['name' => 'page#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
