<?php

namespace dokuwiki\plugin\reviewqueue\meta;

use dokuwiki\ErrorHandler;
use dokuwiki\Remote\AccessDeniedException;
use dokuwiki\Remote\JsonRpcServer;
use dokuwiki\Remote\RemoteException;

/**
 * MCP server over the streaming HTTP transport, serving only the tools a
 * confined account may call.
 *
 * Adapted from McpServer in splitbrain/dokuwiki-plugin-mcp (GPL-2, Andreas
 * Gohr). The protocol handling is his; what differs is that this endpoint
 * refuses anything outside helper/capability.php's TOOLS list.
 *
 * The refusal has to happen on tools/call, not just on tools/list. Hiding a
 * tool from the listing only stops a well-behaved client from picking it -
 * a client that names the method anyway would otherwise still reach it,
 * which would make the whole restriction cosmetic.
 *
 * Everything heavy is core's: JsonRpcServer does transport, body parsing and
 * the $conf['remote'] check, and Api::call() does dispatch plus the ACL
 * checks. This class is protocol translation and one allowlist.
 *
 * See docs/design/adr-0007-agent-confinement.md.
 */
class McpServer extends JsonRpcServer
{
    /** @var string|int|null id of the current request, to correlate errors with it */
    protected $requestId = null;

    /** @var \helper_plugin_reviewqueue_capability */
    protected $capability;

    public function __construct()
    {
        parent::__construct();
        $this->capability = plugin_load('helper', 'reviewqueue_capability');
    }

    /** @inheritdoc */
    public function call($methodname, $args)
    {
        switch ($methodname) {
            case 'initialize':
                return $this->mcpInitialize();
            case 'tools/list':
                return ['tools' => (new ToolSchema())->getTools()];
            case 'tools/call':
                return $this->mcpToolsCall($args);
            case 'ping':
                return (object) [];
            default:
                throw new RemoteException(sprintf('Unknown method %s', $methodname), -32601);
        }
    }

    /**
     * @inheritdoc
     * @return array|null null when the request was a notification
     * @throws RemoteException when a request comes without an id
     */
    protected function createResponse($data)
    {
        if (str_starts_with($data['method'] ?? '', 'notifications/')) return null;

        if (!isset($data['id'])) {
            throw new RemoteException('Only a notification may omit the id', -32600);
        }

        $this->requestId = $data['id'];
        return parent::createResponse($data);
    }

    /**
     * Create an error response, correlated with the request it belongs to.
     *
     * An access error only reaches here when nobody is authenticated - a tool
     * call handles a denied user itself - so it challenges the client.
     *
     * @param \Throwable $exception
     * @return array
     */
    public function returnError($exception)
    {
        global $conf;

        if ($exception instanceof AccessDeniedException) {
            http_status(401);
            header('WWW-Authenticate: Bearer realm="' . str_replace(['\\', '"'], '', $conf['title']) . '"');

            $exception = new RemoteException(
                $this->explain($exception->getMessage()),
                $exception->getCode() ?: -32604,
                $exception
            );
        } elseif (http_response_code() === 200) {
            http_status($exception instanceof RemoteException ? 400 : 500);
        }

        $return = parent::returnError($exception);
        $return['jsonrpc'] = '2.0';
        // MCP says omit the id when it isn't available; JSON-RPC would send null
        if ($this->requestId !== null) $return['id'] = $this->requestId;
        return $return;
    }

