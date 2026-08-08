import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const tokens = JSON.parse(
  fs.readFileSync(path.join(__dirname, '..', '.auth', 'tokens.json'), 'utf-8')
) as Record<string, string>;

// Covers ADR-0004: a queued change is invisible in the read path, so the
// author needs explicit tools and warnings to avoid clobbering their own
// still-unreviewed work.

function rpc(request: any, token: string, method: string, params: any = []) {
  return request
    .post('/lib/exe/jsonrpc.php', {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { jsonrpc: '2.0', method, params, id: 1 },
    })
    .then((r: any) => r.json());
}

async function queue(request: any, page: string, text: string, summary = 's') {
  const body = await rpc(request, tokens.kail, 'core.savePage', { page, text, summary });
  const match = /change #(\d+)/.exec(body.error.message);
  if (!match) throw new Error(`expected a queue rejection, got ${JSON.stringify(body)}`);
  return { id: Number(match[1]), message: body.error.message as string };
}

test('a queued change stays out of the read path and the search index', async ({ request }) => {
  const pageId = `vis${Date.now()}`;
  await rpc(request, tokens.martin, 'core.savePage', {
    page: pageId,
    text: 'LIVE text mentioning aardvarks',
    summary: 'setup',
  });
  await queue(request, pageId, 'DRAFT text mentioning pangolins');

  // The author reading the page back gets the live text, not their draft.
  const read = await rpc(request, tokens.kail, 'core.getPage', { page: pageId });
  expect(read.result).toBe('LIVE text mentioning aardvarks');

  // The draft is not findable by search, for the author either.
  const draftHits = await rpc(request, tokens.kail, 'core.searchPages', { query: 'pangolins' });
  expect(draftHits.result).toEqual([]);

  const liveHits = await rpc(request, tokens.kail, 'core.searchPages', { query: 'aardvarks' });
  expect(liveHits.result.map((h: any) => h.id)).toContain(pageId);
});

test('getPageToEdit returns the live text when nothing is pending', async ({ request }) => {
  const pageId = `visclean${Date.now()}`;
  await rpc(request, tokens.martin, 'core.savePage', {
    page: pageId,
    text: 'just the live text',
    summary: 'setup',
  });

  const res = await rpc(request, tokens.kail, 'plugin.reviewqueue.getPageToEdit', { page: pageId });
  expect(res.result.text).toBe('just the live text');
  expect(res.result.source).toBe('live');
  expect(res.result.pendingId).toBe(0);
  expect(res.result.warning).toBe('');
});

test('getPageToEdit returns your own pending draft so edits build on it', async ({ request }) => {
  const pageId = `vispending${Date.now()}`;
  await rpc(request, tokens.martin, 'core.savePage', {
    page: pageId,
    text: 'live version',
    summary: 'setup',
  });
  const { id } = await queue(request, pageId, 'my unreviewed draft');

  const res = await rpc(request, tokens.kail, 'plugin.reviewqueue.getPageToEdit', { page: pageId });
  expect(res.result.text).toBe('my unreviewed draft');
  expect(res.result.source).toBe('pending');
  expect(res.result.pendingId).toBe(id);
});

