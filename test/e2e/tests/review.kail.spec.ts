import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { saveAsMartin } from './_helpers';

// Covers strategy.md scenario 1 (create) and 2 (edit) via the browser path.
// Each test uses its own fresh page id rather than the shared
// playground:test fixture (reserved for the Phase 6 merge/conflict specs),
// so it can't race with other spec files editing the same page.

test('creating a new page as kail is queued, not published', async ({ page }) => {
  const pageId = `browsercreate${Date.now()}`;
  const marker = 'kail browser new-page content';

  await page.goto(`/doku.php?id=${pageId}&do=edit`);
  await page.fill('#wiki__text', marker);
  await page.click('#edbtn__save');

  await expect(page.locator('#dokuwiki__content .info')).toContainText(
    /submitted for review as change #\d+/
  );

  await page.goto(`/doku.php?id=${pageId}`);
  await expect(page.locator('#dokuwiki__content')).not.toContainText(marker);
});

test('editing an existing page as kail is queued, live content unchanged', async ({
  page,
  request,
}) => {
  const pageId = `browseredit${Date.now()}`;
  const original = 'original content, set up as martin before this test';
  const marker = `kail browser edit ${Date.now()}`;

  // Fixture setup via martin's token, independent of this project's kail
  // browser session, so the page exists and is live before kail touches it.
  await saveAsMartin(request, pageId, original);

  await page.goto(`/doku.php?id=${pageId}&do=edit`);
  const editor = page.locator('#wiki__text');
  await editor.fill(`${marker}\n\n${await editor.inputValue()}`);
  await page.click('#edbtn__save');

  await expect(page.locator('#dokuwiki__content .info')).toContainText(
    /submitted for review as change #\d+/
  );

  await page.goto(`/doku.php?id=${pageId}`);
  const after = await page.locator('#dokuwiki__content').innerText();
  expect(after).toContain(original);
  expect(after).not.toContain(marker);
});
