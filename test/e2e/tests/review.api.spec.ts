import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const tokens = JSON.parse(
  fs.readFileSync(path.join(__dirname, '..', '.auth', 'tokens.json'), 'utf-8')
) as Record<string, string>;

// Covers strategy.md scenarios 1/2 (queued) and 7/9 (martin unaffected) via
// JSON-RPC, plus the MCP equivalent of the same interception.

test('JSON-RPC: kail savePage is rejected with a review id, page stays unpublished', async ({
  request,
}) => {
  const pageId = `apicreate${Date.now()}`;
  const res = await request.post('/lib/exe/jsonrpc.php', {
    headers: { Authorization: `Bearer ${tokens.kail}`, 'Content-Type': 'application/json' },
    data: {
      jsonrpc: '2.0',
      method: 'core.savePage',
      params: { page: pageId, text: 'kail api content', summary: 'api test' },
      id: 1,
    },
  });
  const body = await res.json();
  expect(body.error).toBeTruthy();
  expect(body.error.message).toMatch(/submitted for review as change #\d+/);

  const page = await request.get(`/doku.php?id=${pageId}`);
  expect(await page.text()).not.toContain('kail api content');
});

test('JSON-RPC: martin savePage goes straight through', async ({ request }) => {
  const pageId = `apimartintest${Date.now()}`;
  const res = await request.post('/lib/exe/jsonrpc.php', {
    headers: { Authorization: `Bearer ${tokens.martin}`, 'Content-Type': 'application/json' },
    data: {
      jsonrpc: '2.0',
      method: 'core.savePage',
      params: { page: pageId, text: 'martin api content', summary: 'api test' },
      id: 1,
    },
  });
  const body = await res.json();
  expect(body.result).toBe(true);

  const page = await request.get(`/doku.php?id=${pageId}`);
  expect(await page.text()).toContain('martin api content');
});

test('MCP: kail tools/call core_savePage returns isError with a review id', async ({
  request,
}) => {
  const pageId = `mcpcreate${Date.now()}`;
  const res = await request.post('/lib/plugins/mcp/mcp.php', {
    headers: { Authorization: `Bearer ${tokens.kail}`, 'Content-Type': 'application/json' },
    data: {
      jsonrpc: '2.0',
      method: 'tools/call',
      params: { name: 'core_savePage', arguments: { page: pageId, text: 'x', summary: 'x' } },
      id: 1,
    },
  });
  const body = await res.json();
  expect(body.result.isError).toBe(true);
  expect(body.result.content[0].text).toMatch(/submitted for review as change #\d+/);
});
