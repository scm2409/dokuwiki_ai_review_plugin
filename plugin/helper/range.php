<?php

use dokuwiki\Extension\Plugin;

/**
 * Pure text operations for addressing part of a page: the outline (headings
 * with their byte ranges), section/line lookup and splicing, and searching
 * with line context.
 *
 * No I/O and no queue knowledge - every method takes wiki text in and hands
 * text or offsets back out. That is what makes it usable identically for a
 * live page and for a pending draft's text: the caller (remote.php) decides
 * which text to hand in, this class never reads a page or a queue entry
 * itself.
 *
 * Section boundaries come from DokuWiki's own parser (p_get_instructions()),
 * not a regex - a "====== heading ======" inside a <code> block must not
 * become a section boundary, and only the real parser knows that.
 *
 * The byte-range format ("from-to", see rawWikiSlices() in inc/common.php)
 * and the splice (con()) reuse core's own conventions verbatim, verified
 * against the running container: a header's raw parser byte position P
 * corresponds to 0-based text offset P-1, and core's own section slicing
 * builds a range as "P_i-(P_{i+1}-1)" (last section: "P_i-"). Reproducing
 * that exact formula - not a guessed off-by-one - is what makes a range
 * from outline() interchangeable with the range the browser's own
 * section-edit links carry. One consequence of that formula, also verified:
 * the single byte between two sections (typically the blank line's second
 * newline) belongs to neither slice, which is exactly why con()'s $pretty
 * flag exists - splicing must always go through spliceBytes() below, never
 * plain string concatenation, or that separator silently disappears on
 * every edit.
 */
class helper_plugin_reviewqueue_range extends Plugin
{
    /**
     * The page's headings, each with the byte/line span of its own section
     * (i.e. up to the next heading of any level - see resolveSection() for
     * the "with children" extension).
     *
     * Every heading becomes one entry, regardless of $conf['maxseclevel'] -
     * unlike the browser's section-edit buttons, which only cover headings
     * up to that configured level and silently fold deeper ones into their
     * parent. Index 0 is always the preamble before the first heading
     * (level 0, no title, no hid) and is included even when empty, so
     * section indices are stable regardless of whether the page happens to
     * start with a heading.
     *
     * @param string $text
     * @return array[] one entry per section: index, level, title, hid,
     *                  range, byteStart, byteEnd, lineStart, lineEnd,
     *                  bytes, lines, hash, hashWithChildren
     */
    public function outline($text)
    {
        $headers = $this->headers($text);
        $len = strlen($text);

        // positions[0] is the (nonexistent) preamble "heading"; real headers
        // follow in document order. Building the range for section $i only
        // ever needs positions[$i] (its own start) and positions[$i+1] (the
        // next heading's start, which is where its own range ends) - the
        // same two values core's finishSectionEdit()/startSectionEdit() pair
        // consume, so this reproduces core's formula exactly.
        $positions = [null];
        foreach ($headers as $header) {
            $positions[] = $header['pos'];
        }
        $positions[] = null; // sentinel: "end of text" for the last section

        $sections = [];
        $count = count($headers);
        for ($i = 0; $i <= $count; $i++) {
            $header = $headers[$i - 1] ?? null;
            [$byteStart, $byteEnd] = $this->sliceBounds($positions[$i] ?? null, $positions[$i + 1] ?? null, $len);
            $slice = substr($text, $byteStart, $byteEnd - $byteStart);

            $sections[] = [
                'index'     => $i,
                'level'     => $header['level'] ?? 0,
                'title'     => $header['title'] ?? '',
                'hid'       => $header['hid'] ?? '',
                // Built from the final byte offsets, not from the raw
                // parser position minus one: a page whose very first byte
                // is a heading gives that heading position 1, and "1 - 1"
                // is the string "0" - indistinguishable from "no end given"
                // in rawWikiSlices()'s own truthiness check ($to ? ... :
                // ...), which would then silently round-trip as "to the end
                // of the text" instead of "empty". Deriving the string from
                // byteStart/byteEnd (already boundary-checked below) avoids
                // that collision instead of reproducing it. Verified against
                // the running container for both this edge case and every
                // ordinary section.
                'range'     => $this->rangeStringFromBytes($byteStart, $byteEnd, $len),
                'byteStart' => $byteStart,
                'byteEnd'   => $byteEnd,
                'lineStart' => $this->lineAt($text, $byteStart),
                'lineEnd'   => $this->lineAt($text, max($byteStart, $byteEnd - 1)),
                'bytes'     => strlen($slice),
                'lines'     => $this->countLines($slice),
                'hash'      => $this->hash($slice),
            ];
        }

        // A second pass, once every section's own byteEnd is known: the
        // "hash" above only ever covers a section's own text, matching this
        // format's core-compatible range (verified against a real
        // section-edit link). But replaceSection()/deleteSection() always
        // act on a section *with* its nested subsections (matching
        // resolveSection()'s default) - checking $expect against "hash"
        // there would refuse every write to a heading that has children,
        // permanently, since that hash can never match what those tools
        // actually replace. "hashWithChildren" is what to pass instead.
        foreach ($sections as $i => $section) {
            $childrenEnd = $this->childrenInclusiveEnd($sections, $i);
            $sections[$i]['hashWithChildren'] = $childrenEnd === $section['byteEnd']
                ? $section['hash']
                : $this->hash(substr($text, $section['byteStart'], $childrenEnd - $section['byteStart']));
        }

        return $sections;
    }

