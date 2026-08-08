import { test, expect } from '@playwright/test';

test('kail is logged in and sees the seeded start page', async ({ page }) => {
  await page.goto('/doku.php?id=start');
  await expect(page.locator('#dokuwiki__content')).toContainText('AI Review Queue Test Wiki');
  await expect(page.locator('a[href*="do=logout"]')).toBeVisible();
});