test('stacking a second change on the same page warns the author by change id', async ({
  request,
}) => {
  const pageId = `visstack${Date.now()}`;
  await rpc(request, tokens.martin, 'core.savePage', {
    page: pageId,
    text: 'live version',
    summary: 'setup',
  });

  const first = await queue(request, pageId, 'draft one');
  expect(first.message).not.toMatch(/already have unreviewed/);

  const second = await queue(request, pageId, 'draft two');
  expect(second.message).toMatch(/already have unreviewed change\(s\) #\d+/);
  expect(second.message).toContain(`#${first.id}`);
  expect(second.message).toMatch(/overwritten/);

  // getPageToEdit surfaces the stack too, and hands back the newest draft.
  const res = await rpc(request, tokens.kail, 'plugin.reviewqueue.getPageToEdit', { page: pageId });
  expect(res.result.text).toBe('draft two');
  expect(res.result.warning).toContain('stacked');
});

test('listMyPending and getStatus report the author own changes', async ({ request }) => {
  const pageId = `visstatus${Date.now()}`;
  const { id } = await queue(request, pageId, 'some draft', 'my summary');

  const list = await rpc(request, tokens.kail, 'plugin.reviewqueue.listMyPending');
  const entry = list.result.find((r: any) => r.id === id);
  expect(entry).toBeTruthy();
  expect(entry.target).toBe(pageId);
  expect(entry.summary).toBe('my summary');
  expect(entry.state).toBe('pending');

  const status = await rpc(request, tokens.kail, 'plugin.reviewqueue.getStatus', { id });
  expect(status.result.state).toBe('pending');
  expect(status.result.target).toBe(pageId);

  const text = await rpc(request, tokens.kail, 'plugin.reviewqueue.getPendingText', { id });
  expect(text.result).toBe('some draft');
});

test('searchMyPending finds drafts that the wiki search cannot see', async ({ request }) => {
  // The gap getPageToEdit cannot close: searching by topic rather than by
  // page. Without this the author concludes a topic is uncovered and writes
  // a second version on a different page.
  const pageId = `vissearch${Date.now()}`;
  const marker = `quokka${Date.now()}`;
  await queue(request, pageId, `A draft discussing ${marker} behaviour at length.`, 'draft summary');

  // The published index knows nothing about it.
  const liveHits = await rpc(request, tokens.kail, 'core.searchPages', { query: marker });
  expect(liveHits.result).toEqual([]);

  const mine = await rpc(request, tokens.kail, 'plugin.reviewqueue.searchMyPending', {
    query: marker,
  });
  expect(mine.result).toHaveLength(1);
  expect(mine.result[0].target).toBe(pageId);
  expect(mine.result[0].snippet).toContain(marker);

  // Matching is case-insensitive and also covers the summary.
  const upper = await rpc(request, tokens.kail, 'plugin.reviewqueue.searchMyPending', {
    query: marker.toUpperCase(),
  });
  expect(upper.result).toHaveLength(1);

  const bySummary = await rpc(request, tokens.kail, 'plugin.reviewqueue.searchMyPending', {
    query: 'draft summary',
  });
  expect(bySummary.result.map((h: any) => h.target)).toContain(pageId);

  // A term that appears nowhere returns nothing rather than everything.
  const none = await rpc(request, tokens.kail, 'plugin.reviewqueue.searchMyPending', {
    query: 'definitelynotpresentanywhere',
  });
  expect(none.result).toEqual([]);
});

test('queuing a change does not strand the page lock against other editors', async ({
  request,
}) => {
  // ApiCore::savePage() is lock() -> saveWikiText() -> unlock(). Our
  // RemoteException is thrown from inside saveWikiText(), so without an
  // explicit release the unlock() never runs and the page stays locked for
  // the whole lock timeout - letting a busy agent block human editors out
  // of every page it touches.
  const pageId = `vislock${Date.now()}`;
  await rpc(request, tokens.martin, 'core.savePage', {
    page: pageId,
    text: 'base',
    summary: 'setup',
  });

  await queue(request, pageId, 'kail draft');

  const martinSave = await rpc(request, tokens.martin, 'core.savePage', {
    page: pageId,
    text: 'martin edits right after',
    summary: 'after queue',
  });
  expect(martinSave.error).toBeUndefined();
  expect(martinSave.result).toBe(true);
});

test('a reviewer may read a pending change, an unknown id errors out', async ({ request }) => {
  const pageId = `visacl${Date.now()}`;
  const { id } = await queue(request, pageId, 'kail private draft');

  // martin is a reviewer, so he legitimately may look at it.
  const asReviewer = await rpc(request, tokens.martin, 'plugin.reviewqueue.getPendingText', { id });
  expect(asReviewer.result).toBe('kail private draft');

  // An unknown change id must error rather than leak anything.
  const missing = await rpc(request, tokens.kail, 'plugin.reviewqueue.getStatus', { id: 999999 });
  expect(missing.error).toBeTruthy();
  expect(missing.result).toBeUndefined();
});
