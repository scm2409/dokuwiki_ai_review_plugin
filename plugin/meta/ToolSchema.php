<?php

namespace dokuwiki\plugin\reviewqueue\meta;

use dokuwiki\Remote\ApiCall;
use dokuwiki\Remote\OpenApiDoc\OpenAPIGenerator;

/**
 * Builds the MCP tool list from the remote API, restricted to what a confined
 * account may call.
 *
 * Adapted from SchemaGenerator in splitbrain/dokuwiki-plugin-mcp (GPL-2,
 * Andreas Gohr), which does the same job without a restriction. The two
 * differences are the point of this class:
 *
 * 1. Only methods on helper/capability.php's TOOLS list are emitted. The
 *    upstream plugin exposes every registered remote method, which on a
 *    production wiki is 53 tools - most of them irrelevant to this agent, all
 *    of them costing context on every request, and several of them (page
 *    history, user management) things a confined account must not have.
 *    Default-deny also means a plugin installed next year never shows up.
 *
 * 2. An array schema always carries "items". Core's
 *    OpenAPIGenerator::typeToSchema() adds "items" only when
 *    Type::getSubType() is non-null, and that is only true for a "foo[]"
 *    docblock - a bare "array" produces {"type":"array"} with nothing else.
 *    Google's Gemini API rejects the *entire* GenerateContentRequest over such
 *    a schema, so one bad tool takes the whole tool list down with it;
 *    Anthropic and OpenAI do not validate it, which is why this stays
 *    invisible until a client switches model routes.
 *
 * See docs/design/adr-0007-agent-confinement.md.
 */
class ToolSchema extends OpenAPIGenerator
{
    /** @var \helper_plugin_reviewqueue_capability */
    protected $capability;

    public function __construct()
    {
        parent::__construct();
        $this->capability = plugin_load('helper', 'reviewqueue_capability');
    }

    /**
     * The tools a confined account may call, as MCP tool definitions.
     *
     * @return array
     */
    public function getTools()
    {
        $tools = [];

        $nullSchema = [
            'type' => 'object',
            'properties' => (object) [],
            'required' => [],
        ];

        foreach ($this->api->getMethods() as $method => $call) {
            if (!$this->capability->mayUseTool($method)) continue;

            $args = $call->getArgs();
            $schema = $args ? $this->getMethodArguments($args)['schema'] : $nullSchema;

            $tools[] = [
                // Some LLMs don't allow dots in tool names, so they become underscores.
                'name' => str_replace('.', '_', $method),
                'description' => $this->describe($method, $call),
                'inputSchema' => $this->repair($schema),
                'annotations' => $this->getAnnotations($method, $call),
            ];
        }

        return $tools;
    }

    /**
     * Enforce the invariants a tool schema has to satisfy, recursively.
     *
     * Applied to whatever core's generator produced rather than to individual
     * known-bad methods, so a docblock written next year is covered too.
     *
     * @param array $schema
     * @return array
     */
    protected function repair(array $schema)
    {
        // An array must say what it contains. Where the docblock gave no
        // element type there is nothing to derive one from, so fall back to
        // string and say so in the description rather than emit a schema that
        // makes the whole tool list unusable.
        if (($schema['type'] ?? '') === 'array' && !isset($schema['items'])) {
            $schema['items'] = ['type' => 'string'];
            $schema['description'] = trim(
                ($schema['description'] ?? '') .
                ' (element type not declared in the API docblock; assumed string)'
            );
        }

        foreach (['properties', 'items'] as $key) {
            if (!isset($schema[$key]) || !is_array($schema[$key])) continue;

            if ($key === 'items') {
                $schema[$key] = $this->repair($schema[$key]);
                continue;
            }

            foreach ($schema[$key] as $name => $sub) {
                if (is_array($sub)) $schema[$key][$name] = $this->repair($sub);
            }
        }

        return $schema;
    }

    /**
     * A tool with no description is nearly unusable for a model picking
     * between tools, so fall back to the summary and finally to the method
     * name rather than emit an empty string.
     *
     * @param string $method
     * @param ApiCall $call
     * @return string
     */
    protected function describe($method, ApiCall $call)
    {
        $description = trim((string) $call->getDescription());
        if ($description !== '') return $description;

        $summary = trim((string) $call->getSummary());
        if ($summary !== '') return $summary;

        return sprintf('Calls the %s API method.', $method);
    }

    /**
     * MCP annotations describing how safe a call is.
     *
     * Unlike upstream's list, which only knows core method names, this one is
     * driven by what the method actually does here: every write a confined
     * account can perform goes into the review queue, so nothing it may call
     * is ever destructive to live content.
     *
     * @param string $method
     * @param ApiCall $call
     * @return array
     */
    protected function getAnnotations($method, ApiCall $call)
    {
        $name = substr($method, (int) strrpos($method, '.') + 1);
        $summary = trim((string) $call->getSummary());

        $readOnly = !in_array($name, [
            'saveMedia', 'deleteMedia',
            'updatePendingChange', 'withdrawPendingChange',
            'replaceSection', 'insertSection', 'deleteSection',
            'replaceLines', 'replaceText',
        ], true);

        return [
            'title' => $summary !== '' ? $summary : str_replace('.', '_', $method),
            'readOnlyHint' => $readOnly,
            // Nothing a confined account writes reaches the live wiki without a
            // reviewer approving it first, so no call it may make is destructive.
            'destructiveHint' => false,
        ];
    }
}
