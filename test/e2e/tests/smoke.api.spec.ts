import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const tokens = JSON.parse(
  fs.readFileSync(path.join(__dirname, '..', '.auth', 'tokens.json'), 'utf-8')
) as Record<string, string>;

test('JSON-RPC: martin token identifies martin with the reviewer group', async ({ request }) => {
  const res = await request.post('/lib/exe/jsonrpc.php', {
    headers: { Authorization: `Bearer ${tokens.martin}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'core.whoAmI', params: [], id: 1 },
  });
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  expect(body.result.login).toBe('martin');
  expect(body.result.groups).toContain('reviewer');
});

test('JSON-RPC: kail token identifies kail without the reviewer group', async ({ request }) => {
  const res = await request.post('/lib/exe/jsonrpc.php', {
    headers: { Authorization: `Bearer ${tokens.kail}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'core.whoAmI', params: [], id: 1 },
  });
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  expect(body.result.login).toBe('kail');
  expect(body.result.groups).not.toContain('reviewer');
});

test('MCP: initialize handshake succeeds and identifies the caller', async ({ request }) => {
  const res = await request.post('/lib/plugins/mcp/mcp.php', {
    headers: { Authorization: `Bearer ${tokens.martin}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'initialize', params: {}, id: 1 },
  });
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  expect(body.result.serverInfo.name).toBe('DokuWiki MCP');
  expect(body.result.instructions).toContain("authenticated as 'martin'");
});
