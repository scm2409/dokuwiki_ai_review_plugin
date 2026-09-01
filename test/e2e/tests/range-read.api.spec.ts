import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { tokens, rpc, saveAsMartin } from './_helpers';

// Phase 10 (docs/design/adr-0005): read tools that work on part of a page
// instead of the whole thing. Covers strategy.md scenarios 20-21.

const NESTED = [
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
  '===== Beta =====',
  '',
  'Beta body with a findme marker.',
  '',
].join('\n');

test('getPageOutline lists every heading, nested and all', async ({ request }) => {
  const pageId = `rangeoutline${Date.now()}`;
  await saveAsMartin(request, pageId, NESTED);

  const res = await rpc(request, tokens.martin, 'plugin.reviewqueue.getPageOutline', { page: pageId });
  expect(res.error).toBeUndefined();
  const sections = res.result.sections;

  expect(res.result.source).toBe('live');
  expect(sections.map((s: any) => s.title)).toEqual(['', 'Top', 'Alpha', 'Alpha Child', 'Beta']);
  expect(sections.map((s: any) => s.level)).toEqual([0, 1, 2, 3, 2]);
  expect(sections[0].bytes).toBe(0); // page starts with a heading, empty preamble
});

test('getSection returns one section, with or without its nested children', async ({ request }) => {
  const pageId = `rangesection${Date.now()}`;
  await saveAsMartin(request, pageId, NESTED);

  const withChildren = await rpc(request, tokens.martin, 'plugin.reviewqueue.getSection', {
    page: pageId,
    section: 'Alpha',
  });
  expect(withChildren.result.text).toContain('Alpha Child');
  expect(withChildren.result.text).toContain('Alpha child body');

  const withoutChildren = await rpc(request, tokens.martin, 'plugin.reviewqueue.getSection', {
    page: pageId,
    section: 'Alpha',
    children: false,
  });
  expect(withoutChildren.result.text).not.toContain('Alpha Child');
  expect(withoutChildren.result.text).toContain('Alpha body');

  // Addressable by the range string from getPageOutline too, not just title.
  const outline = await rpc(request, tokens.martin, 'plugin.reviewqueue.getPageOutline', { page: pageId });
  const betaEntry = outline.result.sections.find((s: any) => s.title === 'Beta');
  const byRange = await rpc(request, tokens.martin, 'plugin.reviewqueue.getSection', {
    page: pageId,
    section: betaEntry.range,
  });
  expect(byRange.result.text).toContain('findme marker');
});

test('getSection refuses an ambiguous title and names the candidates', async ({ request }) => {
  const pageId = `rangeambiguous${Date.now()}`;
  await saveAsMartin(request, pageId, '====== X ======\nfoo\n\n====== X ======\nbar\n');

  const res = await rpc(request, tokens.martin, 'plugin.reviewqueue.getSection', {
    page: pageId,
    section: 'X',
  });
  expect(res.error).toBeTruthy();
  expect(res.error.message).toMatch(/more than one heading/);
});

test('a fake heading inside a code block is not treated as a section boundary', async ({ request }) => {
  // The reason section boundaries come from the real parser and not a
  // regex (docs/design/adr-0005): a naive line-based scan would see two
  // headings here instead of one.
  const pageId = `rangecodeblock${Date.now()}`;
  const text = [
    '====== Real Heading ======',
    '',
    '<code>',
    '====== not a heading ======',
    '</code>',
    '',
    'trailing text',
    '',
  ].join('\n');
  await saveAsMartin(request, pageId, text);

  const res = await rpc(request, tokens.martin, 'plugin.reviewqueue.getPageOutline', { page: pageId });
  const titles = res.result.sections.map((s: any) => s.title);
  expect(titles).toEqual(['', 'Real Heading']);
});

test('getLines reads an arbitrary line range for pages without useful headings', async ({ request }) => {
  const pageId = `rangelines${Date.now()}`;
  await saveAsMartin(request, pageId, 'line one\nline two\nline three\nline four\n');

  const res = await rpc(request, tokens.martin, 'plugin.reviewqueue.getLines', {
    page: pageId,
    from: 2,
    count: 2,
  });
  expect(res.result.text).toBe('line two\nline three\n');
  expect(res.result.lineStart).toBe(2);
  expect(res.result.lineEnd).toBe(3);

  const outOfBounds = await rpc(request, tokens.martin, 'plugin.reviewqueue.getLines', {
    page: pageId,
    from: 99,
    count: 1,
  });
  expect(outOfBounds.error).toBeTruthy();
});

