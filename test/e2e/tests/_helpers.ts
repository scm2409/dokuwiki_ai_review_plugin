import * as fs from 'fs';
import * as path from 'path';

// Shared plumbing for the API specs. Not a spec file itself - Playwright's
// default testMatch only collects *.spec.ts, so this is never run as a test.

export const tokens = JSON.parse(
  fs.readFileSync(path.join(__dirname, '..', '.auth', 'tokens.json'), 'utf-8')
) as Record<string, string>;

/** Our own MCP endpoint. The only remote surface a confined account can reach. */
export const MCP_ENDPOINT = '/lib/plugins/reviewqueue/mcp.php';

/** Core's JSON-RPC endpoint. Refused for a confined account since ADR-0007. */
export const JSONRPC_ENDPOINT = '/lib/exe/jsonrpc.php';

export type RpcResult = { result?: any; error?: { code?: number; message?: string } };

/**
 * Call a remote method as the given user, over whichever transport that user
 * is actually allowed to use.
 *
 * Since ADR-0007 a review-scoped account is refused on lib/exe/jsonrpc.php
 * outright, so kail's calls have to go through our MCP endpoint as tools/call
 * while an unconfined account keeps using JSON-RPC directly. Routing on the
 * token here means the specs keep one call shape and stay readable - the
 * transport is not what most of them are about.
 *
 * The MCP result envelope is unwrapped back into the {result, error} shape
 * JSON-RPC uses, so assertions read the same either way. A tool that failed
 * comes back as an error with its message, which is where the RemoteException
 * text ("submitted for review as change #N") that ADR-0003 relies on lands.
 */
export async function rpc(
  request: any,
  token: string,
  method: string,
  params: any = []
): Promise<RpcResult> {
  if (token !== tokens.kail) {
    const res = await request.post(JSONRPC_ENDPOINT, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { jsonrpc: '2.0', method, params, id: 1 },
    });
    return res.json();
  }

  return mcpCall(request, token, method, params);
}

/**
 * Call a tool on our MCP endpoint, unwrapped into the {result, error} shape.
 *
 * @param method dotted remote method name, e.g. 'plugin.reviewqueue.getStatus'
 */
export async function mcpCall(
  request: any,
  token: string,
  method: string,
  params: any = {}
): Promise<RpcResult> {
  const res = await request.post(MCP_ENDPOINT, {
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    data: {
      jsonrpc: '2.0',
      id: 1,
      method: 'tools/call',
      params: { name: method.replace(/\./g, '_'), arguments: params },
    },
  });

  const body = await res.json();

  // A protocol-level refusal: unknown tool, not allowlisted, malformed request.
  if (body.error) return { error: body.error };

  const text = body.result?.content?.[0]?.text ?? '';

  // A tool that threw comes back as a tool result flagged isError, with the
  // exception message as its text.
  if (body.result?.isError) return { error: { message: text } };

  try {
    return { result: JSON.parse(text) };
  } catch {
    // Scalars come back as plain text rather than JSON.
    return { result: text };
  }
}

/** Raw tools/list against our endpoint, without unwrapping. */
export async function mcpToolsList(request: any, token: string): Promise<any[]> {
  const res = await request.post(MCP_ENDPOINT, {
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    data: { jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} },
  });
  return (await res.json()).result.tools;
}

/**
 * Every method the wiki's remote API offers, from core's own OpenAPI spec.
 *
 * The audit in lockdown.api.spec.ts needs the *whole* surface, not just what
 * some tool list advertises, so that a method added by a DokuWiki upgrade or a
 * newly installed plugin shows up even though our endpoint would never expose
 * it. Read as martin: the spec is public, but a confined account is refused on
 * lib/exe/openapi.php like every other entry script it has no business on.
 */
