/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * payroll-mutation-lineage, tasks.md 4.1.
 *
 * SCENARIOS COVERED
 * -----------------
 * openspec/changes/payroll-mutation-lineage/specs/payroll-mutation-lineage/spec.md
 *   Scenario: Two corrections are two lines
 *   Scenario: Recalculation replaces lines
 *   Scenario: A raise explains the salary
 *   Scenario: A direct edit explains the salary
 *   Scenario: The mutation report names the cause
 *   Scenario: A correction must name a line of its own payslip
 *
 * WHAT THIS CLOSES THAT THE UNIT SUITE CANNOT
 * -------------------------------------------
 * PayslipLineageServiceTest and PayrollRunServiceTest feed arrays the tests
 * wrote themselves. They cannot see whether OpenRegister keeps a PayslipLine
 * the schema declares, whether the audit trail the salary line points at is
 * the one OpenRegister actually wrote for the edit, or whether the pre-save
 * guard on PayrollAdjustment is wired to the real save. Every assertion here
 * reads the answer back from the object store, and the first one also from
 * the payslip page a payroll officer opens.
 *
 * FIXTURES
 * --------
 * Four employees, each created by this file under a run-scoped name:
 *   A  raised from 3800.00 to 3876.00 between the 2026-06 and 2026-07 runs
 *      (the raise and the mutation-report scenarios),
 *   B  whose salary an adviser edits directly before the 2026-07 run,
 *   C  with two applied retro corrections settling in 2026-06,
 *   D  with a company car whose assignment ends before 2026-06.
 * The runs carry a run-scoped administration, so they cannot collide with a
 * seeded run of the same period. The engine pays every eligible employee of
 * the register into a run, so the teardown removes every payslip and payslip
 * line of the two runs, not only those of the fixture employees.
 *
 * PRECONDITIONS
 * -------------
 * The humaniq register and its schemas are imported (tests/e2e/ci-seed.sh),
 * and the bundled nl-2026 pack resolves (it ships in lib/Standards/packs).
 */

import type { APIRequestContext } from "@playwright/test";

import { expect, request, test } from "@playwright/test";
import { randomUUID } from "node:crypto";
import { ADMIN_CREDENTIALS, resolveBaseURL } from "../base-url.ts";

