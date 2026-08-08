<?php

use dokuwiki\Extension\CLIPlugin;
use splitbrain\phpcli\Options;

/**
 * Maintenance commands for the review queue, runnable without a browser:
 *
 *   php bin/plugin.php reviewqueue list
 *   php bin/plugin.php reviewqueue show 42
 *   php bin/plugin.php reviewqueue expire
 *
 * Deliberately read-only plus expiry. Approving from the command line would
 * bypass the reviewer identity that the whole design rests on - a decision
 * needs a person attached to it, so it stays in the web UI.
 */
class cli_plugin_reviewqueue extends CLIPlugin
{
    protected function setup(Options $options)
    {
        $options->setHelp(
            "Inspect and maintain the review queue.\n\n" .
            'Approving and rejecting are intentionally not available here: a ' .
            'decision must be attributable to a reviewer, so it belongs in the ' .
            'admin interface.'
        );

        $options->registerCommand('list', 'List changes currently awaiting review');
        $options->registerCommand('show', 'Show one change including its proposed text');
        $options->registerOption('id', 'Change id to show', null, 'id', 'show');
        $options->registerCommand(
            'expire',
            'Archive changes older than the configured max_pending_age (no-op when that is 0)'
        );
    }

    protected function main(Options $options)
    {
        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');

        switch ($options->getCmd()) {
            case 'list':
                $this->cmdList($store);
                break;
            case 'show':
                $this->cmdShow($store, (int) $options->getOpt('id', $options->getArgs()[0] ?? 0));
                break;
            case 'expire':
                $days = (int) $this->getConf('max_pending_age');
                if ($days <= 0) {
                    $this->info('max_pending_age is 0, nothing expires.');
                    break;
                }
                $this->success(sprintf('Archived %d expired change(s).', $store->expireOlderThan($days)));
                break;
            default:
                echo $options->help();
        }
    }

    protected function cmdList(helper_plugin_reviewqueue_store $store)
    {
        $records = $store->listChanges();
        $open = array_filter($records, static fn($r) => in_array($r['state'], ['pending', 'conflicted'], true));

        if (!$open) {
            $this->info('The review queue is empty.');
            return;
        }

        $table = new \splitbrain\phpcli\TableFormatter($this->colors);
        echo $table->format(
            [6, 10, 12, 30, '*'],
            ['ID', 'STATE', 'AUTHOR', 'TARGET', 'SUMMARY']
        );
        foreach ($open as $r) {
            echo $table->format(
                [6, 10, 12, 30, '*'],
                ['#' . $r['id'], $r['state'], $r['author'], $r['target'], (string) $r['summary']]
            );
        }
    }

    protected function cmdShow(helper_plugin_reviewqueue_store $store, $id)
    {
        $record = $store->get($id);
        if (!$record) {
            $this->fatal("No change #$id");
        }

        foreach (['id', 'type', 'target', 'author', 'summary', 'state', 'origin', 'reviewer', 'comment'] as $key) {
            $this->info(sprintf('%-10s %s', $key, var_export($record[$key] ?? null, true)));
        }

        if ($record['type'] === 'page') {
            echo "\n" . $store->getContent($id) . "\n";
        } else {
            $path = $store->mediaPath($id);
            $this->info('stored at ' . ($path ?? '(missing)'));
        }
    }
}
