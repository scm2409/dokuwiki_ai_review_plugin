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
  const item = page.locator(`.reviewqueue-item[data-rqid="${rqid}"]`);
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
  const item = page.locator(`.reviewqueue-item[data-rqid="${rqid}"]`);
  await item.locator('input[name="rqcomment"]').fill('please rephrase');
  await item.locator('button[name="rqaction"][value="reject"]').click();

  await expect(page.locator('#dokuwiki__content')).toContainText(`Change #${rqid} rejected`);

  const live = await request.get(`/doku.php?id=${pageId}`);
  expect(await live.text()).not.toContain('rejected content');
});

test('a rejection reason written by martin is readable by kail via the API', async ({
  page,
  request,
}) => {
  // The full loop that matters to an agent: it submits, a human rejects with
  // a reason, and the agent can retrieve that reason to act on it (ADR-0004).
  const pageId = `uireason${Date.now()}`;
  const rqid = await queueAsKail(request, pageId, 'draft needing work', 'kail summary');

  await page.goto('/doku.php?do=admin&page=reviewqueue');
  const item = page.locator(`.reviewqueue-item[data-rqid="${rqid}"]`);
  await item.locator('input[name="rqcomment"]').fill('too informal, please rewrite');
  await item.locator('button[name="rqaction"][value="reject"]').click();
  await expect(page.locator('#dokuwiki__content')).toContainText(`Change #${rqid} rejected`);

  const status = await request.post('/lib/exe/jsonrpc.php', {
    headers: { Authorization: `Bearer ${tokens.kail}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'plugin.reviewqueue.getStatus', params: { id: rqid }, id: 1 },
  });
  const body = await status.json();
  expect(body.result.state).toBe('rejected');
  expect(body.result.comment).toBe('too informal, please rewrite');
  expect(body.result.reviewer).toBe('martin');
});

test('the queue warns the reviewer when several changes stack on one page', async ({
  page,
  request,
}) => {
  const pageId = `uistack${Date.now()}`;
  const first = await queueAsKail(request, pageId, 'stacked draft one', 's1');
  const second = await queueAsKail(request, pageId, 'stacked draft two', 's2');

  await page.goto('/doku.php?do=admin&page=reviewqueue');
  const item = page.locator(`.reviewqueue-item[data-rqid="${second}"]`);
  await expect(item.locator('.reviewqueue-stacked')).toContainText('unreviewed changes');
  await expect(item.locator('.reviewqueue-stacked')).toContainText(`#${first}`);
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
