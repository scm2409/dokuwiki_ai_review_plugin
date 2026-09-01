import { test, expect } from '@playwright/test';
import { tokens, rpc, JSONRPC_ENDPOINT } from './_helpers';

// Covers strategy.md scenarios 1/2 (queued) and 7/9 (martin unaffected).
//
// The transport half of this changed with ADR-0007: kail no longer reaches
// lib/exe/jsonrpc.php at all, so "the remote API holds the change back" is now
// tested where kail actually calls it - our own MCP endpoint - while the
// JSON-RPC path is tested for being refused outright.

test('kail is refused on core JSON-RPC entirely', async ({ request }) => {
  // The confinement ADR-0007 rests on: core's own endpoint has no per-method
  // gate, so a confined account must not reach it at all. Without this the
  // whole tool allowlist would be decorative.
  const res = await request.post(JSONRPC_ENDPOINT, {
    headers: { Authorization: `Bearer ${tokens.kail}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'core.whoAmI', params: [], id: 1 },
  });

  expect(res.status()).toBe(403);
  expect(await res.text()).not.toContain('kail');
});

test('MCP: kail createPage is queued, the page stays unpublished', async ({ request }) => {
  const pageId = `apicreate${Date.now()}`;
  const res = await rpc(request, tokens.kail, 'plugin.reviewqueue.createPage', {
    page: pageId,
    text: 'kail api content',
    summary: 'api test',
  });

  expect(res.result.status).toBe('queued');
  expect(res.result.pendingId).toBeGreaterThan(0);

  const page = await request.get(`/doku.php?id=${pageId}`);
  expect(await page.text()).not.toContain('kail api content');
});

test('MCP: kail cannot call core.savePage at all', async ({ request }) => {
  // The range write tools plus createPage replace it, so it is off the
  // allowlist - and refused on tools/call, not merely hidden from tools/list.
  const res = await rpc(request, tokens.kail, 'core.savePage', {
    page: `apibypass${Date.now()}`,
    text: 'should never be written',
    summary: 'x',
  });

  expect(res.error).toBeTruthy();
  expect(res.error!.message).toMatch(/no tool called core_savePage/);
});

test('JSON-RPC: martin savePage goes straight through', async ({ request }) => {
  const pageId = `apimartintest${Date.now()}`;
  const res = await rpc(request, tokens.martin, 'core.savePage', {
    page: pageId,
    text: 'martin api content',
    summary: 'api test',
  });
  expect(res.result).toBe(true);

  const page = await request.get(`/doku.php?id=${pageId}`);
  expect(await page.text()).toContain('martin api content');
});

test('createPage refuses empty text with its own message, not the deletion one', async ({
  request,
}) => {
  // writeEffectiveText()'s empty-text guard points at deletePage, which would
  // then answer "does not exist, so there is nothing to delete" - a dead end
  // for an agent following the message it was given.
  const res = await rpc(request, tokens.kail, 'plugin.reviewqueue.createPage', {
    page: `emptycreate${Date.now()}`,
    text: '   ',
    summary: 's',
  });

  expect(res.error).toBeTruthy();
  expect(res.error!.message).toMatch(/needs text/);
  expect(res.error!.message).not.toMatch(/deletePage/);
});
