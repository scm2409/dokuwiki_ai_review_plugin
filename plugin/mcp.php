<?php

/**
 * MCP endpoint for the review queue.
 *
 * The agent connects here instead of to splitbrain/dokuwiki-plugin-mcp, which
 * serves every registered remote method - 53 tools on a production wiki,
 * including page history and user management. This one serves only what
 * helper/capability.php allows.
 *
 * Adapted from mcp.php in splitbrain/dokuwiki-plugin-mcp (GPL-2, Andreas Gohr).
 *
 * See docs/design/adr-0007-agent-confinement.md.
 */

use dokuwiki\ErrorHandler;
use dokuwiki\plugin\reviewqueue\meta\McpServer;

if (!defined('DOKU_INC')) define('DOKU_INC', __DIR__ . '/../../../');

require_once(DOKU_INC . 'inc/init.php');
session_write_close();

// This file stays web-reachable even when the plugin is disabled, and every
// tool decision here depends on helper/capability.php. Without the helper the
// allowlist cannot be consulted at all, so refuse rather than fatal on the
// first null dereference - the fail-closed direction, and a legible answer for
// whoever is looking at a client that suddenly stopped working.
if (!plugin_load('helper', 'reviewqueue_capability')) {
    http_status(503);
    header('Content-Type: application/json');
    echo json_encode([
        'jsonrpc' => '2.0',
        'error' => [
            'code' => -32603,
            'message' => 'The reviewqueue plugin is not enabled, so this endpoint cannot serve tools.',
        ],
    ], JSON_THROW_ON_ERROR);
    exit;
}

$server = new McpServer();

try {
    $result = $server->serve();
} catch (\Throwable $e) {
    ErrorHandler::logException($e);
    $result = $server->returnError($e);
}

if ($result === null) {
    // a notification has no response, it is only acknowledged with an empty 202
    header_remove('Content-Type');
    ini_set('default_mimetype', '');
    http_status(202);
    echo '';
} else {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
}