test('findInPage returns line numbers with surrounding context', async ({ request }) => {
  const pageId = `rangefind${Date.now()}`;
  await saveAsMartin(request, pageId, NESTED);

  const res = await rpc(request, tokens.martin, 'plugin.reviewqueue.findInPage', {
    page: pageId,
    query: 'findme',
    context: 1,
  });
  expect(res.result.hits).toHaveLength(1);
  expect(res.result.hits[0].text).toContain('findme marker');
  expect(res.result.hits[0].context).toContain('Beta body with a findme marker');
});

test('searchWithContext covers both live pages and the caller own pending drafts', async ({
  request,
}) => {
  // Unique per run: a fixed marker accumulates a live page on every run, so
  // the "exactly these two pages" assertions below only held on a fresh
  // container and drifted as soon as the suite was run twice.
  const marker = `zzqsearchmarker${Date.now()}`;

  const livePageId = `rangesearchlive${Date.now()}`;
  await saveAsMartin(request, livePageId, `====== Live ======\n\n${marker} in the live page.\n`);

  const draftPageId = `rangesearchpending${Date.now()}`;
  const queued = await rpc(request, tokens.kail, 'plugin.reviewqueue.createPage', {
    page: draftPageId,
    text: `====== Draft ======\n\n${marker} in kail's own draft.\n`,
    summary: 's',
  });
  expect(queued.result.status).toBe('queued');

  const all = await rpc(request, tokens.kail, 'plugin.reviewqueue.searchWithContext', {
    query: marker,
  });
  const pages = all.result.map((r: any) => r.page).sort();
  expect(pages).toEqual([draftPageId, livePageId].sort());
  const draftResult = all.result.find((r: any) => r.page === draftPageId);
  expect(draftResult.source).toBe('pending');
  const liveResult = all.result.find((r: any) => r.page === livePageId);
  expect(liveResult.source).toBe('live');

  // core.searchPages, by contrast, never sees the pending draft (ADR-0004).
  const core = await rpc(request, tokens.kail, 'core.searchPages', { query: marker });
  expect(core.result.map((r: any) => r.id)).not.toContain(draftPageId);

  const liveOnly = await rpc(request, tokens.kail, 'plugin.reviewqueue.searchWithContext', {
    query: marker,
    scope: 'live',
  });
  expect(liveOnly.result.map((r: any) => r.page)).toEqual([livePageId]);
});

test('getPageOutline exposes a distinct hashWithChildren for a section that has nested subsections', async ({
  request,
}) => {
  // Regression: "hash" is always the section's own text alone (matching
  // core's byte range), but replaceSection/deleteSection always act
  // together with any nested subsections. Passing "hash" as their $expect
  // for a heading with children would refuse the write every time, even
  // when nothing changed - see docs/design/adr-0005.
  const pageId = `rangehashchildren${Date.now()}`;
  await saveAsMartin(request, pageId, NESTED);

  const outline = await rpc(request, tokens.martin, 'plugin.reviewqueue.getPageOutline', { page: pageId });
  const alpha = outline.result.sections.find((s: any) => s.title === 'Alpha');
  const beta = outline.result.sections.find((s: any) => s.title === 'Beta');

  // Alpha has a nested child (Alpha Child): the two hashes must differ.
  expect(alpha.hashWithChildren).not.toBe(alpha.hash);
  // Beta has no children: both hashes describe the same text.
  expect(beta.hashWithChildren).toBe(beta.hash);

  // hashWithChildren is exactly what getSection's default (children=true,
  // matching replaceSection/deleteSection) computes for the same section.
  const withChildren = await rpc(request, tokens.martin, 'plugin.reviewqueue.getSection', {
    page: pageId,
    section: 'Alpha',
  });
  expect(alpha.hashWithChildren).toBe(withChildren.result.hash);
});

test('a section index-0 range round-trips through getSection even when the page starts with a heading', async ({
  request,
}) => {
  // Regression: outline()'s own range-building can legitimately produce a
  // range like "-1" for an empty preamble (a page whose very first byte is
  // a heading) - findSection()'s range-matching regex used to require at
  // least one digit before the dash and silently rejected exactly the
  // range outline() itself had just emitted.
  const pageId = `rangepreambleroundtrip${Date.now()}`;
  await saveAsMartin(request, pageId, '====== Starts With Heading ======\n\nbody\n');

  const outline = await rpc(request, tokens.martin, 'plugin.reviewqueue.getPageOutline', { page: pageId });
  const preamble = outline.result.sections[0];
  expect(preamble.title).toBe('');
  expect(preamble.bytes).toBe(0);

  const bySpec = await rpc(request, tokens.martin, 'plugin.reviewqueue.getSection', {
    page: pageId,
    section: preamble.range,
  });
  expect(bySpec.error).toBeUndefined();
  expect(bySpec.result.text).toBe('');
});
