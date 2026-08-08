import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const tokens = JSON.parse(
  fs.readFileSync(path.join(__dirname, '..', '.auth', 'tokens.json'), 'utf-8')
) as Record<string, string>;

// strategy.md scenarios 10 (auto-merge of disjoint edits) and 11 (real
// conflict -> manual resolution).

const PAGE = (marker: string) => `Intro paragraph.

==== Section A ====

Original A content.

==== Section B ====

Original B content.${marker}`;

async function queueAsKail(request: any, pageId: string, text: string, summary = 's') {
  const res = await request.post('/lib/exe/jsonrpc.php', {
    headers: { Authorization: `Bearer ${tokens.kail}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'core.savePage', params: { page: pageId, text, summary }, id: 1 },
  });
  const body = await res.json();
  const match = /change #(\d+)/.exec(body.error.message);
  if (!match) throw new Error(`expected a queue rejection, got ${JSON.stringify(body)}`);
  return Number(match[1]);
}

async function saveAsMartin(request: any, page: string, text: string, summary = 'setup') {
  const res = await request.post('/lib/exe/jsonrpc.php', {
    headers: { Authorization: `Bearer ${tokens.martin}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'core.savePage', params: { page, text, summary }, id: 1 },
  });
  expect((await res.json()).result).toBe(true);
}

test('disjoint edits are merged automatically on approval', async ({ page, request }) => {
  const pageId = `merge${Date.now()}`;
  await saveAsMartin(request, pageId, PAGE(''));

  // kail rewrites section A only.
  const rqid = await queueAsKail(request, pageId, PAGE('').replace('Original A content.', 'Rewritten A content by kail.'));

  // martin meanwhile rewrites section B only - a different part of the page.
  await saveAsMartin(
    request,
    pageId,
    PAGE('').replace('Original B content.', 'Rewritten B content by martin.'),
    'martin edits B'
  );

  await page.goto('/doku.php?do=admin&page=reviewqueue');
  await page.locator(`.reviewqueue-item[data-rqid="${rqid}"] button[value="approve"]`).click();
  await expect(page.locator('#dokuwiki__content')).toContainText(`Change #${rqid} approved`);

  // Both edits must survive.
  const live = await request.post('/lib/exe/jsonrpc.php', {
    headers: { Authorization: `Bearer ${tokens.martin}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'core.getPage', params: { page: pageId }, id: 1 },
  });
  const text = (await live.json()).result as string;
  expect(text).toContain('Rewritten A content by kail.');
  expect(text).toContain('Rewritten B content by martin.');
  expect(text).not.toContain('<<<<<<<');
});

test('overlapping edits conflict and are resolved by hand', async ({ page, request }) => {
  const pageId = `conflictres${Date.now()}`;
  await saveAsMartin(request, pageId, PAGE(''));

  // Both change the *same* section, so Diff3 cannot reconcile them.
  const rqid = await queueAsKail(request, pageId, PAGE('').replace('Original A content.', 'kail version of A.'));
  await saveAsMartin(
    request,
    pageId,
    PAGE('').replace('Original A content.', 'martin version of A.'),
    'martin edits A'
  );

  await page.goto('/doku.php?do=admin&page=reviewqueue');
  const item = page.locator(`.reviewqueue-item[data-rqid="${rqid}"]`);
  await item.locator('button[value="approve"]').click();
  await expect(page.locator('#dokuwiki__content')).toContainText(
    `Change #${rqid} could not be approved`
  );

  // The live page is untouched, and the reviewer is offered the merged text
  // with conflict markers to edit.
  const conflicted = page.locator(`.reviewqueue-item[data-rqid="${rqid}"]`);
  const textarea = conflicted.locator('textarea[name="rqtext"]');
  await expect(textarea).toBeVisible();
  const merged = await textarea.inputValue();
  expect(merged).toContain('<<<<<<<');
  expect(merged).toContain('kail version of A.');
  expect(merged).toContain('martin version of A.');

  // Submitting with the markers still in must be refused.
  await conflicted.locator('button[value="resolve"]').click();
  await expect(page.locator('#dokuwiki__content')).toContainText('still contains conflict markers');

  // Resolve properly.
  const resolvedText = PAGE('').replace('Original A content.', 'agreed version of A.');
  const stillConflicted = page.locator(`.reviewqueue-item[data-rqid="${rqid}"]`);
  await stillConflicted.locator('textarea[name="rqtext"]').fill(resolvedText);
  await stillConflicted.locator('button[value="resolve"]').click();
  await expect(page.locator('#dokuwiki__content')).toContainText(`Change #${rqid} resolved`);

  const live = await request.post('/lib/exe/jsonrpc.php', {
    headers: { Authorization: `Bearer ${tokens.martin}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'core.getPage', params: { page: pageId }, id: 1 },
  });
  const text = (await live.json()).result as string;
  expect(text).toContain('agreed version of A.');
  expect(text).not.toContain('<<<<<<<');

  // The change is closed and attributed to kail.
  const status = await request.post('/lib/exe/jsonrpc.php', {
    headers: { Authorization: `Bearer ${tokens.kail}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'plugin.reviewqueue.getStatus', params: { id: rqid }, id: 1 },
  });
  expect((await status.json()).result.state).toBe('approved');
});
