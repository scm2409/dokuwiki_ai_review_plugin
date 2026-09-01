import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { tokens, rpc, saveAsMartin } from './_helpers';

// Phase 10 (docs/design/adr-0005, adr-0006): write tools that change part of
// a page instead of resubmitting the whole thing, and - the actual problem
// this phase exists to fix - continue the author's own open draft in place
// instead of stacking a new queue entry every time. Covers strategy.md
// scenarios 22-23.

function cookieHeaderFor(user: string) {
  const storage = JSON.parse(
    fs.readFileSync(path.join(__dirname, '..', '.auth', `${user}-storage.json`), 'utf-8')
  );
  return (storage.cookies as any[]).map((c) => `${c.name}=${c.value}`).join('; ');
}

async function approveAsMartin(request: any, rqid: number) {
  // The admin page's own session cookie, read from martin's storageState -
  // same cross-user-cookie approach gaps.martin.spec.ts uses, just without
  // needing a browser `page` fixture in this api-project file.
  const cookie = cookieHeaderFor('martin');
  const html = await (
    await request.get('/doku.php?do=admin&page=reviewqueue', { headers: { Cookie: cookie } })
  ).text();
  const sectok = /name="sectok" value="([^"]+)"/.exec(html)![1];
  // rqhash is per-record (the content hash this page render actually saw -
  // see admin.php::renderForm()), unlike sectok which is one page-wide CSRF
  // token - so it must come from this specific rqid's own block, not just
  // the first match on the page.
  const block = new RegExp(`data-rqid="${rqid}".*?</form>`, 's').exec(html)![0];
  const rqhash = /name="rqhash" value="([^"]*)"/.exec(block)![1];
  return request.post('/doku.php', {
    headers: { Cookie: cookie },
    form: { do: 'admin', page: 'reviewqueue', rqid: String(rqid), rqaction: 'approve', rqhash, sectok },
  });
}

test('replaceSection on a fresh draft queues a new change', async ({ request }) => {
  const pageId = `rwqueue${Date.now()}`;
  await saveAsMartin(request, pageId, '====== T ======\n\n===== A =====\n\noriginal A\n');

  const res = await rpc(request, tokens.kail, 'plugin.reviewqueue.replaceSection', {
    page: pageId,
    section: 'A',
    text: '===== A =====\n\nreplaced A\n',
    summary: 'edit A',
  });
  expect(res.error).toBeUndefined();
  expect(res.result.status).toBe('queued');
  expect(res.result.pendingId).toBeGreaterThan(0);

  const live = await request.get(`/doku.php?id=${pageId}`);
  expect(await live.text()).not.toContain('replaced A');
});

test(
  'the central regression: a second range write continues the same change instead of stacking a new one',
  async ({ request }) => {
    const pageId = `rwcontinue${Date.now()}`;
    await saveAsMartin(
      request,
      pageId,
      '====== T ======\n\n===== A =====\n\noriginal A\n\n===== B =====\n\noriginal B\n'
    );

    const first = await rpc(request, tokens.kail, 'plugin.reviewqueue.replaceSection', {
      page: pageId,
      section: 'A',
      text: '===== A =====\n\nreplaced A\n',
      summary: 'edit A',
    });
    expect(first.result.status).toBe('queued');
    const rqid = first.result.pendingId;

    const second = await rpc(request, tokens.kail, 'plugin.reviewqueue.replaceSection', {
      page: pageId,
      section: 'B',
      text: '===== B =====\n\nreplaced B\n',
    });
    expect(second.result.status).toBe('updated');
    expect(second.result.pendingId).toBe(rqid); // same change, not a second one

    const pending = await rpc(request, tokens.kail, 'plugin.reviewqueue.listMyPending');
    const forThisPage = pending.result.filter((r: any) => r.target === pageId);
    expect(forThisPage).toHaveLength(1);
    // The first call's summary survives an unrelated second call that
    // didn't pass one - it must not be blanked out.
    expect(forThisPage[0].summary).toBe('edit A');

    const text = await rpc(request, tokens.kail, 'plugin.reviewqueue.getPendingText', { id: rqid });
    expect(text.result).toContain('replaced A');
    expect(text.result).toContain('replaced B');

    const approveRes = await approveAsMartin(request, rqid);
    expect(await approveRes.text()).toContain('approved and published');

    const live = await request.get(`/doku.php?id=${pageId}`);
    const liveText = await live.text();
    expect(liveText).toContain('replaced A');
    expect(liveText).toContain('replaced B');
  }
);

