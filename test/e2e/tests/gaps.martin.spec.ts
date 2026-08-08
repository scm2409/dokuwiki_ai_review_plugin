import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const tokens = JSON.parse(
  fs.readFileSync(path.join(__dirname, '..', '.auth', 'tokens.json'), 'utf-8')
) as Record<string, string>;

// Gaps found while reviewing coverage against strategy.md - see
// docs/testing/coverage-review.md. These cover scenarios 1 (attribution),
// 3 (deletion), 6 (sequential approval), 11 (conflict) and 14 (self-review).

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

async function approve(page: any, rqid: number) {
  await page.goto('/doku.php?do=admin&page=reviewqueue');
  await page.locator(`.reviewqueue-item[data-rqid="${rqid}"] button[value="approve"]`).click();
}

test('an approved change is attributed to its original author, not the reviewer', async ({
  page,
  request,
}) => {
  // The core promise of the whole plugin: martin publishes kail's work, but
  // the history must show kail wrote it, with the review recorded in the summary.
  const pageId = `attrib${Date.now()}`;
  const rqid = await queueAsKail(request, pageId, 'text authored by kail', 'kail wrote this');

  await approve(page, rqid);
  await expect(page.locator('#dokuwiki__content')).toContainText(`Change #${rqid} approved`);

  await page.goto(`/doku.php?id=${pageId}&do=revisions`);
  const history = page.locator('#dokuwiki__content');
  await expect(history).toContainText('kail');
  await expect(history).toContainText(`reviewed by martin, change #${rqid}`);
  await expect(history).not.toContainText('martin</bdi>');
});

test('an approved deletion actually removes the page', async ({ page, request }) => {
  const pageId = `deletion${Date.now()}`;
  await saveAsMartin(request, pageId, 'this page will be deleted');

  const rqid = await queueAsKail(request, pageId, '', 'delete it');

  // Still there while the deletion is only queued.
  let live = await request.get(`/doku.php?id=${pageId}`);
  expect(await live.text()).toContain('this page will be deleted');

  await approve(page, rqid);
  await expect(page.locator('#dokuwiki__content')).toContainText(`Change #${rqid} approved`);

  live = await request.get(`/doku.php?id=${pageId}`);
  expect(await live.text()).not.toContain('this page will be deleted');
});

test('kail cannot approve his own change even by posting directly', async ({ page, request }) => {
  const pageId = `selfapprove${Date.now()}`;
  const rqid = await queueAsKail(request, pageId, 'kail tries to self-approve');

  // Grab a valid sectok from martin's session, then replay the approve as
  // kail - so the only thing standing in the way is the self-review rule.
  await page.goto('/doku.php?do=admin&page=reviewqueue');
  const sectok = await page
    .locator(`.reviewqueue-item[data-rqid="${rqid}"] input[name="sectok"]`)
    .inputValue();

  const kailCookie = fs.readFileSync(
    path.join(__dirname, '..', '.auth', 'kail-storage.json'),
    'utf-8'
  );
  const cookies = JSON.parse(kailCookie).cookies as any[];
  const cookieHeader = cookies.map((c) => `${c.name}=${c.value}`).join('; ');

  const res = await request.post('/doku.php', {
    headers: { Cookie: cookieHeader },
    form: { do: 'admin', page: 'reviewqueue', rqid: String(rqid), rqaction: 'approve', sectok },
  });
  expect(res.ok()).toBeTruthy();

  const live = await request.get(`/doku.php?id=${pageId}`);
  expect(await live.text()).not.toContain('kail tries to self-approve');
});

test('a change whose page moved on is marked conflicted instead of overwriting', async ({
  page,
  request,
}) => {
  // helper/apply.php compares the live page against the change's baseHash.
  // This path is live code, so it needs a test even though the Diff3
  // auto-merge on top of it is still Phase 6.
  const pageId = `conflict${Date.now()}`;
  await saveAsMartin(request, pageId, 'original text');

  const rqid = await queueAsKail(request, pageId, 'kail rewrite based on original');

  // martin edits the page after kail's change was queued.
  await saveAsMartin(request, pageId, 'martin moved this on', 'later edit');

  await approve(page, rqid);
  await expect(page.locator('#dokuwiki__content')).toContainText(
    `Change #${rqid} could not be approved`
  );

  // martin's newer text must survive untouched.
  const live = await request.get(`/doku.php?id=${pageId}`);
  const text = await live.text();
  expect(text).toContain('martin moved this on');
  expect(text).not.toContain('kail rewrite based on original');

  // ...and the change is now flagged for manual handling, not silently gone.
  await page.goto('/doku.php?do=admin&page=reviewqueue');
  await expect(page.locator(`.reviewqueue-item[data-rqid="${rqid}"]`)).toContainText(
    'cannot be approved automatically'
  );
});

test('once approved, a draft leaves the pending search and enters the wiki search', async ({
  page,
  request,
}) => {
  // The handover point: while queued, only searchMyPending finds the text;
  // after approval, the normal wiki search does, and the pending search must
  // no longer report it as outstanding work.
  const pageId = `handover${Date.now()}`;
  const marker = `bilby${Date.now()}`;
  const rqid = await queueAsKail(request, pageId, `An article about ${marker} burrows.`);

  const pendingSearch = (query: string) =>
    request
      .post('/lib/exe/jsonrpc.php', {
        headers: { Authorization: `Bearer ${tokens.kail}`, 'Content-Type': 'application/json' },
        data: {
          jsonrpc: '2.0',
          method: 'plugin.reviewqueue.searchMyPending',
          params: { query },
          id: 1,
        },
      })
      .then((r) => r.json());

  expect((await pendingSearch(marker)).result).toHaveLength(1);

  await approve(page, rqid);
  await expect(page.locator('#dokuwiki__content')).toContainText(`Change #${rqid} approved`);

  // No longer outstanding...
  expect((await pendingSearch(marker)).result).toEqual([]);

  // ...and now findable the normal way.
  const live = await request.post('/lib/exe/jsonrpc.php', {
    headers: { Authorization: `Bearer ${tokens.kail}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'core.searchPages', params: { query: marker }, id: 1 },
  });
  expect((await live.json()).result.map((h: any) => h.id)).toContain(pageId);
});

test('two stacked changes can be approved one after the other', async ({ page, request }) => {
  const pageId = `sequential${Date.now()}`;
  await saveAsMartin(request, pageId, 'base');

  const first = await queueAsKail(request, pageId, 'first draft');
  const second = await queueAsKail(request, pageId, 'second draft');

  await approve(page, first);
  await expect(page.locator('#dokuwiki__content')).toContainText(`Change #${first} approved`);

  let live = await request.get(`/doku.php?id=${pageId}`);
  expect(await live.text()).toContain('first draft');

  // The second was based on 'base', not on 'first draft', so approving it now
  // must be refused as conflicted rather than silently discarding the first.
  await approve(page, second);
  await expect(page.locator('#dokuwiki__content')).toContainText(
    `Change #${second} could not be approved`
  );

  live = await request.get(`/doku.php?id=${pageId}`);
  expect(await live.text()).toContain('first draft');
});
