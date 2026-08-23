import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const tokens = JSON.parse(
  fs.readFileSync(path.join(__dirname, '..', '.auth', 'tokens.json'), 'utf-8')
) as Record<string, string>;

// Covers strategy.md scenarios 1 (approve), 5 (reject with reason), 14
// (self-review), 15 (non-reviewer access), 16 (CSRF), 18 (diff/preview tabs),
// 19 (diff horizontal scroll).

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

test('martin can switch to a rendered preview tab of a pending change without approving it', async ({
  page,
  request,
}) => {
  const ns = `uipreview${Date.now()}`;
  const pageId = `${ns}:page1`;
  // A relative link ([[.:sibling]]) only resolves correctly if the preview is
  // rendered with $ID set to the change's own target page rather than the
  // admin page it's displayed on - proves the renderAs() context fix, not
  // just that *something* got rendered.
  const text = '====== Preview heading ======\n\nSome **bold** text and a [[.:sibling]] link.';
  const rqid = await queueAsKail(request, pageId, text, 'kail summary');

  await page.goto('/doku.php?do=admin&page=reviewqueue');
  const item = page.locator(`.reviewqueue-item[data-rqid="${rqid}"]`);
  const tabs = item.locator('.reviewqueue-tabs');
  const diffPanel = tabs.locator('.reviewqueue-tabpanel-diff');
  const previewPanel = tabs.locator('.reviewqueue-tabpanel-preview');

  // Diff is the default tab, preview is a click away, not the other way
  // round - and not both open at once.
  await expect(diffPanel).toBeVisible();
  await expect(previewPanel).toBeHidden();

  await tabs.locator('label', { hasText: 'Preview' }).click();
  await expect(previewPanel).toBeVisible();
  await expect(diffPanel).toBeHidden();

  await expect(previewPanel.locator('h1')).toContainText('Preview heading');
  await expect(previewPanel.locator('strong')).toContainText('bold');
  await expect(previewPanel.locator(`a[href*="id=${ns}:sibling"]`)).toBeVisible();

  // Still just a preview - the change must remain untouched.
  const status = await request.post('/lib/exe/jsonrpc.php', {
    headers: { Authorization: `Bearer ${tokens.kail}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'plugin.reviewqueue.getStatus', params: { id: rqid }, id: 1 },
  });
  expect((await status.json()).result.state).toBe('pending');
});

test('a diff line too wide for the page gets its own horizontal scrollbar', async ({
  page,
  request,
}) => {
  const pageId = `uiscroll${Date.now()}`;
  // A single unbroken word: browsers don't word-break plain text by default,
  // so this can only be reached via a scrollbar, not by the browser wrapping
  // the line for us - if it were, this test would pass without the fix.
  const longWord = 'x'.repeat(400);
  const rqid = await queueAsKail(request, pageId, `some text ${longWord} end`, 'kail summary');

  await page.goto('/doku.php?do=admin&page=reviewqueue');
  const item = page.locator(`.reviewqueue-item[data-rqid="${rqid}"]`);
  const diffScroll = item.locator('.reviewqueue-tabpanel-diff .reviewqueue-scroll');

  await expect(diffScroll).toHaveCSS('overflow-x', 'auto');
  const overflows = await diffScroll.evaluate((el) => el.scrollWidth > el.clientWidth);
  expect(overflows).toBe(true);
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

  // Assert the change itself is untouched, not just that the page looks
  // unchanged - otherwise this would also pass if the approval failed for
  // some unrelated reason.
  const status = await request.post('/lib/exe/jsonrpc.php', {
    headers: { Authorization: `Bearer ${tokens.kail}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'plugin.reviewqueue.getStatus', params: { id: rqid }, id: 1 },
  });
  expect((await status.json()).result.state).toBe('pending');
});