test('a range write refuses a stale $expect', async ({ request }) => {
  const pageId = `rwstale${Date.now()}`;
  await saveAsMartin(request, pageId, '====== T ======\n\n===== A =====\n\noriginal A\n');

  const res = await rpc(request, tokens.kail, 'plugin.reviewqueue.replaceSection', {
    page: pageId,
    section: 'A',
    text: 'irrelevant',
    expect: 'notarealhash0',
  });
  expect(res.error).toBeTruthy();
  expect(res.error.message).toMatch(/changed since you last read it/);
});

test('replaceLines requires $expect and rejects an out-of-bounds range', async ({ request }) => {
  const pageId = `rwlines${Date.now()}`;
  await saveAsMartin(request, pageId, 'one\ntwo\nthree\n');

  const missing = await rpc(request, tokens.kail, 'plugin.reviewqueue.replaceLines', {
    page: pageId,
    from: 1,
    to: 1,
    text: 'ONE',
    expect: '',
  });
  expect(missing.error.message).toMatch(/requires \$expect/);

  const outOfBounds = await rpc(request, tokens.kail, 'plugin.reviewqueue.replaceLines', {
    page: pageId,
    from: 99,
    to: 99,
    text: 'x',
    expect: 'irrelevant',
  });
  expect(outOfBounds.error).toBeTruthy();
});

test('replaceText refuses an ambiguous match unless $all is set', async ({ request }) => {
  const pageId = `rwtext${Date.now()}`;
  await saveAsMartin(request, pageId, 'foo bar\nfoo baz\n');

  const ambiguous = await rpc(request, tokens.kail, 'plugin.reviewqueue.replaceText', {
    page: pageId,
    search: 'foo',
    replace: 'FOO',
  });
  expect(ambiguous.error.message).toMatch(/occurs 2 times/);

  const all = await rpc(request, tokens.kail, 'plugin.reviewqueue.replaceText', {
    page: pageId,
    search: 'foo',
    replace: 'FOO',
    all: true,
  });
  expect(all.result.status).toBe('queued');

  const text = await rpc(request, tokens.kail, 'plugin.reviewqueue.getPendingText', {
    id: all.result.pendingId,
  });
  expect(text.result).toBe('FOO bar\nFOO baz\n');
});

test('a range write that would empty the page is refused', async ({ request }) => {
  const pageId = `rwempty${Date.now()}`;
  await saveAsMartin(request, pageId, 'the only line\n');

  const res = await rpc(request, tokens.kail, 'plugin.reviewqueue.replaceLines', {
    page: pageId,
    from: 1,
    to: 1,
    text: '',
    expect: (
      await rpc(request, tokens.kail, 'plugin.reviewqueue.getLines', { page: pageId, from: 1, count: 1 })
    ).result.hash,
  });
  expect(res.error).toBeTruthy();
  expect(res.error.message).toMatch(/would leave .* empty/);
});

test('a caller not subject to review gets an immediate live write instead of a queued one', async ({
  request,
}) => {
  const pageId = `rwlive${Date.now()}`;
  await saveAsMartin(request, pageId, '====== T ======\n\noriginal\n');

  const res = await rpc(request, tokens.martin, 'plugin.reviewqueue.replaceText', {
    page: pageId,
    search: 'original',
    replace: 'martin live edit',
  });
  expect(res.result.status).toBe('live');
  expect(res.result.pendingId).toBe(0);

  const live = await request.get(`/doku.php?id=${pageId}`);
  expect(await live.text()).toContain('martin live edit');
});

test('insertSection places new text before/after/start/end of an anchor correctly', async ({
  request,
}) => {
  const pageId = `rwinsert${Date.now()}`;
  await saveAsMartin(
    request,
    pageId,
    '====== T ======\n\n===== A =====\n\nA body.\n\n===== B =====\n\nB body.\n'
  );

  const after = await rpc(request, tokens.kail, 'plugin.reviewqueue.insertSection', {
    page: pageId,
    anchor: 'A',
    position: 'after',
    text: '===== A.1 =====\n\nnew sibling after A\n',
  });
  expect(after.result.status).toBe('queued');
  const rqid = after.result.pendingId;

  const text = await rpc(request, tokens.kail, 'plugin.reviewqueue.getPendingText', { id: rqid });
  // A.1 lands between A and B, not appended at the end of the page.
  const order = ['A body.', 'new sibling after A', 'B body.'].map((needle) => text.result.indexOf(needle));
  expect(order[0]).toBeLessThan(order[1]);
  expect(order[1]).toBeLessThan(order[2]);
});

