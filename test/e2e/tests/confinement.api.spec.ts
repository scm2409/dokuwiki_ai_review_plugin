import { test, expect } from '@playwright/test';
import { MCP_ENDPOINT, cookieHeaderFor, mcpToolsList, rpc, tokens } from './_helpers';

// ADR-0007: a review-scoped account is confined at three gates - entry script,
// do= action, and MCP tool - all of which read helper/capability.php.
//
// The point of these is the *negative* space: not that the agent can work, but
// that the routes around the review queue are closed on every transport at
// once. Covers strategy.md scenarios 24-27.

const auth = (token: string) => ({ Authorization: `Bearer ${token}` });

test.describe('entry script allowlist', () => {
  const REFUSED = [
    ['/lib/exe/jsonrpc.php', 'the whole remote API, no per-method gate'],
    ['/lib/exe/xmlrpc.php', 'same surface over XML-RPC'],
    ['/feed.php', 'RSS of recent changes'],
    ['/lib/exe/openapi.php', 'discloses the full API surface'],
  ];

  for (const [path, why] of REFUSED) {
    test(`kail is refused on ${path} (${why})`, async ({ request }) => {
      const res = await request.get(path, { headers: auth(tokens.kail) });
      expect(res.status()).toBe(403);
    });
  }

  // opensearch.php belongs here rather than above: it defines NOSESSION, so
  // auth_setup() never runs and there is no authenticated user for the gate to
  // confine. Those five NOSESSION scripts (css, js, jquery, manifest,
  // opensearch) are exactly the ones carrying no wiki content, so nothing is
  // lost - every content-bearing script authenticates and is covered.
  const ALLOWED = [
    '/doku.php?id=start',
    '/lib/exe/css.php',
    '/lib/exe/opensearch.php',
    MCP_ENDPOINT,
  ];

  for (const path of ALLOWED) {
    test(`kail still reaches ${path}`, async ({ request }) => {
      const res = await request.get(path, { headers: auth(tokens.kail) });
      // The MCP endpoint only answers POST; what matters is that the gate did
      // not turn it away.
      expect(res.status()).not.toBe(403);
    });
  }

  test('martin is unaffected by the entry script gate', async ({ request }) => {
    for (const [path] of REFUSED) {
      const res = await request.get(path, { headers: auth(tokens.martin) });
      expect(res.status(), `${path} must stay open for martin`).not.toBe(403);
    }
  });
});

test.describe('no historical revisions on any path', () => {
  // The subtle one. Dropping do=revisions from the act allowlist would leave
  // ?rev= wide open, because that request's act is still 'show' - so this is
  // checked at DOKUWIKI_INIT_DONE, before doku.php:45, fetch.php:32 and
  // detail.php:15 ever read the parameter.
  let pageId: string;
  let oldRev: number;
  const SECRET = 'revision-only-marker';

  test.beforeAll(async ({ request }) => {
    pageId = `revguard${Date.now()}`;
    const save = (text: string, summary: string) =>
      request.post('/lib/exe/jsonrpc.php', {
        headers: { ...auth(tokens.martin), 'Content-Type': 'application/json' },
        data: {
          jsonrpc: '2.0',
          method: 'core.savePage',
          params: { page: pageId, text, summary },
          id: 1,
        },
      });

    await save(SECRET, 'v1');
    await new Promise((r) => setTimeout(r, 1100)); // distinct revision timestamps
    await save('current harmless text', 'v2');

    const hist = await request.post('/lib/exe/jsonrpc.php', {
      headers: { ...auth(tokens.martin), 'Content-Type': 'application/json' },
      data: {
        jsonrpc: '2.0',
        method: 'core.getPageHistory',
        params: { page: pageId },
        id: 1,
      },
    });
    const revisions = (await hist.json()).result as any[];
    oldRev = revisions[revisions.length - 1].revision;
    expect(oldRev).toBeGreaterThan(0);
  });

  test('martin can read the old revision (so the fixture is real)', async ({ request }) => {
    const res = await request.get(`/doku.php?id=${pageId}&rev=${oldRev}`, {
      headers: auth(tokens.martin),
    });
    expect(await res.text()).toContain(SECRET);
  });

  test('kail cannot, by any of the routes that read rev/at', async ({ request }) => {
    const routes = [
      `/doku.php?id=${pageId}&rev=${oldRev}`,
      `/doku.php?id=${pageId}&at=2020-01-01`,
      `/doku.php?id=${pageId}&do=diff&rev=${oldRev}`,
      `/doku.php?id=${pageId}&do=export_raw&rev=${oldRev}`,
      `/lib/exe/fetch.php?media=${pageId}&rev=${oldRev}`,
      `/lib/exe/detail.php?media=${pageId}&rev=${oldRev}`,
    ];

    for (const route of routes) {
      const res = await request.get(route, { headers: auth(tokens.kail) });
      expect(res.status(), `${route} must be refused`).toBe(403);
      expect(await res.text(), `${route} must not leak`).not.toContain(SECRET);
    }
  });

  test('rev=0 is the current revision, not history, and stays allowed', async ({ request }) => {
    const res = await request.get(`/doku.php?id=${pageId}&rev=0`, { headers: auth(tokens.kail) });
    expect(res.status()).toBe(200);
    expect(await res.text()).not.toContain(SECRET);
  });
});

