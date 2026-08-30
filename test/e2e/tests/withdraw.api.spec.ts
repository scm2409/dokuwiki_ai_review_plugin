import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const tokens = JSON.parse(
  fs.readFileSync(path.join(__dirname, '..', '.auth', 'tokens.json'), 'utf-8')
) as Record<string, string>;

// docs/design/adr-0006: the author's own withdraw/continue lifecycle.
// Covers strategy.md scenario 24.

function rpc(request: any, token: string, method: string, params: any = []) {
  return request
    .post('/lib/exe/jsonrpc.php', {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { jsonrpc: '2.0', method, params, id: 1 },
    })
    .then((r: any) => r.json());
}

async function saveAsMartin(request: any, page: string, text: string, summary = 'setup') {
  const res = await rpc(request, tokens.martin, 'core.savePage', { page, text, summary });
  expect(res.result).toBe(true);
}

async function queueAsKail(request: any, pageId: string, text: string, summary = 's') {
  const res = await rpc(request, tokens.kail, 'core.savePage', { page: pageId, text, summary });
  const match = /change #(\d+)/.exec(res.error.message);
  if (!match) throw new Error(`expected a queue rejection, got ${JSON.stringify(res)}`);
  return Number(match[1]);
}

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

test('an author can withdraw their own pending change', async ({ request }) => {
  const pageId = `withdraw${Date.now()}`;
  const rqid = await queueAsKail(request, pageId, 'draft text', 'a draft');

  const res = await rpc(request, tokens.kail, 'plugin.reviewqueue.withdrawPendingChange', {
    id: rqid,
    reason: 'changed my mind',
  });
  expect(res.error).toBeUndefined();
  expect(res.result.state).toBe('withdrawn');

  const pending = await rpc(request, tokens.kail, 'plugin.reviewqueue.listMyPending');
  expect(pending.result.map((r: any) => r.id)).not.toContain(rqid);

  const status = await rpc(request, tokens.kail, 'plugin.reviewqueue.getStatus', { id: rqid });
  expect(status.result.state).toBe('withdrawn');
  expect(status.result.comment).toBe('changed my mind');

  // Not a reviewer decision: no reviewer is recorded, unlike a rejection.
  const record = await rpc(request, tokens.martin, 'plugin.reviewqueue.getStatus', { id: rqid });
  expect(record.result.reviewer).toBe('');

  // Gone from the admin queue too.
  const adminPage = await request.get('/doku.php?do=admin&page=reviewqueue');
  expect(await adminPage.text()).not.toContain(`data-rqid="${rqid}"`);
});

test('only the author can withdraw their change - not even a reviewer', async ({ request }) => {
  const pageId = `withdrawother${Date.now()}`;
  const rqid = await queueAsKail(request, pageId, 'draft text', 's');

  const res = await rpc(request, tokens.martin, 'plugin.reviewqueue.withdrawPendingChange', { id: rqid });
  expect(res.error).toBeTruthy();
  // DokuWiki's JSON-RPC layer replaces any AccessDeniedException's own
  // message with this generic wording (inc/Remote/JsonRpcServer.php) rather
  // than passing "This change is not yours" through - asserted directly so
  // a future core change to that behaviour is caught here.
  expect(res.error.message).toMatch(/forbidden to call the method/);

  // Still pending - the refusal actually blocked it.
  const status = await rpc(request, tokens.kail, 'plugin.reviewqueue.getStatus', { id: rqid });
  expect(status.result.state).toBe('pending');
});

test('a conflicted change cannot be withdrawn', async ({ request }) => {
  const pageId = `withdrawconflict${Date.now()}`;
  const original = '==== Section A ====\n\nOriginal A content.\n';
  await saveAsMartin(request, pageId, original);

  const rqid = await queueAsKail(
    request,
    pageId,
    original.replace('Original A content.', 'kail version of A.'),
    'kail edits A'
  );
  await saveAsMartin(request, pageId, original.replace('Original A content.', 'martin version of A.'), 'martin edits A');

  const approveRes = await approveAsMartin(request, rqid);
  expect(await approveRes.text()).toContain('could not be approved');

  const status = await rpc(request, tokens.kail, 'plugin.reviewqueue.getStatus', { id: rqid });
  expect(status.result.state).toBe('conflicted');

  const withdraw = await rpc(request, tokens.kail, 'plugin.reviewqueue.withdrawPendingChange', { id: rqid });
  expect(withdraw.error).toBeTruthy();
  expect(withdraw.error.message).toMatch(/is conflicted, not pending/);
});

test('a decided change cannot be withdrawn again', async ({ request }) => {
  const pageId = `withdrawapproved${Date.now()}`;
  const rqid = await queueAsKail(request, pageId, 'approved text', 's');

  const approveRes = await approveAsMartin(request, rqid);
  expect(await approveRes.text()).toContain('approved and published');

  const withdraw = await rpc(request, tokens.kail, 'plugin.reviewqueue.withdrawPendingChange', { id: rqid });
  expect(withdraw.error).toBeTruthy();
  expect(withdraw.error.message).toMatch(/is approved, not pending/);
});
