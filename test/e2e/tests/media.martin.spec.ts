import { test, expect } from '@playwright/test';
import * as crypto from 'crypto';
import * as fs from 'fs';
import * as path from 'path';
import { tokens, rpc } from './_helpers';

// strategy.md scenarios 4 (kail's upload is queued, published on approval)
// and 8 (martin's upload is unaffected).

// A real PNG, including bytes that cleanText() would mangle if the queue ever
// routed binaries through the text path (CR, LF, NUL are all in here).
const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64'
);

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

// Deletions, the other half of the media path. Before this was tested,
// core.deleteMedia had been listed as "queued" in the write-path audit for two
// phases without anything ever calling it - which is how both of the bugs
// these two tests pin down survived: the caller was told the deletion had
// failed when it had in fact been queued, and approving the deletion of the
// last file in a namespace fatally errored on a constant Kaos does not define.

test('kail deletion is queued, the file stays live, and the caller is told so', async ({
  page,
  request,
}) => {
  const mediaId = `deleteme${Date.now()}.png`;

  // Put a file there that is not subject to review, so the deletion is the
  // only thing under test.
  await rpc(request, tokens.martin, 'core.saveMedia', {
    media: mediaId,
    base64: PNG.toString('base64'),
  });

  const res = await rpc(request, tokens.kail, 'core.deleteMedia', { media: mediaId });
  // The throw is the success signal (ADR-0003) - what matters is that it names
  // the queue rather than reporting a failure the agent would retry.
  expect(res.error).toBeTruthy();
  expect(res.error.message).toMatch(/submitted for review as change #\d+/);
  const rqid = Number(/change #(\d+)/.exec(res.error.message)![1]);

  // Still live: nothing was deleted.
  expect((await request.get(`/lib/exe/fetch.php?media=${mediaId}`)).status()).toBe(200);

  await page.goto('/doku.php?do=admin&page=reviewqueue');
  const item = page.locator(`.reviewqueue-item[data-rqid="${rqid}"]`);
  await expect(item).toContainText(mediaId);
  await expect(item).toContainText('DELETING');

  await item.locator('button[value="approve"]').click();
  await expect(page.locator('#dokuwiki__content')).toContainText(`Change #${rqid} approved`);

  expect((await request.get(`/lib/exe/fetch.php?media=${mediaId}`)).status()).toBe(404);
});

test('approving the deletion of the last file in a namespace completes the change', async ({
  page,
  request,
}) => {
  // media_delete() returns DOKU_MEDIA_DELETED | DOKU_MEDIA_EMPTY_NS here,
  // because removing this file empties its namespace - a success the approval
  // path used to read as a failure.
  const mediaId = `lonely${Date.now()}:only.png`;

  await rpc(request, tokens.martin, 'core.saveMedia', {
    media: mediaId,
    base64: PNG.toString('base64'),
  });

  const res = await rpc(request, tokens.kail, 'core.deleteMedia', { media: mediaId });
  const rqid = Number(/change #(\d+)/.exec(res.error.message)![1]);

  await page.goto('/doku.php?do=admin&page=reviewqueue');
  await page.locator(`.reviewqueue-item[data-rqid="${rqid}"] button[value="approve"]`).click();
  await expect(page.locator('#dokuwiki__content')).toContainText(`Change #${rqid} approved`);

  expect((await request.get(`/lib/exe/fetch.php?media=${mediaId}`)).status()).toBe(404);

  // And the change is actually done with, not left pending for a reviewer to
  // trip over a second time.
  await page.goto('/doku.php?do=admin&page=reviewqueue');
  await expect(page.locator(`.reviewqueue-item[data-rqid="${rqid}"]`)).toHaveCount(0);
});
