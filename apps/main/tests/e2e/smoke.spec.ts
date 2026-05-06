import { test, expect } from '@playwright/test'

test('home page renders the app title', async ({ page }) => {
  await page.goto('/')
  await expect(page).toHaveTitle(/Catalyst Engine/)
  await expect(page.getByText('Catalyst Engine')).toBeVisible()
})