    /**
     * Resolve a section spec against $text to a byte range, optionally
     * extended to cover its nested subsections.
     *
     * $spec accepts, tried in this order: a numeric section index (as
     * returned by outline()), a "from-to" byte range, "#hid", or an exact
     * heading title (case-insensitive; if more than one heading shares that
     * title, throws naming the ambiguous indices).
     *
     * @param string $text
     * @param string $spec section index, "from-to" range, "#hid" or title
     * @param bool $withChildren include nested subsections (stop only at the next heading of equal or shallower level)
     * @return array the matching outline() entry, byteEnd/lineEnd/range/bytes/lines/hash adjusted for $withChildren
     * @throws \InvalidArgumentException no match, or an ambiguous title
     */
    public function resolveSection($text, $spec, $withChildren = true)
    {
        $sections = $this->outline($text);
        $spec = trim((string) $spec);

        $match = $this->findSection($sections, $spec);
        if ($match === null) {
            throw new \InvalidArgumentException("reviewqueue: no section matches '$spec'");
        }

        if (!$withChildren) {
            return $match;
        }

        $byteEnd = $this->childrenInclusiveEnd($sections, $match['index']);
        if ($byteEnd === $match['byteEnd']) {
            return $match;
        }

        $len = strlen($text);
        $slice = substr($text, $match['byteStart'], $byteEnd - $match['byteStart']);
        $hash = $this->hash($slice);

        return [
            'index'            => $match['index'],
            'level'            => $match['level'],
            'title'            => $match['title'],
            'hid'              => $match['hid'],
            'range'            => $this->rangeStringFromBytes($match['byteStart'], $byteEnd, $len),
            'byteStart'        => $match['byteStart'],
            'byteEnd'          => $byteEnd,
            'lineStart'        => $match['lineStart'],
            'lineEnd'          => $this->lineAt($text, max($match['byteStart'], $byteEnd - 1)),
            'bytes'            => strlen($slice),
            'lines'            => $this->countLines($slice),
            'hash'             => $hash,
            // Already fully extended - nothing further to include.
            'hashWithChildren' => $hash,
        ];
    }

    /**
     * Core's own three-way split of a page by a "from-to" byte range - see
     * rawWikiSlices() in inc/common.php, reimplemented here because that
     * function reads the live page from disk and cannot be pointed at a
     * pending draft's text. Same range format, same defaults, same
     * off-by-one, deliberately kept byte-for-byte identical to core.
     *
     * @param string $text
     * @param string $range "from-to" in the same 1-based, inclusive-ish convention core's section-edit links use
     * @return string[] [prefix, section, suffix]
     */
    public function slices($text, $range)
    {
        $parts = explode('-', $range, 2);
        $from = $parts[0] !== '' ? ((int) $parts[0]) : 0;
        $to = ($parts[1] ?? '') !== '' ? ((int) $parts[1]) : 0;

        $from = $from ? $from - 1 : 0;
        $to = $to ? $to - 1 : strlen($text);

        return [
            substr($text, 0, $from),
            substr($text, $from, $to - $from),
            substr($text, $to),
        ];
    }

    /**
     * Replace the byte range [$start, $end) of $text with $new, using
     * core's own con() so a blank line is reinserted between the spliced-in
     * text and its neighbours when needed - see the class docblock for why
     * this matters and plain concatenation must not be used instead.
     *
     * @param string $text
     * @param int $start
     * @param int $end
     * @param string $new
     * @return string
     */
    public function spliceBytes($text, $start, $end, $new)
    {
        return con(substr($text, 0, $start), $new, substr($text, $end), true);
    }

