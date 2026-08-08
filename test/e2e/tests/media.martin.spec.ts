import { test, expect } from '@playwright/test';
import * as crypto from 'crypto';
import * as fs from 'fs';
import * as path from 'path';

const tokens = JSON.parse(
  fs.readFileSync(path.join(__dirname, '..', '.auth', 'tokens.json'), 'utf-8')
) as Record<string, string>;

// strategy.md scenarios 4 (kail's upload is queued, published on approval)
// and 8 (martin's upload is unaffected).

// A real PNG, including bytes that cleanText() would mangle if the queue ever
// routed binaries through the text path (CR, LF, NUL are all in here).
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

test('kail upload is queued, not published, and survives approval byte-for-byte', async ({
  page,
  request,
}) => {
  const mediaId = `queued${Date.now()}.png`;

  const res = await rpc(request, tokens.kail, 'core.saveMedia', {
    media: mediaId,
    base64: PNG.toString('base64'),
  });
  expect(res.error).toBeTruthy();
  expect(res.error.message).toMatch(/submitted for review as change #\d+/);
  const rqid = Number(/change #(\d+)/.exec(res.error.message)![1]);

  // Not downloadable while it is only queued.
  const before = await request.get(`/lib/exe/fetch.php?media=${mediaId}`);
  expect(before.status()).toBe(404);

  // The reviewer sees it described rather than diffed.
  await page.goto('/doku.php?do=admin&page=reviewqueue');
  const item = page.locator(`.reviewqueue-item[data-rqid="${rqid}"]`);
  await expect(item).toContainText(mediaId);
  await expect(item.locator('.reviewqueue-media')).toContainText('image/png');

  await item.locator('button[value="approve"]').click();
  await expect(page.locator('#dokuwiki__content')).toContainText(`Change #${rqid} approved`);

  // Now downloadable, and identical to what was uploaded - this is the check
  // that the queue never ran the binary through cleanText().
  const after = await request.get(`/lib/exe/fetch.php?media=${mediaId}`);
  expect(after.status()).toBe(200);
  const served = Buffer.from(await after.body());
  expect(crypto.createHash('sha256').update(served).digest('hex')).toBe(
    crypto.createHash('sha256').update(PNG).digest('hex')
  );
});

test('kail upload can be rejected and never appears', async ({ page, request }) => {
  const mediaId = `rejected${Date.now()}.png`;

  const res = await rpc(request, tokens.kail, 'core.saveMedia', {
    media: mediaId,
    base64: PNG.toString('base64'),
  });
  const rqid = Number(/change #(\d+)/.exec(res.error.message)![1]);

  await page.goto('/doku.php?do=admin&page=reviewqueue');
  const item = page.locator(`.reviewqueue-item[data-rqid="${rqid}"]`);
  await item.locator('input[name="rqcomment"]').fill('wrong image');
  await item.locator('button[value="reject"]').click();
  await expect(page.locator('#dokuwiki__content')).toContainText(`Change #${rqid} rejected`);

  const after = await request.get(`/lib/exe/fetch.php?media=${mediaId}`);
  expect(after.status()).toBe(404);
});

test('martin upload is published immediately, no queue entry', async ({ request }) => {
  const mediaId = `direct${Date.now()}.png`;

  const res = await rpc(request, tokens.martin, 'core.saveMedia', {
    media: mediaId,
    base64: PNG.toString('base64'),
  });
  expect(res.error).toBeUndefined();
  expect(res.result).toBe(true);

  const fetched = await request.get(`/lib/exe/fetch.php?media=${mediaId}`);
  expect(fetched.status()).toBe(200);
});
