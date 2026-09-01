import { test, expect } from '@playwright/test';
import { execFileSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { tokens, rpc } from './_helpers';

const CONTAINER = 'reviewqueue-test-dokuwiki';
const ACL = '/var/www/html/conf/acl.auth.php';

// The queue must not become a way to read around the wiki's own ACLs: a
// reviewer who may not read a page must not see its queued content either.
// The default test ACL grants everyone everything, so this needs a
// restrictive namespace to be meaningful.

function inContainer(...args: string[]) {
  return execFileSync('podman', ['exec', CONTAINER, ...args], { encoding: 'utf-8' });
}

test.describe('secret namespace martin cannot read', () => {
  test.beforeAll(() => {
    inContainer('cp', ACL, `${ACL}.bak`);
    // kail keeps write access; martin is denied outright.
    inContainer(
      'sh',
      '-c',
      `printf 'secret:*\\t@user\\t0\\nsecret:*\\tkail\\t8\\n' >> ${ACL}`
    );
  });

  test.afterAll(() => {
    inContainer('mv', `${ACL}.bak`, ACL);
  });

  test('a reviewer without read access neither sees nor can act on the change', async ({
    page,
    request,
  }) => {
    const pageId = `secret:doc${Date.now()}`;
    const marker = 'confidential draft body';

    const res = await rpc(request, tokens.kail, 'plugin.reviewqueue.createPage', {
      page: pageId,
      text: marker,
      summary: 'secret',
    });
    expect(res.result.status).toBe('queued');
    const rqid = res.result.pendingId as number;

    // Not listed in the admin queue, and its content is not on the page.
    await page.goto('/doku.php?do=admin&page=reviewqueue');
    await expect(page.locator(`.reviewqueue-item[data-rqid="${rqid}"]`)).toHaveCount(0);
    expect(await page.content()).not.toContain(marker);

    // Not readable through the remote API either.
    const peek = await rpc(request, tokens.martin, 'plugin.reviewqueue.getPendingText', {
      id: rqid,
    });
    expect(peek.error).toBeTruthy();
    expect(peek.result).toBeUndefined();

    // And approving it by posting directly must be refused, not merely hidden.
    await page.goto('/doku.php?do=admin&page=reviewqueue');
    const sectok = await page.locator('input[name="sectok"]').first().inputValue();
    await request.post('/doku.php', {
      form: { do: 'admin', page: 'reviewqueue', rqid: String(rqid), rqaction: 'approve', sectok },
    });

    const stillQueued = await rpc(request, tokens.kail, 'plugin.reviewqueue.getStatus', {
      id: rqid,
    });
    expect(stillQueued.result.state).toBe('pending');
  });

  test('the author can still see their own change in a namespace others cannot read', async ({
    request,
  }) => {
    const pageId = `secret:mine${Date.now()}`;
    const res = await rpc(request, tokens.kail, 'plugin.reviewqueue.createPage', {
      page: pageId,
      text: 'my own secret draft',
      summary: 's',
    });
    const rqid = res.result.pendingId as number;

    const own = await rpc(request, tokens.kail, 'plugin.reviewqueue.getPendingText', { id: rqid });
    expect(own.result).toBe('my own secret draft');
  });
});
