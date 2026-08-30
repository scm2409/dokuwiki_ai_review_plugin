import { test, expect } from '@playwright/test';
import { execFileSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';

const tokens = JSON.parse(
  fs.readFileSync(path.join(__dirname, '..', '.auth', 'tokens.json'), 'utf-8')
) as Record<string, string>;

const CONTAINER = 'reviewqueue-test-dokuwiki';

// strategy.md scenarios 9 (config is the only thing that scopes review),
// 12/13 (MCP tool surface) and 17 (fail-closed). These need to manipulate the
// container's config and filesystem, so they shell out to podman rather than
// going through HTTP only. Each test restores what it changed.

function inContainer(...args: string[]) {
  return execFileSync('podman', ['exec', CONTAINER, ...args], { encoding: 'utf-8' });
}

function rpc(request: any, token: string, method: string, params: any = []) {
  return request
    .post('/lib/exe/jsonrpc.php', {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { jsonrpc: '2.0', method, params, id: 1 },
    })
    .then((r: any) => r.json());
}

test('MCP exposes the reviewqueue tools alongside the core ones', async ({ request }) => {
  const res = await request.post('/lib/plugins/mcp/mcp.php', {
    headers: { Authorization: `Bearer ${tokens.kail}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', method: 'tools/list', params: {}, id: 1 },
  });
  const tools = (await res.json()).result.tools as any[];
  const names = tools.map((t) => t.name);

  expect(names).toContain('plugin_reviewqueue_getPageToEdit');
  expect(names).toContain('plugin_reviewqueue_listMyPending');
  expect(names).toContain('plugin_reviewqueue_getStatus');
  expect(names).toContain('plugin_reviewqueue_getPendingText');
  expect(names).toContain('core_savePage');

  // The descriptions are what steer an agent, so they must not be the
  // garbled docblock fragments DokuWiki's parser produces from multi-line
  // @param/@return tags (see ADR-0004).
  const getPageToEdit = tools.find((t) => t.name === 'plugin_reviewqueue_getPageToEdit');
  expect(getPageToEdit.description).toContain('instead of core.getPage');
  expect(getPageToEdit.annotations.title).not.toMatch(/^[a-z]|\)$/);
});

test('martin editing through MCP is published immediately', async ({ request }) => {
  const pageId = `mcpmartin${Date.now()}`;
  const res = await request.post('/lib/plugins/mcp/mcp.php', {
    headers: { Authorization: `Bearer ${tokens.martin}`, 'Content-Type': 'application/json' },
    data: {
      jsonrpc: '2.0',
      method: 'tools/call',
      params: {
        name: 'core_savePage',
        arguments: { page: pageId, text: 'martin via mcp', summary: 'mcp' },
      },
      id: 1,
    },
  });
  const body = await res.json();
  expect(body.result.isError).toBeFalsy();

  const live = await request.get(`/doku.php?id=${pageId}`);
  expect(await live.text()).toContain('martin via mcp');
});

test('with review_users cleared, kail writes directly like anyone else', async ({ request }) => {
  // Proves the review scoping lives entirely in configuration - there is no
  // hidden special-casing of the name "kail" anywhere in the plugin.
  const conf = '/var/www/html/conf/local.php';
  inContainer('cp', conf, `${conf}.bak`);
  try {
    // Append an overriding assignment rather than rewriting the existing
    // line - PHP takes the last one, and this can't be defeated by
    // whitespace or quoting differences in the seeded config.
    inContainer('sh', '-c', `echo "\\$conf['plugin']['reviewqueue']['review_users'] = '';" >> ${conf}`);
    expect(inContainer('tail', '-1', conf)).toContain("['review_users'] = ''");

    const pageId = `noscope${Date.now()}`;
    const res = await rpc(request, tokens.kail, 'core.savePage', {
      page: pageId,
      text: 'kail writes directly now',
      summary: 'unscoped',
    });
    expect(res.error).toBeUndefined();
    expect(res.result).toBe(true);

    const live = await request.get(`/doku.php?id=${pageId}`);
    expect(await live.text()).toContain('kail writes directly now');
  } finally {
    inContainer('mv', `${conf}.bak`, conf);
  }

  // ...and review is back on afterwards.
  const back = await rpc(request, tokens.kail, 'core.savePage', {
    page: `rescope${Date.now()}`,
    text: 'queued again',
    summary: 's',
  });
  expect(back.error.message).toMatch(/submitted for review/);
});

test('fail-closed: an unwritable queue rejects the save instead of publishing it', async ({
  request,
}) => {
  // The project's stated guiding principle: if the queue cannot be written,
  // the save must fail, never fall through to a normal publish.
  const pageId = `failclosed${Date.now()}`;
  const queueDir = '/var/www/html/data/reviewqueue';

  inContainer('mkdir', '-p', `${queueDir}/queue`);
  inContainer('chmod', '0500', `${queueDir}/queue`);
  try {
    const res = await rpc(request, tokens.kail, 'core.savePage', {
      page: pageId,
      text: 'must not be published',
      summary: 'fail-closed',
    });

    expect(res.error).toBeTruthy();
    expect(res.result).toBeUndefined();
    expect(res.error.message).toMatch(/could not be written|not saved/i);

    const live = await request.get(`/doku.php?id=${pageId}`);
    expect(await live.text()).not.toContain('must not be published');
  } finally {
    inContainer('chmod', '0755', `${queueDir}/queue`);
  }

  // The queue works again once the directory is writable.
  const after = await rpc(request, tokens.kail, 'core.savePage', {
    page: `recovered${Date.now()}`,
    text: 'queued normally',
    summary: 's',
  });
  expect(after.error.message).toMatch(/submitted for review/);
});

test('fail-closed: an unwritable pending change survives a failed updateContent()', async ({
  request,
}) => {
  // Same guiding principle as the enqueue() case above, applied to Phase
  // 10's in-place continuation (docs/design/adr-0006): a failed write must
  // never leave the change silently unchanged-but-reported-as-updated, and
  // must never corrupt what was there before.
  const pageId = `failupdate${Date.now()}`;
  const queueDir = '/var/www/html/data/reviewqueue/queue';

  const queued = await rpc(request, tokens.kail, 'core.savePage', {
    page: pageId,
    text: 'original content',
    summary: 'first',
  });
  const rqid = Number(/change #(\d+)/.exec(queued.error.message)![1]);

  // Read-only on the file itself (not just the directory): updateContent()
  // rewrites an *existing* file, which only needs directory write
  // permission to create - the file's own permissions are what has to
  // block this.
  inContainer('chmod', '0400', `${queueDir}/${rqid}.content`);
  try {
    const update = await rpc(request, tokens.kail, 'plugin.reviewqueue.updatePendingChange', {
      id: rqid,
      text: 'attempted update',
    });
    expect(update.error).toBeTruthy();
    expect(update.error.message).toMatch(/could not be written|not saved/i);

    const text = await rpc(request, tokens.kail, 'plugin.reviewqueue.getPendingText', { id: rqid });
    expect(text.result).toBe('original content');
  } finally {
    inContainer('chmod', '0644', `${queueDir}/${rqid}.content`);
  }

  // Works again once the file is writable.
  const after = await rpc(request, tokens.kail, 'plugin.reviewqueue.updatePendingChange', {
    id: rqid,
    text: 'now updates fine',
  });
  expect(after.error).toBeUndefined();
  expect(after.result.status).toBe('updated');
});