test('deleteSection removes a heading and everything nested under it', async ({ request }) => {
  const pageId = `rwdelete${Date.now()}`;
  await saveAsMartin(
    request,
    pageId,
    '====== T ======\n\n===== A =====\n\nA body.\n\n==== A Child ====\n\nchild body.\n\n===== B =====\n\nB body.\n'
  );

  const res = await rpc(request, tokens.kail, 'plugin.reviewqueue.deleteSection', {
    page: pageId,
    section: 'A',
  });
  expect(res.result.status).toBe('queued');

  const text = await rpc(request, tokens.kail, 'plugin.reviewqueue.getPendingText', {
    id: res.result.pendingId,
  });
  expect(text.result).not.toContain('A body');
  expect(text.result).not.toContain('child body');
  expect(text.result).toContain('B body');
});

test('replaceSection accepts hashWithChildren, but not the plain hash, as $expect for a section with children', async ({
  request,
}) => {
  const pageId = `rwhashchildren${Date.now()}`;
  await saveAsMartin(
    request,
    pageId,
    '====== T ======\n\n===== A =====\n\nA body.\n\n==== A Child ====\n\nchild body.\n'
  );

  const outline = await rpc(request, tokens.kail, 'plugin.reviewqueue.getPageOutline', { page: pageId });
  const a = outline.result.sections.find((s: any) => s.title === 'A');
  expect(a.hashWithChildren).not.toBe(a.hash);

  // The plain "hash" (own text only) never matches what replaceSection
  // actually checks against, since it always includes nested subsections.
  const wrongExpect = await rpc(request, tokens.kail, 'plugin.reviewqueue.replaceSection', {
    page: pageId,
    section: 'A',
    text: '===== A =====\n\nreplaced\n',
    expect: a.hash,
  });
  expect(wrongExpect.error).toBeTruthy();
  expect(wrongExpect.error.message).toMatch(/changed since you last read it/);

  // hashWithChildren is the correct one and the write goes through.
  const rightExpect = await rpc(request, tokens.kail, 'plugin.reviewqueue.replaceSection', {
    page: pageId,
    section: 'A',
    text: '===== A =====\n\nreplaced\n',
    expect: a.hashWithChildren,
  });
  expect(rightExpect.error).toBeUndefined();
  expect(rightExpect.result.status).toBe('queued');
});

test('approving a change refuses when the author changed it after this page was rendered', async ({
  request,
}) => {
  // Regression: since Phase 10 a pending change's content is no longer
  // immutable (docs/design/adr-0006) - if approve() simply re-read the
  // content by id at submit time, an author continuing their draft between
  // the reviewer loading the admin page and clicking Approve would get
  // text published that the reviewer never actually saw.
  const pageId = `rwtoctou${Date.now()}`;
  const queued = await rpc(request, tokens.kail, 'plugin.reviewqueue.createPage', {
    page: pageId,
    text: 'original text',
    summary: 'v1',
  });
  const rqid = queued.result.pendingId as number;

  // martin "opens" the admin page (capturing the rqhash it renders)...
  const cookie = cookieHeaderFor('martin');
  const htmlBefore = await (
    await request.get('/doku.php?do=admin&page=reviewqueue', { headers: { Cookie: cookie } })
  ).text();
  const blockBefore = new RegExp(`data-rqid="${rqid}".*?</form>`, 's').exec(htmlBefore)![0];
  const staleHash = /name="rqhash" value="([^"]*)"/.exec(blockBefore)![1];
  const sectok = /name="sectok" value="([^"]+)"/.exec(htmlBefore)![1];

  // ...then, before martin clicks Approve, kail continues the draft.
  const updated = await rpc(request, tokens.kail, 'plugin.reviewqueue.updatePendingChange', {
    id: rqid,
    text: 'MUTATED text',
  });
  expect(updated.result.status).toBe('updated');

  // martin's stale form submission must be refused, not silently publish
  // the mutated text.
  await request.post('/doku.php', {
    headers: { Cookie: cookie },
    form: { do: 'admin', page: 'reviewqueue', rqid: String(rqid), rqaction: 'approve', rqhash: staleHash, sectok },
  });
  const live = await request.get(`/doku.php?id=${pageId}`);
  expect(await live.text()).not.toContain('MUTATED text');
  const status = await rpc(request, tokens.kail, 'plugin.reviewqueue.getStatus', { id: rqid });
  expect(status.result.state).toBe('pending');

  // Approving again with the *current* hash succeeds normally.
  const approveRes = await approveAsMartin(request, rqid);
  expect(await approveRes.text()).toContain('approved and published');
  expect(await (await request.get(`/doku.php?id=${pageId}`)).text()).toContain('MUTATED text');
});