test.describe('act and ajax allowlists', () => {
  for (const act of ['revisions', 'diff', 'recent', 'mediadetail', 'subscribe', 'profile']) {
    test(`do=${act} is refused for kail`, async ({ request }) => {
      const res = await request.get(`/doku.php?id=start&do=${act}`, { headers: auth(tokens.kail) });
      // doku.php itself stays reachable; the act is swapped for 'show' with a
      // notice, so the refusal shows up in the body rather than the status.
      expect(await res.text()).toContain('not available to your account');
    });
  }

  for (const act of ['show', 'search', 'index', 'edit']) {
    test(`do=${act} still works for kail`, async ({ request }) => {
      const res = await request.get(`/doku.php?id=start&do=${act}`, { headers: auth(tokens.kail) });
      expect(await res.text()).not.toContain('not available to your account');
    });
  }

  test('media history ajax calls are refused, ordinary ones are not', async ({ request }) => {
    for (const call of ['mediadiff', 'mediadetails']) {
      const res = await request.get(`/lib/exe/ajax.php?call=${call}`, {
        headers: auth(tokens.kail),
      });
      expect(res.status(), `call=${call} must be refused`).toBe(403);
    }

    const ok = await request.get('/lib/exe/ajax.php?call=qsearch&q=start', {
      headers: auth(tokens.kail),
    });
    expect(ok.status()).toBe(200);
  });
});

