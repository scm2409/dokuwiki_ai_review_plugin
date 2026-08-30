import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const tokens = JSON.parse(
  fs.readFileSync(path.join(__dirname, '..', '.auth', 'tokens.json'), 'utf-8')
) as Record<string, string>;

// docs/design/adr-0005 claims getPageOutline's "range" values are the exact
// same "from-to" byte format DokuWiki's own section-edit buttons use
// (rawWikiSlices()/con() in inc/common.php) - not a re-derived approximation.
// This needs a real, authenticated browser session (martin's), so it lives
// separately from range-read.api.spec.ts's unauthenticated api project.

function rpc(request: any, token: string, method: string, params: any = []) {
  return request
    .post('/lib/exe/jsonrpc.php', {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { jsonrpc: '2.0', method, params, id: 1 },
    })
    .then((r: any) => r.json());
}

test("a getSection range matches the browser's own section-edit link byte-for-byte", async ({
  page,
  request,
}) => {
  const pageId = `rangecorecompat${Date.now()}`;
  const text = [
    '====== Top ======',
    '',
    'Intro paragraph.',
    '',
    '===== Alpha =====',
    '',
    'Alpha body.',
    '',
    '==== Alpha Child ====',
    '',
    'Alpha child body.',
    '',
  ].join('\n');
  expect((await rpc(request, tokens.martin, 'core.savePage', { page: pageId, text, summary: 's' })).result).toBe(
    true
  );

  const outline = await rpc(request, tokens.martin, 'plugin.reviewqueue.getPageOutline', { page: pageId });
  const alpha = outline.result.sections.find((s: any) => s.title === 'Alpha');
  const ourText = await rpc(request, tokens.martin, 'plugin.reviewqueue.getSection', {
    page: pageId,
    section: alpha.range,
    children: false,
  });

  // Following the exact same range through DokuWiki's own section-edit
  // load path (Action/Edit.php -> rawWikiSlices($RANGE, ...)) must produce
  // byte-identical text to what getSection() returned for it.
  await page.goto(`/doku.php?id=${pageId}&do=edit&range=${alpha.range}`);
  const editorText = await page.locator('#wiki__text').inputValue();

  expect(editorText).toBe(ourText.result.text);
});
