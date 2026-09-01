import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { queueAsKail } from './_helpers';

// Covers strategy.md scenario 15 (non-reviewer access): kail is logged in
// (this project's storage state) but is not in reviewer_groups, so the
// admin queue page must deny access and never leak pending-change content.

test('kail cannot access the review queue admin page', async ({ page, request }) => {
  // Put a change in the queue first with content distinctive enough to spot.
  // Without this the assertion would pass just as happily against an empty
  // queue, i.e. prove nothing.
  const marker = `leakcanary${Date.now()}`;
  const rqid = await queueAsKail(request, `leak${Date.now()}`, marker, marker);
  expect(rqid).toBeGreaterThan(0);

  const res = await page.goto('/doku.php?do=admin&page=reviewqueue');
  const text = await res!.text();
  expect(text).not.toContain('reviewqueue-item');
  expect(text).not.toContain(marker);
});

test('kail has no review queue link in Site Tools', async ({ page }) => {
  await page.goto('/doku.php?id=start');
  await expect(page.locator('a[href*="page=reviewqueue"]')).toHaveCount(0);
});
