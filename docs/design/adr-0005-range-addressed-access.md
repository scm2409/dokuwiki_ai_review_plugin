# ADR-0005: Range-addressed page access

## Status

Accepted (2026-08-30).

## Context

Every existing read and write path in this plugin - `core.getPage`/`core.savePage`,
`getPageToEdit`, `getPendingText` - moves a **whole page** across the wire. On a large
page that alone can exhaust an agent's context, and every small edit costs two full-page
transfers (read the whole thing, write the whole thing back). This is a limitation of the
DokuWiki MCP surface in general, not something specific to review queues - but this
plugin is the right place to address it, because it already owns the "live text vs. my
unreviewed draft" duality (ADR-0004) that any range tool has to respect: a tool that reads
or writes "part of a page" needs to know which page - live or pending - it is a part of,
exactly like `getPageToEdit` already does for the whole page.

Note on search specifically: `core.searchPages` (`inc/Remote/ApiCore.php:324`) already
returns page id, score, title, and an `ft_snippet()` - not the page body - so search was
never the size problem. What was missing is a search that returns **precise positions**
(line number, section) so an agent can follow up with a targeted read instead of a
whole-page one, and one that covers the agent's own unreviewed drafts in the same call
(closing the same gap `searchMyPending`, ADR-0004, closes for whole-page search).

## Decision

### The effective-text model

Every range operation works on the same **effective text** `getPageToEdit` already
computes: the caller's own newest open pending change for the page if one exists,
otherwise the live text. A write op resolves the effective text, splices the addressed
range, and produces a **full new page text** - the queue keeps storing full page text,
never a patch. Approval still writes one whole page revision, the three-way merge
(`helper/merge.php`) still works on full texts, and the reviewer still gets one coherent
base-to-final diff. Only the wire payload shrinks; nothing at rest changes shape.

### Reuse core's own section-edit machinery where it actually fits, derive where it doesn't

DokuWiki already edits pages section-wise in the browser and already builds a table of
contents. Verified against the running container (2024-02-06b), the division below
follows from what each of those actually is:

**Reused verbatim:**

- **`con($pre, $text, $suf, $pretty)`** (`inc/common.php:1247`) - core's own splice for
  section saves, including the "insert a blank line if one is needed" logic. Used for
  every splice in `helper/range.php`; never plain string concatenation.
- **`rawWikiSlices()`'s `"from-to"` byte-range format** (`inc/common.php:1216`) - the same
  format the browser's own section-edit buttons carry
  (`html_secedit_get_button()` → `do=edit&range=...` → `Action/Edit.php` →
  `rawWikiSlices($RANGE, ...)`). `getPageOutline`'s `range` field is emitted in this exact
  format, verified byte-for-byte identical to what a real section-edit link loads
  (`range-corecompat.martin.spec.ts`). `rawWikiSlices()` itself reads the live page from
  disk, so it cannot be pointed at a pending draft - `helper/range.php::slices()`
  reimplements it for arbitrary text instead, deliberately kept identical down to its
  off-by-one, rather than "fixed": diverging from a convention already used elsewhere in
  this codebase would just create a second, incompatible convention.
- **`sectionID()`/`_headerToLink()`** (`inc/pageutils.php:249`, called the same way
  `Doku_Renderer::_headerToLink($title, true)` does) for heading anchors, so an anchor
  from `getPageOutline` is the wiki's real anchor.

**Not reused as the addressing index:**

- The browser's section-edit ranges come from the **xhtml renderer**
  (`inc/parser/xhtml.php:220-256`) and are capped at `$conf['maxseclevel']` (default 3) -
  a heading past that level gets folded into its parent's range instead of an editable
  section of its own. An agent needs to address every heading, not just the shallow ones.
- The stored table of contents (`inc/parser/metadata.php:151`) is filtered by
  `$conf['toptoclevel']`/`$conf['maxtoclevel']`, has its levels renumbered relative to
  `toptoclevel`, and carries no byte positions - a display artifact, not an index.
- Neither exists for a pending draft at all: page metadata and the rendered document are
  only ever produced for a live page.

