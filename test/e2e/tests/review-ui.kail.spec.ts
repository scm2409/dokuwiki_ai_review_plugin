import { test, expect } from '@playwright/test';

// Covers strategy.md scenario 15 (non-reviewer access): kail is logged in
// (this project's storage state) but is not in reviewer_groups, so the
// admin queue page must deny access and never leak pending-change content.

test('kail cannot access the review queue admin page', async ({ page, request }) => {
  const res = await page.goto('/doku.php?do=admin&page=reviewqueue');
  const text = await res!.text();
  expect(text).not.toContain('reviewqueue-item');
});
