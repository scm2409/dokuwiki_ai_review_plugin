import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const tokens = JSON.parse(
  fs.readFileSync(path.join(__dirname, '..', '.auth', 'tokens.json'), 'utf-8')
) as Record<string, string>;

const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64'
);

function rpc(request: any, token: string, method: string, params: any = []) {
  return request
    .post('/lib/exe/jsonrpc.php', {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { jsonrpc: '2.0', method, params, id: 1 },
    })
    .then((r: any) => r.json());
}

// The guarantee this whole plugin exists for: a review-scoped account must
// not be able to change anything in the wiki without a human approving it.
// This walks the remote API surface rather than trusting that the two
// intercepted paths are the only ones - so a future DokuWiki release adding
// a mutating method shows up here as a failure instead of a silent hole.

// Every method the MCP plugin does not mark read-only, and why it is safe.
// A method appearing here that is not in this map fails the audit below - so
// a DokuWiki upgrade or a newly installed plugin that adds a write path is
// caught rather than silently unreviewed.
const MUTATING: Record<string, 'queued' | 'admin-only' | 'harmless' | 'own-tool' | 'own-queue'> = {
  core_savePage: 'queued',
  core_appendPage: 'queued',
  core_saveMedia: 'queued',
  core_deleteMedia: 'queued',
  // Range writes go through the same ApiCore::savePage() call core.savePage
  // itself uses (see remote.php::writeEffectiveText()), so they are queued
  // exactly the same way and never bypass review.
  plugin_reviewqueue_replaceSection: 'queued',
  plugin_reviewqueue_insertSection: 'queued',
  plugin_reviewqueue_deleteSection: 'queued',
  plugin_reviewqueue_replaceLines: 'queued',
  plugin_reviewqueue_replaceText: 'queued',
  // Locks are transient and cannot alter content; login/logoff affect only
  // the caller's own session.
  core_lockPages: 'harmless',
  core_unlockPages: 'harmless',
  core_login: 'harmless',
  core_logoff: 'harmless',
  // Core admin plugins refuse non-admins themselves; asserted below.
  plugin_acl_addAcl: 'admin-only',
  plugin_acl_delAcl: 'admin-only',
  plugin_acl_listAcls: 'admin-only',
  plugin_usermanager_createUser: 'admin-only',
  plugin_usermanager_deleteUser: 'admin-only',
  // Our own queue tools are read-only in effect, but the MCP plugin only
  // knows its hardcoded list of core method names, so they land here too.
  plugin_reviewqueue_getPageToEdit: 'own-tool',
  plugin_reviewqueue_listMyPending: 'own-tool',
  plugin_reviewqueue_searchMyPending: 'own-tool',
  plugin_reviewqueue_getStatus: 'own-tool',
  plugin_reviewqueue_getPendingText: 'own-tool',
  plugin_reviewqueue_getPageOutline: 'own-tool',
  plugin_reviewqueue_getSection: 'own-tool',
  plugin_reviewqueue_getLines: 'own-tool',
  plugin_reviewqueue_findInPage: 'own-tool',
  plugin_reviewqueue_searchWithContext: 'own-tool',
  // These do write, but only to a queue entry the caller already owns and
  // that has not been reviewed yet - they can never make unreviewed content
  // live, which is the guarantee this audit exists to protect.
  plugin_reviewqueue_updatePendingChange: 'own-queue',
  plugin_reviewqueue_withdrawPendingChange: 'own-queue',
};