test.describe('the media manager is closed, and why', () => {
  // Both of these were live bypasses found by /code-review on this branch.
  // The media manager reaches two routes no other gate catches, which is why
  // lib/exe/mediamanager.php and do=media are off the allowlists entirely
  // rather than filtered by mediado=/tab_details= value.
  const PNG_JPEG =
    '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwc' +
    'KDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAA' +
    'AAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

  let mediaId: string;

  test.beforeAll(async ({ request }) => {
    mediaId = `metaguard${Date.now()}:photo.jpg`;
    const res = await rpc(request, tokens.martin, 'core.saveMedia', {
      media: mediaId,
      base64: PNG_JPEG,
    });
    expect(res.result).toBe(true);
  });

  test('kail cannot reach the media manager at all', async ({ request }) => {
    for (const path of [
      `/lib/exe/mediamanager.php?image=${mediaId}`,
      `/doku.php?do=media&image=${mediaId}`,
    ]) {
      const res = await request.get(path, { headers: auth(tokens.kail) });
      const body = await res.text();
      // mediamanager.php is refused outright; do=media is swapped for 'show'.
      expect(
        res.status() === 403 || body.includes('not available to your account'),
        `${path} must be refused`
      ).toBe(true);
    }
  });

  test('mediado=save cannot write IPTC metadata into the live file', async ({ request }) => {
    // core's media_metasave() fires neither MEDIA_UPLOAD_FINISH nor
    // MEDIA_DELETE_FILE, so action/media.php never sees it: reachable, this
    // publishes an unreviewed change to a live file and pushes an attic
    // revision. The plugin's core guarantee, on a media path this phase opened.
    const cookie = cookieHeaderFor('kail');
    const marker = `PWNED${Date.now()}`;

    const res = await request.post('/lib/exe/mediamanager.php', {
      headers: { Cookie: cookie, 'Content-Type': 'application/x-www-form-urlencoded' },
      form: {
        sectok: '',
        img: mediaId,
        mediado: 'save',
        'meta[Iptc.Headline]': marker,
      },
    });
    expect(res.status()).toBe(403);

    // And the live file is untouched, checked independently of the status code.
    const file = await request.get(`/lib/exe/fetch.php?media=${mediaId}`, {
      headers: auth(tokens.martin),
    });
    expect(Buffer.from(await file.body()).toString('latin1')).not.toContain(marker);
  });

  test('the media history tab does not disclose revisions', async ({ request }) => {
    // tab_details=history renders the media revision list with no rev/at
    // parameter, so requestsRevision() cannot catch it - the entry script and
    // act allowlists are what close it.
    for (const path of [
      `/lib/exe/mediamanager.php?image=${mediaId}&tab_details=history`,
      `/doku.php?do=media&image=${mediaId}&tab_details=history`,
    ]) {
      const res = await request.get(path, { headers: auth(tokens.kail) });
      const body = await res.text();
      expect(body, `${path} must not disclose a revision timestamp`).not.toMatch(/rev=1\d{9}/);
    }
  });

  test('kail still reads and writes media through the tools it does have', async ({ request }) => {
    const read = await rpc(request, tokens.kail, 'core.getMediaInfo', { media: mediaId });
    expect(read.result).toBeTruthy();

    const write = await rpc(request, tokens.kail, 'core.saveMedia', {
      media: `metaguardown${Date.now()}.jpg`,
      base64: PNG_JPEG,
    });
    expect(write.error!.message).toMatch(/review/i);
  });
});

test.describe('tool schema invariants', () => {
  // Structural properties of the generator's output, asserted over whatever it
  // currently emits rather than against a list of known-bad tool names, so
  // tools added later are covered without touching this test.
  //
  // The array/items rule is not cosmetic: Google's Gemini API rejects the
  // entire request when any function declaration has an array parameter with
  // no items, so one bad schema takes all the tools down with it. Anthropic
  // and OpenAI do not validate it, which is how such a schema survives unseen.
  function violations(node: any, path: string): string[] {
    if (!node || typeof node !== 'object') return [];
    const found: string[] = [];

    if (node.type === 'array' && node.items === undefined) {
      found.push(`${path}: type "array" without "items"`);
    }
    if (node.properties && typeof node.properties === 'object') {
      for (const [name, sub] of Object.entries(node.properties)) {
        found.push(...violations(sub, `${path}.${name}`));
      }
    }
    if (node.items) found.push(...violations(node.items, `${path}[]`));
    if (node.additionalProperties && typeof node.additionalProperties === 'object') {
      found.push(...violations(node.additionalProperties, `${path}{}`));
    }
    return found;
  }

  test('no schema node is an array without items', async ({ request }) => {
    const tools = await mcpToolsList(request, tokens.kail);
    const found = tools.flatMap((t) => violations(t.inputSchema, t.name));
    expect(found).toEqual([]);
  });

  test('every tool has a non-empty description and title', async ({ request }) => {
    const tools = await mcpToolsList(request, tokens.kail);

    const undescribed = tools.filter((t) => !t.description || !t.description.trim());
    expect(undescribed.map((t) => t.name)).toEqual([]);

    const untitled = tools.filter((t) => !t.annotations?.title?.trim());
    expect(untitled.map((t) => t.name)).toEqual([]);
  });

  test('every tool advertises an object input schema', async ({ request }) => {
    const tools = await mcpToolsList(request, tokens.kail);
    for (const tool of tools) {
      expect(tool.inputSchema.type, `${tool.name} input schema`).toBe('object');
    }
  });
});
