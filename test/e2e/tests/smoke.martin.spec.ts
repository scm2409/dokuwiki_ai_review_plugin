import { test, expect } from '@playwright/test';

test('martin is logged in and sees the seeded start page', async ({ page }) => {
  await page.goto('/doku.php?id=start');
  await expect(page.locator('#dokuwiki__content')).toContainText('AI Review Queue Test Wiki');
  await expect(page.locator('a[href*="do=logout"]')).toBeVisible();
});

test('martin can edit a page and the change is live immediately (no review)', async ({ page }) => {
  const marker = `martin direct edit ${Date.now()}`;
  await page.goto('/doku.php?id=playground:test&do=edit');
  const editor = page.locator('#wiki__text');
  await expect(editor).toBeVisible();
  await editor.fill(`${marker}\n\n` + (await editor.inputValue()));
  await page.click('#edbtn__save');

  await page.goto('/doku.php?id=playground:test');
  await expect(page.locator('#dokuwiki__content')).toContainText(marker);
});