    /**
     * A 1-based, inclusive line range as a byte range.
     *
     * @param string $text
     * @param int $from first line, 1-based
     * @param int $to last line, 1-based; 0 means "to the last line"
     * @return int[] [byteStart, byteEnd]
     * @throws \InvalidArgumentException $from/$to out of range
     */
    public function resolveLines($text, $from, $to)
    {
        $starts = $this->lineStarts($text);
        $lineCount = count($starts);
        $from = (int) $from;
        $to = (int) $to ?: $lineCount;

        if ($from < 1 || $from > $lineCount || $to < $from || $to > $lineCount) {
            throw new \InvalidArgumentException(
                "reviewqueue: line range $from-$to is out of bounds (page has $lineCount lines)"
            );
        }

        $byteStart = $starts[$from - 1];
        $byteEnd = $to < $lineCount ? $starts[$to] : strlen($text);
        return [$byteStart, $byteEnd];
    }

    /**
     * Up to $count lines of $text starting at line $from (1-based).
     *
     * @param string $text
     * @param int $from first line, 1-based
     * @param int $count number of lines; 0 means "to the end"
     * @return int[] [byteStart, byteEnd]
     * @throws \InvalidArgumentException $from out of range
     */
    public function lines($text, $from, $count)
    {
        $starts = $this->lineStarts($text);
        $lineCount = count($starts);
        $from = (int) $from;

        if ($from < 1 || $from > $lineCount) {
            throw new \InvalidArgumentException(
                "reviewqueue: line $from is out of bounds (page has $lineCount lines)"
            );
        }

        $to = $count > 0 ? min($lineCount, $from + (int) $count - 1) : $lineCount;
        $byteStart = $starts[$from - 1];
        $byteEnd = $to < $lineCount ? $starts[$to] : strlen($text);
        return [$byteStart, $byteEnd];
    }

    /**
     * Case-insensitive substring search over $text, one entry per matching
     * line, with surrounding lines for context.
     *
     * @param string $text
     * @param string $query
     * @param int $contextLines lines of context on either side of the match
     * @return array[] one entry per matching line: line, section, text, context
     */
    public function findInText($text, $query, $contextLines = 2)
    {
        $query = (string) $query;
        if ($query === '') return [];

        $lines = explode("\n", $text);
        $starts = $this->lineStarts($text);
        $sections = $this->outline($text);

        $hits = [];
        foreach ($lines as $i => $line) {
            if (stripos($line, $query) === false) continue;

            $lineNo = $i + 1;
            $from = max(1, $lineNo - $contextLines);
            $to = min(count($lines), $lineNo + $contextLines);
            $context = implode("\n", array_slice($lines, $from - 1, $to - $from + 1));

            $hits[] = [
                'line'    => $lineNo,
                'section' => $this->sectionIndexAt($sections, $starts[$i]),
                'text'    => $line,
                'context' => $context,
            ];
        }

        return $hits;
    }

    /**
     * Short content-addressed hash, for the staleness guard: a caller
     * re-submitting a stale read of this exact text/slice can be detected
     * without shipping the text itself back and forth.
     *
     * @param string $text
     * @return string 12 hex characters
     */
    public function hash($text)
    {
        return substr(sha1($text), 0, 12);
    }

    /**
     * 1-based line number containing byte offset $byteOffset.
     *
     * @param string $text
     * @param int $byteOffset
     * @return int
     */
    public function lineAt($text, $byteOffset)
    {
        return substr_count($text, "\n", 0, max(0, $byteOffset)) + 1;
    }

    /**
     * Number of lines in $text (a trailing newline does not count as an
     * extra empty line, matching how editors report line counts).
     *
     * @param string $text
     * @return int
     */
    public function countLines($text)
    {
        if ($text === '') return 0;
        $count = substr_count($text, "\n");
        return str_ends_with($text, "\n") ? $count : $count + 1;
    }

    /**
     * @param string $text
     * @return array[] level, title, hid, pos (raw parser byte position) - document order
     */
    protected function headers($text)
    {
        $headers = [];
        $check = [];
        foreach (p_get_instructions($text) as [$fn, $args]) {
            if ($fn !== 'header') continue;
            [$title, $level, $pos] = $args;
            $headers[] = [
                'level' => $level,
                'title' => $title,
                // Same call core's own renderer makes to assign heading
                // anchors (Doku_Renderer::_headerToLink($title, true)), with
                // $check threaded through in document order so repeated
                // titles get the same "title", "title1", "title2" suffixes
                // a live render of this text would produce.
                'hid'   => sectionID($title, $check),
                'pos'   => $pos,
            ];
        }
        return $headers;
    }

