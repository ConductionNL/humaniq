/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared auth helper — storage-state path + interactive login fallback,
 * mirroring the procest/pipelinq e2e convention.
 */
import { Page } from '@playwright/test'
import path from 'path'

export const STORAGE_STATE = path.join(__dirname, '..', '.auth', 'user.json')

export async function login(page: Page, user?: string, password?: string): Promise<void> {
	const username = user ?? process.env.ADMIN_USER ?? 'admin'
	const pass = password ?? process.env.ADMIN_PASSWORD ?? 'admin'

	await page.goto('/index.php/login')
	await page.fill('input[name="user"]', username)
	await page.fill('input[name="password"]', pass)
	await page.click('button[type="submit"], input[type="submit"]')
	await page.waitForURL('**/apps/**', { timeout: 30000 })
}