const NC_URL = resolveBaseURL();
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects`;
const APP_API = `${NC_URL}/index.php/apps/humaniq/api`;
const REGISTER = "humaniq";
const AUTH = ADMIN_CREDENTIALS;
const HEADERS = {
	"OCS-APIRequest": "true",
	"Content-Type": "application/json",
};

/* Namespaces every fixture this run creates in a SHARED register. */
const RUN_ID = randomUUID().slice(0, 8);
const ADMINISTRATION = `E2E-PML-${RUN_ID}`;

type Row = Record<string, unknown>;

/** Read an object's id out of either payload shape OpenRegister returns. */
function idOf(row: Row | undefined): string {
	const self = (row?.["@self"] || {}) as Row;
	return String(self.id || row?.id || "");
}

/** An eligible NL employee: wit table, BSN and verified identity. */
function employee(suffix: string, lastName: string, salary: number): Row {
	return {
		employeeNumber: `${RUN_ID}-${suffix}`,
		firstName: "Lijn",
		lastName: `${lastName}-${RUN_ID}`,
		startDate: "2026-01-01",
		grossMonthlySalary: salary,
		taxTableColor: "wit",
		bsn: "111222333",
		identityDocumentVerified: true,
		administrationId: ADMINISTRATION,
	};
}

test.describe.serial("payroll mutation lineage", () => {
	let api: APIRequestContext;

	const cleanup: Array<{ schema: string; id: string }> = [];
	const runIds: string[] = [];

	const ids = {
		empA: "",
		empB: "",
		empC: "",
		empD: "",
		run06: "",
		run07: "",
		retro100: "",
		retro50: "",
		raise: "",
		assignment: "",
	};

	/** Create one object and queue it for the teardown. */
	async function create(schema: string, data: Row): Promise<string> {
		const res = await api.post(`${OR_BASE}/${REGISTER}/${schema}`, {
			headers: HEADERS,
			data,
		});
		expect(res.ok(), `${schema} fixture (${res.status()}): ${await res.text()}`).toBeTruthy();
		const id = idOf((await res.json()) as Row);
		expect(id, `${schema} fixture must return an id`).toBeTruthy();
		cleanup.push({ schema, id });
		return id;
	}

	/** One object as the store holds it. */
	async function read(schema: string, id: string): Promise<Row> {
		const res = await api.get(`${OR_BASE}/${REGISTER}/${schema}/${id}`, { headers: HEADERS });
		expect(res.ok(), `${schema} ${id} must be readable (${res.status()})`).toBeTruthy();
		return (await res.json()) as Row;
	}

	/** Merge fields into an object with a full PUT. */
	async function update(schema: string, id: string, fields: Row): Promise<void> {
		const current = await read(schema, id);
		delete current["@self"];
		const res = await api.put(`${OR_BASE}/${REGISTER}/${schema}/${id}`, {
			headers: HEADERS,
			data: { ...current, ...fields },
		});
		expect(res.ok(), `${schema} update (${res.status()}): ${await res.text()}`).toBeTruthy();
	}

	/** Every object of a schema matching a filter. */
	async function list(schema: string, filter: Record<string, string>): Promise<Row[]> {
		const query = new URLSearchParams({ ...filter, _limit: "500" });
		const res = await api.get(`${OR_BASE}/${REGISTER}/${schema}?${query}`, { headers: HEADERS });
		expect(res.ok(), `${schema} list (${res.status()})`).toBeTruthy();
		const body = (await res.json()) as Row | Row[];
		return Array.isArray(body) ? body : ((body.results as Row[]) ?? []);
	}

	/** Calculate a draft run through the guarded endpoint. */
	async function calculate(runId: string): Promise<Row> {
		const res = await api.post(`${APP_API}/payroll/calculate`, {
			headers: HEADERS,
			data: { runId },
		});
		const body = (await res.json()) as Row;
		expect(res.ok(), `calculating run ${runId} (${res.status()}): ${JSON.stringify(body)}`).toBeTruthy();
		return body;
	}

	/** The payslip one employee has in one run. */
	async function payslipOf(runId: string, employeeId: string): Promise<Row> {
		const slips = await list("Payslip", { payrollRunId: runId, employeeId });
		expect(slips.length, `one payslip for ${employeeId} in run ${runId}`).toBe(1);
		return slips[0];
	}

	/** The lines of one payslip. */
	async function linesOf(payslipId: string): Promise<Row[]> {
		return list("PayslipLine", { payslipId });
	}

	test.beforeAll(async () => {
		api = await request.newContext({ httpCredentials: AUTH });

		ids.empA = await create("Employee", employee("a", "Verhoging", 3800));
		ids.empB = await create("Employee", employee("b", "Bewerking", 4000));
		ids.empC = await create("Employee", employee("c", "Correctie", 3500));
		ids.empD = await create("Employee", employee("d", "Leaseauto", 4200));

		for (const employeeId of [ids.empA, ids.empB, ids.empC, ids.empD]) {
			await create("EmploymentContract", {
				employeeId,
				type: "permanent",
				writtenContract: true,
				startDate: "2026-01-01",
				hoursPerWeek: 40,
				administrationId: ADMINISTRATION,
			});
		}

		ids.run06 = await create("PayrollRun", {
			period: "2026-06",
			administrationId: ADMINISTRATION,
			status: "draft",
		});
		ids.run07 = await create("PayrollRun", {
			period: "2026-07",
			administrationId: ADMINISTRATION,
			status: "draft",
		});
		runIds.push(ids.run06, ids.run07);

		// C: two applied corrections, both settling in 2026-06.
		for (const [amount, key] of [
			[100, "retro100"],
			[50, "retro50"],
		] as const) {
			ids[key] = await create("PayrollAdjustment", {
				employeeId: ids.empC,
				originalPayrollRunId: ids.run06,
				originalPeriod: "2026-05",
				correctionRef: `${RUN_ID}-${amount}`,
				correctionType: "salary",
				deltaGross: amount,
				settlementPeriod: "2026-06",
				settlementLine: "nabetaling",
				status: "applied",
			});
		}

		// D: a company car, issued before the period and still out.
		const car = await create("Asset", {
			name: `Leaseauto ${RUN_ID}`,
			category: "vehicle",
			status: "issued",
			licencePlate: `E2-${RUN_ID.slice(0, 3)}`,
			listPrice: 45000,
			fuelType: "gasoline",
			companyCarTaxCategory: "standard",
			firstAdmissionDate: "2025-01-01",
			administrationId: ADMINISTRATION,
		});
		ids.assignment = await create("AssetAssignment", {
			assetId: car,
			employeeId: ids.empD,
			issuedOn: "2026-01-01",
			issueReceiptSigned: true,
			administrationId: ADMINISTRATION,
		});
	});

	test.afterAll(async () => {
		// The engine pays every eligible employee of the register into a run,
		// so remove every payslip and line of these runs, not just ours.
		for (const runId of runIds) {
			if (!runId) continue;
			for (const schema of ["PayslipLine", "Payslip", "WageCostAllocation", "PayrollMutationReport"]) {
				const field = schema === "PayrollMutationReport" ? "toRunId" : "payrollRunId";
				const rows = await list(schema, { [field]: runId }).catch(() => [] as Row[]);
				for (const row of rows) {
					await api
						.delete(`${OR_BASE}/${REGISTER}/${schema}/${idOf(row)}`, { headers: HEADERS })
						.catch(() => {});
				}
			}
		}
		for (const { schema, id } of cleanup.reverse()) {
			if (!id) continue;
			await api
				.delete(`${OR_BASE}/${REGISTER}/${schema}/${id}`, { headers: HEADERS })
				.catch(() => {});
		}
		await api?.dispose();
	});

	test("two corrections are two lines", async ({ page }) => {
		await calculate(ids.run06);

		const slip = await payslipOf(ids.run06, ids.empC);
		expect(Number(slip.retroAdjustment), "the payslip total stays the sum of both corrections").toBe(150);

		const retro = (await linesOf(idOf(slip))).filter((line) => line.component === "retroAdjustment");
		expect(retro.length, "one line per correction, not one summed line").toBe(2);
		const bySource = Object.fromEntries(retro.map((line) => [String(line.sourceId), Number(line.amount)]));
		expect(bySource, "each line links to its own correction with its own amount").toEqual({
			[ids.retro100]: 100,
			[ids.retro50]: 50,
		});
		for (const line of retro) {
			expect(line.sourceSchema).toBe("PayrollAdjustment");
			expect(line.sourceKind).toBe("correction");
		}

		// The payroll officer's view: the payslip page lists the lines.
		await page.goto(`/index.php/apps/humaniq/payslips/${idOf(slip)}`, {
			waitUntil: "domcontentloaded",
		});
		await expect(page.getByText("Where each amount comes from").first()).toBeVisible({
			timeout: 30_000,
		});
		await expect(
			page.getByRole("row", { name: new RegExp(ids.retro100.slice(0, 8)) }).first(),
			"the 100.00 correction must be listed on the payslip page",
		).toBeVisible({ timeout: 30_000 });
	});

	test("recalculation replaces lines", async () => {
		const before = await payslipOf(ids.run06, ids.empD);
		const carLines = (await linesOf(idOf(before))).filter((line) => line.component === "bijtelling");
		expect(carLines.length, "the open car assignment gives one bijtelling line").toBe(1);
		expect(carLines[0].sourceId).toBe(ids.assignment);

		// The assignment ends before the period, then the draft is recalculated.
		await update("AssetAssignment", ids.assignment, { returnedOn: "2026-05-31" });
		await calculate(ids.run06);

		const after = await payslipOf(ids.run06, ids.empD);
		const remaining = (await linesOf(idOf(after))).filter((line) => line.component === "bijtelling");
		expect(remaining, "a recalculated draft keeps only its current lines").toEqual([]);
		expect(Number(after.bijtelling ?? 0)).toBe(0);
	});

	test("a raise explains the salary", async () => {
		// 2026-06 was calculated at 3800.00 above; now the raise takes effect.
		ids.raise = await create("CompAdjustment", {
			cycleId: `cycle-${RUN_ID}`,
			employeeId: ids.empA,
			contractId: `contract-${RUN_ID}`,
			currentSalary: 380000,
			proposedSalary: 387600,
			effectiveDate: "2026-07-01",
			status: "proposed",
			// Not the admin who approves below: the approver may not be the proposer.
			proposedBy: `e2e-proposer-${RUN_ID}`,
		});
		const approve = await api.post(`${OR_BASE}/${ids.raise}/transition`, {
			headers: HEADERS,
			data: { action: "approve" },
		});
		expect(approve.ok(), `approving the raise (${approve.status()}): ${await approve.text()}`).toBeTruthy();
		const effectuate = await api.post(`${APP_API}/comp/effectuate`, {
			headers: HEADERS,
			data: { adjustmentId: ids.raise },
		});
		expect(effectuate.ok(), `applying the raise (${effectuate.status()}): ${await effectuate.text()}`).toBeTruthy();
		expect(Number((await read("Employee", ids.empA)).grossMonthlySalary)).toBe(3876);

		// B: an adviser edits the salary directly, before the 2026-07 run.
		await update("Employee", ids.empB, { grossMonthlySalary: 4100 });

		await calculate(ids.run07);

		const slip = await payslipOf(ids.run07, ids.empA);
		const salary = (await linesOf(idOf(slip))).filter((line) => line.component === "salary");
		expect(salary.length).toBe(1);
		expect(Number(salary[0].amount)).toBe(3876);
		expect(salary[0].sourceKind, "the applied raise set this salary").toBe("raise");
		expect(salary[0].sourceSchema).toBe("CompAdjustment");
		expect(salary[0].sourceId).toBe(ids.raise);
	});

	test("a direct edit explains the salary", async () => {
		const slip = await payslipOf(ids.run07, ids.empB);
		const salary = (await linesOf(idOf(slip))).filter((line) => line.component === "salary");
		expect(salary.length).toBe(1);
		expect(Number(salary[0].amount)).toBe(4100);
		expect(salary[0].sourceKind, "no raise proposes 4100.00, so the edit explains it").toBe("record-edit");
		expect(salary[0].sourceSchema).toBe("Employee");
		expect(salary[0].sourceId).toBe(ids.empB);
		expect(Number(salary[0].sourceAuditTrailId), "the audit entry of the edit").toBeGreaterThan(0);
		expect(salary[0].sourceChangedBy, "who made the edit").toBe(AUTH.username);
		expect(String(salary[0].sourceChangedAt ?? ""), "when the edit was made").not.toBe("");

		// The audit entry named is one OpenRegister wrote for this employee.
		const logs = await api.get(
			`${NC_URL}/index.php/apps/openregister/api/objects/${REGISTER}/Employee/${ids.empB}/audit-trails`,
			{ headers: HEADERS },
		);
		if (logs.ok()) {
			const body = (await logs.json()) as Row | Row[];
			const rows = Array.isArray(body) ? body : ((body.results as Row[]) ?? []);
			expect(
				rows.map((row) => Number(row.id)),
				"the line points at an audit row of this employee",
			).toContain(Number(salary[0].sourceAuditTrailId));
		}
	});

	test("the mutation report names the cause", async () => {
		const res = await api.post(`${APP_API}/payroll/mutations`, {
			headers: HEADERS,
			data: { toRunId: ids.run07, fromRunId: ids.run06 },
		});
		const body = (await res.json()) as Row;
		expect(res.ok(), `mutation report (${res.status()}): ${JSON.stringify(body)}`).toBeTruthy();

		const report = (body.report ?? body) as Row;
		const line = ((report.lines as Row[]) ?? []).find((row) => row.employeeId === ids.empA);
		expect(line, "the raised employee is in the report").toBeTruthy();
		expect(line?.classification).toBe("changed");

		const causes = line?.causes as Row[] | null;
		expect(Array.isArray(causes), "lines exist on both payslips, so the causes are known").toBe(true);
		const raise = (causes ?? []).find((cause) => cause.component === "salary" && cause.change === "new");
		expect(raise, "the salary line the raise set is listed as a cause").toBeTruthy();
		expect(raise?.sourceKind).toBe("raise");
		expect(raise?.sourceId).toBe(ids.raise);
		expect(Number(raise?.amountAfter)).toBe(3876);
		expect(
			(causes ?? []).every((cause) => cause.component === "salary"),
			"the raise is the only difference, so only salary lines are causes",
		).toBe(true);
	});

	test("a correction must name a line of its own payslip", async () => {
		const ownSlip = await payslipOf(ids.run06, ids.empC);
		const otherSlip = await payslipOf(ids.run06, ids.empA);
		const otherLine = (await linesOf(idOf(otherSlip)))[0];
		const ownLine = (await linesOf(idOf(ownSlip)))[0];
		expect(otherLine, "the other employee's payslip has lines").toBeTruthy();

		const correction = await create("PayrollAdjustment", {
			employeeId: ids.empC,
			originalPayrollRunId: ids.run06,
			originalPayslipId: idOf(ownSlip),
			originalPeriod: "2026-06",
			correctionRef: `${RUN_ID}-line`,
			status: "draft",
		});
		const current = await read("PayrollAdjustment", correction);
		delete current["@self"];

		const refused = await api.put(`${OR_BASE}/${REGISTER}/PayrollAdjustment/${correction}`, {
			headers: HEADERS,
			data: { ...current, correctsPayslipLineId: idOf(otherLine) },
		});
		expect(refused.ok(), "a line of another employee's payslip is refused").toBe(false);
		expect(refused.status()).toBeGreaterThanOrEqual(400);
		expect(
			String((await read("PayrollAdjustment", correction)).correctsPayslipLineId ?? ""),
			"the refused value is not stored",
		).toBe("");

		// Control: a line of its own original payslip is accepted.
		const accepted = await api.put(`${OR_BASE}/${REGISTER}/PayrollAdjustment/${correction}`, {
			headers: HEADERS,
			data: { ...current, correctsPayslipLineId: idOf(ownLine) },
		});
		expect(accepted.ok(), `a line of its own payslip is accepted (${accepted.status()})`).toBe(true);
	});
});
