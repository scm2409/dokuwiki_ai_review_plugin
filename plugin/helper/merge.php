<?php

use dokuwiki\Extension\Plugin;

/**
 * Three-way merge of a queued change against a page that moved on while the
 * change was waiting, using DokuWiki's own Diff3 (inc/DifferenceEngine.php).
 *
 * Nothing is stored: a merge is always recomputed from the current live text.
 * The live page can change again between a failed approval and the reviewer
 * resolving it, so a merge result cached at conflict time would be stale by
 * the time anyone acted on it.
 */
class helper_plugin_reviewqueue_merge extends Plugin
{
    /** Marker lines used in the conflicted output the reviewer edits. */
    public const MARK_LIVE = '<<<<<<< current page';
    public const MARK_SPLIT = '=======';
    public const MARK_PENDING = '>>>>>>> proposed change';

    /**
     * @param string $base the page text the change was written against
     * @param string $live the page text as it is now
     * @param string $pending the proposed text
     * @return array 'text' => merged wiki text, 'conflicts' => number of
     *               conflicting blocks (0 means it merged cleanly)
     */
    public function merge($base, $live, $pending)
    {
        $this->requireDiffEngine();

        $diff3 = new \Diff3(
            explode("\n", $base),
            explode("\n", $live),
            explode("\n", $pending)
        );

        $lines = [];
        $conflicts = 0;

        foreach ($diff3->_edits as $edit) {
            if (!$edit->isConflict()) {
                $lines = array_merge($lines, $edit->merged());
                continue;
            }

            $conflicts++;
            $lines = array_merge(
                $lines,
                [self::MARK_LIVE],
                $this->sideOf($edit, 'final1'),
                [self::MARK_SPLIT],
                $this->sideOf($edit, 'final2'),
                [self::MARK_PENDING]
            );
        }

        return [
            'text'      => implode("\n", $lines),
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Read one side of a conflicting block.
     *
     * Diff3::mergedOutput() would do all of the above for us, but it is broken
     * in DokuWiki 2024-02-06b: on the conflict branch it reads $edit->final1
     * and $edit->final2 directly, and those are declared protected on
     * _Diff3_Op (inc/DifferenceEngine.php:1458), so it fatals with "Cannot
     * access protected property" the moment there actually is a conflict. Core
     * never calls it - Diff3 isn't even in the autoload map in inc/load.php -
     * so the breakage went unnoticed upstream.
     *
     * Clean merges avoid the issue entirely (merged() is public), so this
     * reflection is confined to the conflict path. It keeps working unchanged
     * if a later DokuWiki release makes those properties public again.
     *
     * @param object $edit a _Diff3_Op
     * @param string $side 'final1' (current page) or 'final2' (proposed)
     * @return array lines
     */
    protected function sideOf($edit, $side)
    {
        $prop = new \ReflectionProperty($edit, $side);
        $prop->setAccessible(true);
        $value = $prop->getValue($edit);
        return is_array($value) ? $value : [];
    }

    /**
     * DokuWiki's autoload map (inc/load.php) lists Diff, TableDiffFormatter
     * and UnifiedDiffFormatter from inc/DifferenceEngine.php - but not Diff3,
     * so it never loads on its own. Checking for the class without triggering
     * the autoloader tells us whether the file is already in, which makes the
     * require safe against a redeclare.
     */
    protected function requireDiffEngine()
    {
        if (!class_exists('Diff3', false)) {
            require_once(DOKU_INC . 'inc/DifferenceEngine.php');
        }
    }

    /**
     * The text a queued change was written against.
     *
     * Normally this was stored alongside the change. The attic fallback only
     * matters for changes queued before base texts were kept, and is verified
     * against the recorded hash because DokuWiki revisions are second-granular:
     * if a human saved in the same second the change was based on, the attic
     * entry for that timestamp holds their text, not the original.
     *
     * Returns null when the base cannot be established. A three-way merge is
     * impossible then, and the caller must fall back to manual resolution
     * rather than merge against a wrong base.
     *
     * @param array $record
     * @return string|null
     */
    public function baseText(array $record)
    {
        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');

        $stored = $store->getBase($record['id']);
        if ($stored !== null && sha1($stored) === $record['baseHash']) {
            return $stored;
        }

        // A change that created the page has an empty document as its base,
        // which is exactly right for a three-way merge.
        if (empty($record['baseRev'])) {
            return $record['baseHash'] === sha1('') ? '' : null;
        }

        $text = rawWiki($record['target'], $record['baseRev']);
        return sha1($text) === $record['baseHash'] ? $text : null;
    }
}
