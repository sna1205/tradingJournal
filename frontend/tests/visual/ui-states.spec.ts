import { expect, test } from '@playwright/test'

test.describe('UI visual states', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/__visual-regression')
    await page.waitForLoadState('networkidle')
  })

  test('form baseline state', async ({ page }) => {
    await expect(page.locator('[data-testid="visual-form"]')).toHaveScreenshot('form-baseline.png')
  })

  test('select popover state', async ({ page }) => {
    await page.locator('[data-testid="visual-direction"] .select-trigger').click()
    await expect(page.locator('[data-testid="visual-form"]')).toHaveScreenshot('select-open.png')
  })

  test('confirm modal overlay state', async ({ page }) => {
    await page.getByTestId('open-confirm').click()
    await expect(page.locator('.confirm-overlay')).toBeVisible()
    await expect(page).toHaveScreenshot('confirm-modal.png')
  })
})
