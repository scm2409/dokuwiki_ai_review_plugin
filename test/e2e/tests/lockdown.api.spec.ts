import { test, expect } from '@playwright/test';
import { tokens, rpc, mcpCall, mcpToolsList, allRemoteMethods } from './_helpers';

// The guarantee this whole plugin exists for: a review-scoped account must not
// be able to change anything in the wiki without a human approving it, and
// since ADR-0007 must not be able to read anything it has no business reading
// either.
//
// Before ADR-0007 this walked the tool list the mcp plugin advertised. That is
// no longer the right source: our endpoint deliberately shows a small subset,
// so a mutating method added by a DokuWiki upgrade or a newly installed plugin
// would simply not appear and the audit would pass by not looking. It now
// walks the *whole* remote API from core's own OpenAPI spec and checks every
// method against the allowlist - broader than before, and stricter for the
// methods that are actually reachable.

/**
 * Every method the allowlist exposes, and why it cannot publish unreviewed
 * content. A tool on the allowlist that is missing here fails the audit, so
 * widening the allowlist forces a deliberate decision instead of passing
 * silently.
 */
const ALLOWED: Record<string, 'read-only' | 'queued' | 'own-queue'> = {
  core_whoAmI: 'read-only',
  core_listPages: 'read-only',
  core_searchPages: 'read-only',
  core_listMedia: 'read-only',
  core_getMedia: 'read-only',
  core_getMediaInfo: 'read-only',
  // Media writes go through MEDIA_UPLOAD_FINISH / MEDIA_DELETE_FILE, which the
  // plugin intercepts exactly like a page save.
  core_saveMedia: 'queued',
  core_deleteMedia: 'queued',
  plugin_reviewqueue_getPageToEdit: 'read-only',
  plugin_reviewqueue_listMyPending: 'read-only',
  plugin_reviewqueue_searchMyPending: 'read-only',
  plugin_reviewqueue_getStatus: 'read-only',
  plugin_reviewqueue_getPendingText: 'read-only',
  plugin_reviewqueue_getPageOutline: 'read-only',
  plugin_reviewqueue_getSection: 'read-only',
  plugin_reviewqueue_getLines: 'read-only',
  plugin_reviewqueue_findInPage: 'read-only',
  plugin_reviewqueue_searchWithContext: 'read-only',
  // Creating and the range writes all end in ApiCore::savePage() via
  // remote.php::writeEffectiveText(), so they are queued exactly like a
  // core.savePage would have been.
  plugin_reviewqueue_createPage: 'queued',
  plugin_reviewqueue_deletePage: 'queued',
  plugin_reviewqueue_replaceSection: 'queued',
  plugin_reviewqueue_insertSection: 'queued',
  plugin_reviewqueue_deleteSection: 'queued',
  plugin_reviewqueue_replaceLines: 'queued',
  plugin_reviewqueue_replaceText: 'queued',
  // These write only to a queue entry the caller already owns and that has not
  // been reviewed, so they can never make anything live.
  plugin_reviewqueue_updatePendingChange: 'own-queue',
  plugin_reviewqueue_withdrawPendingChange: 'own-queue',
};

/** Reading these would defeat the point of confining the account at all. */
const MUST_NEVER_BE_REACHABLE = [
  'core.getPageHistory',
  'core.getRecentPageChanges',
  'core.getMediaHistory',
  'core.getRecentMediaChanges',
  'plugin.acl.addAcl',
  'plugin.acl.delAcl',
  'plugin.usermanager.createUser',
  'plugin.usermanager.deleteUser',
];

test('the advertised tool list is exactly the audited allowlist', async ({ request }) => {
  const names = (await mcpToolsList(request, tokens.kail)).map((t) => t.name).sort();
  expect(names).toEqual(Object.keys(ALLOWED).sort());
});

test('every remote method the wiki has is either audited or unreachable', async ({ request }) => {
  // The whole surface, not just what we advertise - so a method that arrives
  // with a DokuWiki upgrade or a newly installed plugin shows up here.
  const all = await allRemoteMethods(request);
  expect(all.length).toBeGreaterThan(50);

  const unaudited = all.map((m) => m.replace(/\./g, '_')).filter((name) => !(name in ALLOWED));
  expect(unaudited.length).toBeGreaterThan(0);

  // Everything not on the allowlist must be refused by name on tools/call, not
  // merely absent from tools/list - hiding a tool is not blocking it.
  for (const name of unaudited) {
    const res = await mcpCall(request, tokens.kail, name.replace(/_/g, '.'), {});
    expect(res.error, `${name} should not be callable`).toBeTruthy();
    expect(res.error!.message).toMatch(/no tool called/);
  }
});

test('history and admin methods are unreachable for a confined account', async ({ request }) => {
  for (const method of MUST_NEVER_BE_REACHABLE) {
    const res = await mcpCall(request, tokens.kail, method, {});
    expect(res.error, `${method} must not be callable`).toBeTruthy();
    expect(res.error!.message).toMatch(/no tool called/);
  }
});

test('nothing on the allowlist is a known-dangerous method', async () => {
  // A second, independent reading of the same list: even if someone adds an
  // entry to ALLOWED above to make the first test pass, these shapes must
  // never appear on it.
  const names = Object.keys(ALLOWED);
  for (const forbidden of ['History', 'Recent', 'acl', 'usermanager']) {
    expect(names.filter((n) => n.includes(forbidden))).toEqual([]);
  }
});

test('creating a page is queued, never applied', async ({ request }) => {
  const pageId = `lock${Date.now()}`;

  const created = await rpc(request, tokens.kail, 'plugin.reviewqueue.createPage', {
    page: pageId,
    text: 'via createPage',
    summary: 's',
  });
  expect(created.result.status).toBe('queued');

  const live = await request.get(`/doku.php?id=${pageId}`);
  expect(await live.text()).not.toContain('via createPage');
});

test('media writes are queued, never applied', async ({ request }) => {
  const PNG =
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
  const mediaId = `lockmedia${Date.now()}.png`;

  const res = await rpc(request, tokens.kail, 'core.saveMedia', { media: mediaId, base64: PNG });
  // A queued write reports back as a failure by ADR-0003's convention, because
  // core.saveMedia has no other channel to tell a caller it did not publish.
  expect(res.error).toBeTruthy();
  expect(res.error!.message).toMatch(/review/i);

  const fetched = await request.get(`/lib/exe/fetch.php?media=${mediaId}`, {
    headers: { Authorization: `Bearer ${tokens.martin}` },
  });
  expect(fetched.status()).toBe(404);
});
