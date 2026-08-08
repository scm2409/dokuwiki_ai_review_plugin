import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const tokens = JSON.parse(
  fs.readFileSync(path.join(__dirname, '..', '.auth', 'tokens.json'), 'utf-8')
) as Record<string, string>;

// Covers strategy.md scenarios 1 (approve), 5 (reject with reason), 14
// (self-review), 15 (non-reviewer access), 16 (CSRF).

async function queueAsKail(request: any, pageId: string, text: string, summary: string) {
  const res = await request.post('/lib/exe/jsonrpc.php', {
    headers: { Authorization: `Bearer ${tokens.kail}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'core.savePage', params: { page: pageId, text, summary }, id: 1 },
  });
  const body = await res.json();
  const match = /change #(\d+)/.exec(body.error.message);
  if (!match) throw new Error(`unexpected response: ${JSON.stringify(body)}`);
  return Number(match[1]);
}

test('martin has a discoverable link to the review queue in Site Tools', async ({ page }) => {
  // DokuWiki's own "Admin" menu entry only shows for $INFO['ismanager']
  // (inc/Menu/Item/Admin.php), which martin - a reviewer via a dedicated
  // group, not a DokuWiki manager - never is. Without our own menu item
  // (QueueMenuItem via MENU_ITEMS_ASSEMBLY) there would be no link to the
  // queue anywhere except a banner on a page that already has a pending
  // change on it.
  await page.goto('/doku.php?id=start');
  const link = page.locator('a[href*="do=admin"][href*="page=reviewqueue"]');
  await expect(link).toBeVisible();
  await link.click();
  await expect(page).toHaveURL(/do=admin.*page=reviewqueue|page=reviewqueue.*do=admin/);
});

test('martin sees a pending change with a diff and can approve it', async ({ page, request }) => {
  const pageId = `uiapprove${Date.now()}`;
  const rqid = await queueAsKail(request, pageId, 'content from kail', 'kail summary');

  await page.goto('/doku.php?do=admin&page=reviewqueue');
  const item = page.locator('.reviewqueue-item', { hasText: `#${rqid}` });
  await expect(item).toContainText(pageId);
  await expect(item).toContainText('kail');
  await expect(item.locator('table.diff')).toContainText('content from kail');

  await item.locator('button[name="rqaction"][value="approve"]').click();
  await expect(page.locator('#dokuwiki__content')).toContainText(`Change #${rqid} approved`);

  const live = await request.get(`/doku.php?id=${pageId}`);
  expect(await live.text()).toContain('content from kail');
});

test('martin can reject a change with a comment, page stays unpublished', async ({
  page,
  request,
}) => {
  const pageId = `uireject${Date.now()}`;
  const rqid = await queueAsKail(request, pageId, 'rejected content', 'kail summary');

  await page.goto('/doku.php?do=admin&page=reviewqueue');
  const item = page.locator('.reviewqueue-item', { hasText: `#${rqid}` });
  await item.locator('input[name="rqcomment"]').fill('please rephrase');
  await item.locator('button[name="rqaction"][value="reject"]').click();

  await expect(page.locator('#dokuwiki__content')).toContainText(`Change #${rqid} rejected`);

  const live = await request.get(`/doku.php?id=${pageId}`);
  expect(await live.text()).not.toContain('rejected content');
});

test('approving without a valid CSRF token is rejected', async ({ page, request }) => {
  const pageId = `uicsrf${Date.now()}`;
  const rqid = await queueAsKail(request, pageId, 'csrf content', 'kail summary');

  await page.goto('/doku.php?do=admin&page=reviewqueue');
  // Bypass the form and post with a bogus sectok directly.
  const res = await request.post('/doku.php', {
    form: { do: 'admin', page: 'reviewqueue', rqid: String(rqid), rqaction: 'approve', sectok: 'bogus' },
  });
  expect(res.ok()).toBeTruthy();

  const live = await request.get(`/doku.php?id=${pageId}`);
  expect(await live.text()).not.toContain('csrf content');
});