So `getPageOutline` derives its own outline from **`p_get_instructions($text)`**
(`inc/parserutils.php:219`), which parses raw text without a cache and emits `header`
calls carrying `[title, level, bytePos]` - the same real parser DokuWiki itself uses, so a
`====== heading ======` inside a `<code>` block is correctly not treated as a section
boundary (verified: a naive line-based scan would get this wrong). The result is a strict
superset of the browser's editable sections, in the browser's own byte-range format - one
code path serves live pages and drafts identically.

### Line-based and search tools for everything else

`getLines`/`replaceLines` address a 1-based line range directly, for pages without useful
headings. `findInPage` and `searchWithContext` return line number, section index, and
surrounding context instead of a whole page or a bare snippet; `searchWithContext`'s
`scope` parameter (`live`/`pending`/`all`) makes it, by default, the union of
`core.searchPages` and `searchMyPending` - a single call that never needs both.

### A staleness guard, not a lock

Every read tool returns a short hash (12 hex characters) of the exact region it returned.
Every write tool accepts an `expect` parameter: given, it refuses the write unless the
region's current hash still matches - `replaceLines` requires it outright, since a line
number silently pointing at the wrong place (the page changed since it was read) is a
harder failure mode to notice than a section or search-text match simply failing to
resolve. This is a **guard against a stale read**, not a lock: nothing prevents two
callers from racing past it if both hashes happen to still match at the moment each
writes.

A section's `hash` (from `getPageOutline`/`getSection`) always covers that heading's own
text alone, matching its core-compatible byte range - but `replaceSection`/`deleteSection`
always act on a section *together with* its nested subsections (matching
`resolveSection()`'s default), so `hash` can never match what they actually check for a
heading that has any children. `getPageOutline` additionally exposes `hashWithChildren`
for exactly this: the hash `replaceSection`/`deleteSection`'s `$expect` needs, computed
once per entry so a caller never has to make a second `getSection` call just to get it.
Found and fixed during review, before this phase merged - a section with no children has
`hash === hashWithChildren`, so the distinction is invisible until a page actually has
nested headings, which most do.

## Rationale

- **Full text at rest is a firm constraint, not a convenience.** Storing patches would
  need every consumer of a queue entry - the diff renderer, the three-way merge, the
  preview - to reconstruct a full text before doing anything, turning today's simple
  "read one file" into "replay N patches," for a savings that only ever applies to the
  wire, never to disk.
- **Reusing core's own byte-range convention beats inventing a new one.** An agent, a
  human reviewer, and the browser's own edit-section links now speak the same address
  format; a range that came from one is meaningful to the others without translation.
- **Deriving the outline from the parser instead of the renderer's section-edit list is
  the only way to cover a pending draft at all**, since a draft is never rendered - and it
  happens to also be the more correct choice for a live page (no arbitrary
  `maxseclevel` cutoff, no code-block false positives).

## Consequences

- `helper/range.php` is pure text-in/text-out with no I/O and no queue knowledge - the
  same text logic serves a live page and a pending draft's content without knowing which
  one it was handed, which is what let it be verified directly (byte-for-byte, against
  the running container) before any queue integration was written on top of it.
- A `section`/`anchor` spec (index, `#hid`, `from-to` range, or exact title) is resolved
  fresh against the current effective text on every call - it is not a durable identifier
  that survives someone else's unrelated edit reordering headings. The `expect` guard is
  what catches that case at write time.
- New tools mean new remote methods: `getPageOutline`, `getSection`, `getLines`,
  `findInPage`, `searchWithContext` (read), `replaceSection`, `insertSection`,
  `deleteSection`, `replaceLines`, `replaceText` (write). All are `readOnlyHint: false` in
  the generated MCP schema regardless of whether they read or write, for the same reason
  already noted in ADR-0004: the `mcp` plugin's read-only list only knows core method
  names.
- The write tools' interaction with the queue - continuing the author's own open change
  in place rather than stacking a new one - is a large enough decision on its own to be
  its own ADR: see [ADR-0006](adr-0006-author-change-lifecycle.md).
- Deliberately out of scope for this ADR: moving a section *across* pages (would need a
  "linked changes" concept so both halves of the move are approved together), and a
  regex grep directly over `data/pages/` (namespace-unbounded on a large wiki, and finds
  syntax/markup a search-index-based tool would not) - recorded as open in
  `docs/roadmap.md`.
