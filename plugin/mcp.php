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