test('every mutating remote method is accounted for', async ({ request }) => {
  const res = await request.post('/lib/plugins/mcp/mcp.php', {
    headers: { Authorization: `Bearer ${tokens.kail}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'tools/list', params: {}, id: 1 },
  });
  const tools = (await res.json()).result.tools as any[];

  const mutating = tools
    .filter((t) => t.annotations?.readOnlyHint !== true)
    .map((t) => t.name)
    .sort();

  expect(mutating).toEqual(Object.keys(MUTATING).sort());
});

test('admin-only methods are refused for a review-scoped account', async ({ request }) => {
  // Asserted with complete arguments on purpose: called with arguments
  // missing, these answer "Missing argument ..." first, which looks like the
  // call was accepted and made the audit ambiguous.
  const acl = await rpc(request, tokens.kail, 'plugin.acl.addAcl', {
    scope: '*',
    user: 'kail',
    level: 255,
  });
  expect(acl.error).toBeTruthy();
  expect(acl.result).toBeFalsy();

  const user = await rpc(request, tokens.kail, 'plugin.usermanager.createUser', {
    user: `intruder${Date.now()}`,
    password: 'secret123',
    name: 'Intruder',
    mail: 'intruder@example.test',
    groups: ['admin'],
  });
  expect(user.error).toBeTruthy();
  expect(user.result).toBeFalsy();

  // kail's own permissions are unchanged: a save is still queued, not applied.
  const save = await rpc(request, tokens.kail, 'core.savePage', {
    page: `afteracl${Date.now()}`,
    text: 'still queued',
    summary: 's',
  });
  expect(save.error.message).toMatch(/submitted for review/);
});

test('savePage, appendPage and saveMedia are all queued, never applied', async ({ request }) => {
  const pageId = `lock${Date.now()}`;

  const save = await rpc(request, tokens.kail, 'core.savePage', {
    page: pageId,
    text: 'via savePage',
    summary: 's',
  });
  expect(save.error.message).toMatch(/submitted for review/);

  const append = await rpc(request, tokens.kail, 'core.appendPage', {
    page: pageId,
    text: 'via appendPage',
    summary: 's',
  });
  expect(append.error.message).toMatch(/submitted for review/);

  const media = await rpc(request, tokens.kail, 'core.saveMedia', {
    media: `${pageId}.png`,
    base64: PNG.toString('base64'),
  });
  expect(media.error.message).toMatch(/submitted for review/);

  // Nothing of it is live.
  const page = await request.get(`/doku.php?id=${pageId}`);
  const text = await page.text();
  expect(text).not.toContain('via savePage');
  expect(text).not.toContain('via appendPage');
  expect((await request.get(`/lib/exe/fetch.php?media=${pageId}.png`)).status()).toBe(404);
});

test('range write tools are queued, never applied, exactly like savePage', async ({ request }) => {
  const pageId = `lockrange${Date.now()}`;

  // martin publishes a page directly so there is something to edit - range
  // write tools operate on existing effective text, not page creation.
  const setup = await rpc(request, tokens.martin, 'core.savePage', {
    page: pageId,
    text: '====== Range Lockdown Test ======\n\noriginal\n',
    summary: 's',
  });
  expect(setup.result).toBe(true);

  const replace = await rpc(request, tokens.kail, 'plugin.reviewqueue.replaceText', {
    page: pageId,
    search: 'original',
    replace: 'via replaceText',
  });
  expect(replace.error).toBeFalsy();
  expect(replace.result.status).toBe('queued');
  expect(replace.result.pendingId).toBeGreaterThan(0);

  // Nothing of it is live.
  const page = await request.get(`/doku.php?id=${pageId}`);
  const text = await page.text();
  expect(text).not.toContain('via replaceText');
  expect(text).toContain('original');

  // ...and it shows up as a change awaiting review, same as core.savePage.
  const pending = await rpc(request, tokens.kail, 'plugin.reviewqueue.listMyPending');
  expect(pending.result.map((r: any) => r.target)).toContain(pageId);
});

test('deleteMedia cannot remove a file directly', async ({ request }) => {
  // The gap this test was written for: media_delete() does not go through
  // MEDIA_UPLOAD_FINISH, so before it was intercepted separately a
  // review-scoped account could delete files outright while being unable to
  // add them.
  const mediaId = `deletable${Date.now()}.png`;
  const setup = await rpc(request, tokens.martin, 'core.saveMedia', {
    media: mediaId,
    base64: PNG.toString('base64'),
  });
  expect(setup.result).toBe(true);
  expect((await request.get(`/lib/exe/fetch.php?media=${mediaId}`)).status()).toBe(200);

  await rpc(request, tokens.kail, 'core.deleteMedia', { media: mediaId });

  // Still there: the deletion was held back, not carried out.
  expect((await request.get(`/lib/exe/fetch.php?media=${mediaId}`)).status()).toBe(200);

  // ...and it shows up as a change awaiting review.
  const pending = await rpc(request, tokens.kail, 'plugin.reviewqueue.listMyPending');
  expect(pending.result.map((r: any) => r.target)).toContain(mediaId);
});

test('privileged legacy methods stay refused', async ({ request }) => {
  // These are admin-gated in core; assert it rather than assume it.
  const created = await rpc(request, tokens.kail, 'dokuwiki.createUser', {
    userStruct: { user: `intruder${Date.now()}`, password: 'x', name: 'x', mail: 'x@example.test' },
  });
  expect(created.error).toBeTruthy();
  expect(created.result).toBeFalsy();

  const deleted = await rpc(request, tokens.kail, 'dokuwiki.deleteUsers', { users: ['martin'] });
  expect(deleted.error ?? deleted.result === false).toBeTruthy();

  // martin still exists and can still write.
  const check = await rpc(request, tokens.martin, 'core.whoAmI');
  expect(check.result.login).toBe('martin');
});

test('martin is unaffected by any of this', async ({ request }) => {
  const pageId = `unaffected${Date.now()}`;
  const mediaId = `${pageId}.png`;

  expect((await rpc(request, tokens.martin, 'core.savePage', {
    page: pageId, text: 'martin writes', summary: 's',
  })).result).toBe(true);

  expect((await rpc(request, tokens.martin, 'core.saveMedia', {
    media: mediaId, base64: PNG.toString('base64'),
  })).result).toBe(true);

  expect((await rpc(request, tokens.martin, 'core.deleteMedia', { media: mediaId })).result).toBe(
    true
  );
  expect((await request.get(`/lib/exe/fetch.php?media=${mediaId}`)).status()).toBe(404);
});
