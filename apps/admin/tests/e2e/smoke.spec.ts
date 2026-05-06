import { test, expect } from '@playwright/test'

test('admin home page renders', async ({ page }) => {
  await page.goto('/')
  await expect(page).toHaveTitle(/Admin/)
  await expect(page.getByRole('heading', { level: 1, name: /Admin/ })).toBeVisible()
})
