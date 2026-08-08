<?php

use dokuwiki\Extension\Plugin;

/**
 * File-based CRUD for pending changes under data/reviewqueue/ (see ADR-0002
 * and docs/design/spec.md for the on-disk layout). No SQL, no migrations -
 * a linear directory scan is enough at the expected scale.
 */
class helper_plugin_reviewqueue_store extends Plugin
{
    /**
     * Persist a new pending change.
     *
     * Fail-closed by design: throws on any I/O problem instead of silently
     * succeeding. Callers must never let the original save through when this
     * throws (see docs/design/spec.md, "Fail-closed").
     *
     * @param array $meta type, target, author, summary, minor, baseRev,
     *                     baseHash, origin - see docs/design/spec.md for
     *                     the full field list
     * @param string $content new page text (empty string = deletion)
     * @param string $base the page text the change was written against, kept
     *                     for the three-way merge on approval
     * @return int the new pending change id
     * @throws \RuntimeException on any storage failure
     */
    public function enqueue(array $meta, $content, $base = '')
    {
        $this->ensureDirs();
        $id = $this->nextId();

        // io_lock() gives up after 3 seconds and treats the lock as stale, so
        // under pathological load two callers could in principle be handed the
        // same id. Refuse rather than silently overwrite somebody else's queued
        // change - losing a change quietly is exactly what fail-closed forbids.
        if (file_exists($this->queueFile($id, 'json'))) {
            throw new \RuntimeException("reviewqueue: change id #$id is already taken");
        }

        $record = $meta + [
            'reviewer'    => null,
            'reviewedAt'  => null,
            'comment'     => null,
            'mergeResult' => null,
        ];
        $record['id']      = $id;
        $record['created'] = time();
        $record['state']   = 'pending';

        $ok = io_saveFile($this->queueFile($id, 'json'), json_encode(
            $record,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
        $ok = $ok && io_saveFile($this->queueFile($id, 'content'), $content);
        // The base text is stored rather than read back from the attic on
        // demand: DokuWiki revisions are second-granular, so when a human
        // saves in the same second as the queued change was based on, the
        // attic entry for that timestamp is overwritten and the original is
        // simply gone. That is exactly the busy-wiki situation where an
        // automatic merge is most valuable.
        $ok = $ok && io_saveFile($this->queueFile($id, 'base'), $base);

        if (!$ok) {
            @unlink($this->queueFile($id, 'json'));
            @unlink($this->queueFile($id, 'content'));
            @unlink($this->queueFile($id, 'base'));
            throw new \RuntimeException("reviewqueue: failed to persist pending change #$id");
        }

        return $id;
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function get($id)
    {
        $file = $this->fileFor($id, 'json');
        if (!$file || !file_exists($file)) return null;
        $data = json_decode(io_readFile($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param int $id
     * @return string
     */
    public function getContent($id)
    {
        $file = $this->fileFor($id, 'content');
        return $file ? io_readFile($file) : '';
    }

    /**
     * The page text a change was written against, or null when it was not
     * recorded (changes queued before base texts were stored).
     *
     * @param int $id
     * @return string|null
     */
    public function getBase($id)
    {
        $file = $this->fileFor($id, 'base');
        return $file ? io_readFile($file) : null;
    }

    /**
     * Take a copy of an uploaded file into the queue.
     *
     * Deliberately not routed through io_saveFile()/io_readFile() like the
     * page text is: io_readFile() runs cleanText() on what it reads, which
     * normalises line endings and would corrupt any binary. Media is copied
     * byte for byte instead.
     *
     * @param int $id change id
     * @param string $tmpFile path of the uploaded temp file
     * @throws \RuntimeException on any storage failure
     */
    public function putMedia($id, $tmpFile)
    {
        $this->ensureDirs();
        if (!@copy($tmpFile, $this->queueFile($id, 'media'))) {
            throw new \RuntimeException("reviewqueue: failed to store media for change #$id");
        }
    }

    /**
     * Path of the stored upload for a change, or null if there is none.
     *
     * @param int $id
     * @return string|null
     */
    public function mediaPath($id)
    {
        return $this->fileFor($id, 'media');
    }

    /**
     * Overwrite a pending change's metadata, e.g. when transitioning state.
     * Content is immutable once queued (a re-edit is a new pending change).
     *
     * @param array $record full record as previously returned by get(), with
     *                       updated fields
     * @throws \RuntimeException on any storage failure
     */
    public function update(array $record)
    {
        $id = $record['id'];
        $ok = io_saveFile($this->queueFile($id, 'json'), json_encode(
            $record,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
        if (!$ok) {
            throw new \RuntimeException("reviewqueue: failed to update pending change #$id");
        }
    }

    /**
     * Move a decided change from queue/ to archive/.
     *
     * @param int $id
     * @throws \RuntimeException on any storage failure
     */
    public function archive($id)
    {
        $this->ensureDirs();

        // Move the payload first and the metadata last. There is no atomic
        // multi-file rename, so if this is interrupted the change simply stays
        // in the queue (its .json is what listChanges() goes by) rather than
        // becoming a half-archived entry with no content.
        $moved = [];
        foreach (['content', 'base', 'media', 'json'] as $ext) {
            $from = $this->queueFile($id, $ext);
            if (!file_exists($from)) continue;
            $to = $this->archiveFile($id, $ext);
            if (@rename($from, $to)) {
                $moved[] = [$to, $from];
                continue;
            }
            // Roll back so the change stays consistently in the queue.
            foreach ($moved as [$movedTo, $movedFrom]) {
                @rename($movedTo, $movedFrom);
            }
            throw new \RuntimeException("reviewqueue: failed to archive pending change #$id");
        }
    }

    /**
     * Delete a queued change outright, without archiving it.
     *
     * Only for changes that were never viable - e.g. an upload whose payload
     * could not be stored. A change a human decided on belongs in the archive
     * instead, so that the decision stays on record.
     *
     * @param int $id
     */
    public function discard($id)
    {
        foreach (['json', 'content', 'base', 'media'] as $ext) {
            @unlink($this->queueFile($id, $ext));
        }
    }

    /**
     * Archive changes that have been sitting in the queue for too long.
     *
     * @param int $days age threshold; 0 disables expiry
     * @return int number of changes archived
     */
    public function expireOlderThan($days)
    {
        $days = (int) $days;
        if ($days <= 0) return 0;

        $cutoff = time() - ($days * 86400);
        $count = 0;

        foreach ($this->listChanges(['state' => 'pending']) as $record) {
            if ($record['created'] > $cutoff) continue;
            $record['state'] = 'superseded';
            $record['comment'] = 'Expired after ' . $days . ' days without review.';
            $this->update($record);
            $this->archive($record['id']);
            $count++;
        }

        return $count;
    }

    /**
     * @param array $filter e.g. ['state' => 'pending', 'target' => 'start']
     * @return array[] matching records, oldest first
     */
    public function listChanges(array $filter = [])
    {
        $dir = $this->dataDir() . '/queue';
        if (!is_dir($dir)) return [];

        $records = [];
        foreach (glob($dir . '/*.json') as $file) {
            $data = json_decode(io_readFile($file), true);
            if (!is_array($data)) continue;
            foreach ($filter as $key => $value) {
                if (($data[$key] ?? null) !== $value) continue 2;
            }
            $records[] = $data;
        }

        usort($records, static function ($a, $b) {
            return $a['created'] <=> $b['created'];
        });
        return $records;
    }

    /**
     * $conf['savedir'] is intentionally left as DokuWiki's raw, often
     * relative config value (e.g. './data') - core only ever resolves it
     * to an absolute path indirectly, for the *derived* paths like
     * $conf['datadir'], via init_path()/fullpath() in inc/init.php. Since
     * PHP resolves relative paths against the current script's directory
     * (different for doku.php vs. lib/exe/jsonrpc.php vs. a plugin's own
     * entry script), using $conf['savedir'] directly here would silently
     * write into the wrong place depending on which entry point triggered
     * the save. dirname() of the already-absolute datadir is the same
     * savedir DokuWiki itself resolved, so this matches core's behaviour
     * regardless of caller.
     */
    public function dataDir()
    {
        global $conf;
        return dirname($conf['datadir']) . '/reviewqueue';
    }

    /**
     * @param int $id
     * @param string $ext 'json' or 'content'
     * @return string|null the queue path, or the archive path if it's no
     *                      longer pending; null if neither exists
     */
    protected function fileFor($id, $ext)
    {
        $queued = $this->queueFile($id, $ext);
        if (file_exists($queued)) return $queued;
        $archived = $this->archiveFile($id, $ext);
        return file_exists($archived) ? $archived : null;
    }

    protected function queueFile($id, $ext)
    {
        return $this->dataDir() . "/queue/$id.$ext";
    }

    protected function archiveFile($id, $ext)
    {
        return $this->dataDir() . "/archive/$id.$ext";
    }

    protected function ensureDirs()
    {
        global $conf;
        foreach (['queue', 'archive'] as $sub) {
            $dir = $this->dataDir() . '/' . $sub;
            if (is_dir($dir)) continue;
            if (!@mkdir($dir, $conf['dperm'] ?: 0770, true) && !is_dir($dir)) {
                throw new \RuntimeException("reviewqueue: cannot create directory $dir");
            }
        }
    }

    /**
     * Allocate the next pending-change id from data/reviewqueue/seq.
     * Locked via io_lock() so concurrent saves (e.g. two AI edits at once)
     * can't collide - io_saveFile() would re-lock the same path and stall,
     * so this writes the file directly while already holding the lock.
     *
     * @return int
     * @throws \RuntimeException on any storage failure
     */
    protected function nextId()
    {
        $file = $this->dataDir() . '/seq';
        io_lock($file);
        $seq = (int) trim((string) io_readFile($file)) + 1;
        $written = @file_put_contents($file, (string) $seq);
        io_unlock($file);
        if ($written === false) {
            throw new \RuntimeException('reviewqueue: cannot allocate a change id');
        }
        return $seq;
    }
}
