import { test as setup, expect } from '@playwright/test';

const CREDENTIALS: Record<string, { user: string; pass: string }> = {
  martin: { user: 'martin', pass: 'martin' },
  kail: { user: 'kail', pass: 'kail' },
};

for (const [name, { user, pass }] of Object.entries(CREDENTIALS)) {
  setup(`log in as ${name}`, async ({ page }) => {
    await page.goto(`/doku.php?id=start&do=login`);
    const loginForm = page.locator('#dw__login');
    await loginForm.locator('input[name="u"]').fill(user);
    await loginForm.locator('input[name="p"]').fill(pass);
    await loginForm.locator('button[type="submit"]').click();

    // A logout link only appears in DokuWiki's tools menu once authenticated.
    await expect(page.locator('a[href*="do=logout"]')).toBeVisible({ timeout: 5000 });
    await page.context().storageState({ path: `.auth/${name}-storage.json` });
  });
}