export async function allRemoteMethods(request: any): Promise<string[]> {
  const res = await request.get('/lib/exe/openapi.php?spec=1', {
    headers: { Authorization: `Bearer ${tokens.martin}` },
  });
  const spec = await res.json();
  return Object.keys(spec.paths ?? {})
    .map((p) => p.replace(/^\//, ''))
    .sort();
}

/**
 * Put a change into the queue as kail and return its queue id.
 *
 * Since ADR-0007 kail has no core.savePage, so this goes the way the agent
 * itself has to: createPage for a page that does not exist yet, and otherwise
 * a whole-page replaceLines addressed by what getLines reports - which is also
 * the round trip (read a range with its hash, write it back) the range write
 * tools are designed around.
 */
export async function queueAsKail(
  request: any,
  pageId: string,
  text: string,
  summary = 's'
): Promise<number> {
  const created = await rpc(request, tokens.kail, 'plugin.reviewqueue.createPage', {
    page: pageId,
    text,
    summary,
  });
  if (created.result?.pendingId) return created.result.pendingId;

  // Already there (live or as our own open draft): replace it wholesale.
  const cur = await rpc(request, tokens.kail, 'plugin.reviewqueue.getLines', {
    page: pageId,
    from: 1,
    count: 0,
  });
  if (!cur.result) {
    throw new Error(
      `could not read ${pageId} to queue a change: ${JSON.stringify(created)} / ${JSON.stringify(cur)}`
    );
  }

  const written = await rpc(request, tokens.kail, 'plugin.reviewqueue.replaceLines', {
    page: pageId,
    from: cur.result.lineStart,
    to: cur.result.lineEnd,
    text,
    expect: cur.result.hash,
    summary,
  });
  if (!written.result?.pendingId) {
    throw new Error(`expected a queued change, got ${JSON.stringify(written)}`);
  }
  return written.result.pendingId;
}

/**
 * Queue a change as kail through the browser edit form, using kail's session
 * cookie rather than a token.
 *
 * The only route that can still stack a second pending change on one page.
 * Over the API it cannot happen any more: createPage refuses when a draft is
 * already open, and a range write continues that draft in place rather than
 * starting another (ADR-0006). Stacking remains reachable - and its warnings
 * worth testing - only for someone editing in the browser, which is what a
 * human placed under review does.
 */
export async function queueViaBrowserAsKail(
  request: any,
  pageId: string,
  text: string,
  summary = 's'
): Promise<{ id: number; body: string }> {
  const cookie = cookieHeaderFor('kail');

  const form = await (
    await request.get(`/doku.php?id=${pageId}&do=edit`, { headers: { Cookie: cookie } })
  ).text();

  // Resubmit every hidden field the form carries (sectok, date, prefix,
  // suffix, ...) so this behaves like a real save rather than a guess at
  // DokuWiki's form contract.
  const fields: Record<string, string> = {};
  for (const m of form.matchAll(
    /<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"/g
  )) {
    fields[m[1]] = m[2].replace(/&amp;/g, '&').replace(/&quot;/g, '"');
  }
  if (!fields.sectok) throw new Error(`no sectok in the edit form for ${pageId}`);

  const res = await request.post('/doku.php', {
    headers: { Cookie: cookie, 'Content-Type': 'application/x-www-form-urlencoded' },
    form: { ...fields, id: pageId, wikitext: text, summary, 'do[save]': '1' },
  });

  // The rendered page carries DokuWiki's msg() notices, which is where both
  // the queue confirmation and ADR-0004's stacking warning appear.
  const body = await res.text();
  const match = /change #(\d+)/.exec(body);
  if (!match) throw new Error(`expected a queue notice for ${pageId}, got no "change #N" in body`);
  return { id: Number(match[1]), body };
}

/** Publish a page directly as martin, who is not subject to review. */
export async function saveAsMartin(
  request: any,
  page: string,
  text: string,
  summary = 'setup'
): Promise<void> {
  const res = await rpc(request, tokens.martin, 'core.savePage', { page, text, summary });
  if (res.result !== true) throw new Error(`setup save failed: ${JSON.stringify(res)}`);
}

/** martin's admin-session cookie, read from the storage state auth.setup wrote. */
export function cookieHeaderFor(user: string): string {
  const storage = JSON.parse(
    fs.readFileSync(path.join(__dirname, '..', '.auth', `${user}-storage.json`), 'utf-8')
  );
  return (storage.cookies as any[]).map((c) => `${c.name}=${c.value}`).join('; ');
}