    /**
     * @param string|null $startPos raw parser position, or null for "from the beginning"
     * @param string|null $nextPos raw parser position of the next heading, or null for "to the end"
     * @param int $len strlen($text)
     * @return int[] [byteStart, byteEnd]
     */
    protected function sliceBounds($startPos, $nextPos, $len)
    {
        $byteStart = $startPos === null ? 0 : $startPos - 1;
        $byteEnd = $nextPos === null ? $len : $nextPos - 2;
        return [$byteStart, max($byteStart, $byteEnd)];
    }

    /**
     * The single place a "from-to" range string is built, always from
     * already-resolved 0-based byte offsets - see the comment in outline()
     * for why building it from raw parser positions instead is a trap.
     *
     * @param int $byteStart
     * @param int $byteEnd
     * @param int $len strlen(text)
     * @return string
     */
    protected function rangeStringFromBytes($byteStart, $byteEnd, $len)
    {
        $from = $byteStart === 0 ? '' : (string) ($byteStart + 1);
        $to = $byteEnd >= $len ? '' : (string) ($byteEnd + 1);
        return "$from-$to";
    }

    /**
     * The byte offset a section's content extends to once its nested
     * subsections are included: the next section at an equal-or-shallower
     * level, or the end of the text if there is none. Shared by outline()
     * (to compute "hashWithChildren" for every entry up front) and
     * resolveSection() (to compute the extended range for one entry on
     * demand) so the two never define "with children" two different ways.
     *
     * The preamble (index 0, level 0) never has "children" in this sense -
     * it always returns its own byteEnd unchanged.
     *
     * @param array[] $sections outline() result, in document order
     * @param int $index
     * @return int
     */
    protected function childrenInclusiveEnd(array $sections, $index)
    {
        $section = $sections[$index];
        if ($section['level'] === 0) {
            return $section['byteEnd'];
        }

        $end = $section['byteEnd'];
        foreach ($sections as $candidate) {
            if ($candidate['index'] <= $index) continue;
            if ($candidate['level'] <= $section['level']) break;
            $end = $candidate['byteEnd'];
        }
        return $end;
    }

    /**
     * @param array[] $sections outline() result
     * @param string $spec
     * @return array|null
     * @throws \InvalidArgumentException ambiguous title
     */
    protected function findSection(array $sections, $spec)
    {
        if ($spec === '' || $spec === '0') {
            return $sections[0] ?? null;
        }

        if (ctype_digit($spec)) {
            return $sections[(int) $spec] ?? null;
        }

        if (preg_match('/^\d*-\d*$/', $spec)) {
            foreach ($sections as $section) {
                if ($section['range'] === $spec) return $section;
            }
            return null;
        }

        if ($spec[0] === '#') {
            $hid = substr($spec, 1);
            foreach ($sections as $section) {
                if ($section['hid'] === $hid) return $section;
            }
            return null;
        }

        $matches = [];
        foreach ($sections as $section) {
            if ($section['index'] === 0) continue;
            if (strcasecmp($section['title'], $spec) === 0) $matches[] = $section;
        }

        if (count($matches) > 1) {
            $indices = implode(', ', array_column($matches, 'index'));
            throw new \InvalidArgumentException(
                "reviewqueue: '$spec' matches more than one heading (indices $indices) - use the index or #hid instead"
            );
        }

        return $matches[0] ?? null;
    }

    /**
     * Byte offset of the start of each line, index 0 = line 1.
     *
     * @param string $text
     * @return int[]
     */
    protected function lineStarts($text)
    {
        if ($text === '') return [];

        $starts = [0];
        $offset = 0;
        while (($pos = strpos($text, "\n", $offset)) !== false) {
            $starts[] = $pos + 1;
            $offset = $pos + 1;
        }
        // A trailing newline does not start an extra, non-existent line.
        if (($starts[count($starts) - 1] ?? 0) >= strlen($text) && count($starts) > 1) {
            array_pop($starts);
        }
        return $starts;
    }

    /**
     * @param array[] $sections outline() result
     * @param int $byteOffset
     * @return int the deepest-matching section's index
     */
    protected function sectionIndexAt(array $sections, $byteOffset)
    {
        $index = 0;
        foreach ($sections as $section) {
            if ($byteOffset >= $section['byteStart']) $index = $section['index'];
        }
        return $index;
    }
}
