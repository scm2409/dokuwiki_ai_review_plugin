import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { tokens, rpc, MCP_ENDPOINT } from './_helpers';

test('JSON-RPC: martin token identifies martin with the reviewer group', async ({ request }) => {
  const res = await rpc(request, tokens.martin, 'core.whoAmI', []);
  const body = res;
  expect(body.result.login).toBe('martin');
  expect(body.result.groups).toContain('reviewer');
});

test('JSON-RPC: kail token identifies kail without the reviewer group', async ({ request }) => {
  const res = await rpc(request, tokens.kail, 'core.whoAmI', []);
  const body = res;
  expect(body.result.login).toBe('kail');
  expect(body.result.groups).not.toContain('reviewer');
});

test('MCP: initialize handshake succeeds and identifies the caller', async ({ request }) => {
  // Our own endpoint since ADR-0007; splitbrain/dokuwiki-plugin-mcp is not
  // installed, because it would serve the unrestricted surface next to it.
  const res = await request.post(MCP_ENDPOINT, {
    headers: { Authorization: `Bearer ${tokens.martin}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'initialize', params: {}, id: 1 },
  });
  const body = await res.json();
  expect(body.result.serverInfo.name).toBe('DokuWiki Review Queue');
  expect(body.result.instructions).toContain("authenticated as 'martin'");
});