    /**
     * @link https://modelcontextprotocol.io/specification/2025-03-26/basic/lifecycle#initialization
     * @return array
     */
    protected function mcpInitialize()
    {
        global $conf;
        $info = confToHash(DOKU_PLUGIN . 'reviewqueue/plugin.info.txt');

        return [
            'protocolVersion' => '2025-03-26',
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => 'DokuWiki Review Queue',
                'version' => $info['date'] ?? 'unknown',
            ],
            'instructions' => sprintf(
                "Read and propose changes to the DokuWiki instance called '%s'. Changes you " .
                "submit are held for human review and do not go live until approved. %s",
                $conf['title'],
                $this->authStatus()
            ),
        ];
    }

    /**
     * @link https://modelcontextprotocol.io/specification/2025-03-26/server/tools#calling-tools
     * @param array $args
     * @return array
     * @throws AccessDeniedException when no user is authenticated
     * @throws RemoteException when the tool does not exist or is not allowed
     */
    protected function mcpToolsCall($args)
    {
        global $INPUT;
        $name = $args['name'] ?? '';

        // Tool names carry underscores where the method name has dots.
        $method = str_replace('_', '.', $name);

        // Checked before the method registry, so a method that exists but is
        // not allowed is indistinguishable from one that does not exist. There
        // is no reason to tell a caller about tools it may not have.
        if (!$this->capability->mayUseTool($method) || !isset($this->remote->getMethods()[$method])) {
            throw new RemoteException(sprintf('There is no tool called %s', $name), -32602);
        }

        try {
            $result = $this->remote->call($method, $args['arguments'] ?? []);
        } catch (AccessDeniedException | RemoteException $e) {
            // Missing credentials are for the client to fix; anything else the
            // API deliberately reported is something the model can see and work
            // around, so it comes back as a tool result rather than a protocol
            // error.
            if ($e instanceof AccessDeniedException && $INPUT->server->str('REMOTE_USER') === '') throw $e;
            return $this->mcpToolResult($this->explain($e->getMessage()), true);
        } catch (\Throwable $e) {
            // Anything else is a genuine server fault - a TypeError, a fatal in
            // a helper. Reporting it as a tool result would hand the model
            // internal detail (class names, paths) and leave no trace in the
            // error log for whoever has to fix it, so log it and let mcp.php's
            // handler turn it into a 500.
            ErrorHandler::logException($e);
            throw $e;
        }

        return $this->mcpToolResult($result);
    }

    /**
     * @param mixed $value what the tool returned, or why it failed
     * @param bool $isError
     * @return array
     */
    protected function mcpToolResult($value, $isError = false)
    {
        // MCP only supports text, image and audio; complex types go back as JSON.
        return [
            'content' => [[
                'type' => 'text',
                'text' => is_scalar($value) ? (string) $value : json_encode($value, JSON_PRETTY_PRINT),
            ]],
            'isError' => $isError,
        ];
    }

    /**
     * Describe the authentication state of this request.
     *
     * DokuWiki authenticates while initialising, long before any MCP method is
     * dispatched; this only reports the outcome, so a caller can tell
     * "credentials rejected" from "no credentials sent".
     *
     * @return string
     */
    public function authStatus()
    {
        global $INPUT;

        $user = $INPUT->server->str('REMOTE_USER');
        if ($user !== '') return sprintf("You are authenticated as '%s'.", $user);

        $header = '';
        if ($INPUT->server->str('HTTP_AUTHORIZATION') !== '') $header = 'Authorization';
        if ($INPUT->server->str('HTTP_X_DOKUWIKI_TOKEN') !== '') $header = 'X-DOKUWIKI-TOKEN';

        if ($header !== '') {
            return sprintf(
                'The credentials sent in the %s header were not accepted. Any tool call that needs ' .
                'a user will fail.',
                $header
            );
        }

        return 'No API token was sent and no other credentials were accepted. Any tool call that ' .
            'needs a user will fail. Send a token in either an "Authorization: Bearer <token>" or ' .
            'an "X-DOKUWIKI-TOKEN: <token>" header.';
    }

    /**
     * Amend a failure with the authentication state when that may be the cause.
     *
     * @param string $message
     * @return string
     */
    protected function explain($message)
    {
        global $INPUT;

        if ($INPUT->server->str('REMOTE_USER') !== '') return $message;
        return rtrim($message, '.') . '. ' . $this->authStatus();
    }
}
