<?php

namespace App\Services\Mcp;

use Carbon\Carbon;

/**
 * One MCP tool.
 *
 *  READ   - changes nothing.
 *  WRITE  - creates or updates something; reversible by another update.
 *  DANGER - hard to undo or touches money / many people: needs `confirm: true` (the AI must show the user what will
 *           happen first - the refusal text carries a plain-language preview).
 */
final class Tool
{
    public const READ = 'read';
    public const WRITE = 'write';
    public const DANGER = 'danger';

    /**
     * @param array<string,array> $properties JSON-schema properties
     * @param string[]            $required
     * @param \Closure(array, ToolContext): mixed $run
     * @param null|\Closure(array, ToolContext): string $preview plain-language "what will happen" for DANGER tools
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public array $properties,
        public array $required,
        public string $kind,
        public \Closure $run,
        public ?\Closure $preview = null,
    ) {
    }

    public function inputSchema(): array
    {
        $props = $this->properties;
        if ($this->kind === self::DANGER) {
            $props['confirm'] = ['type' => 'boolean', 'description' => 'Must be true. Call once WITHOUT it to get a preview, show that to the user in plain words, and only repeat with confirm=true after they clearly agree.'];
        }

        $schema = ['type' => 'object', 'properties' => $props === [] ? new \stdClass() : $props];
        if ($this->required) {
            $schema['required'] = array_values($this->required);
        }

        return $schema;
    }

    public function annotations(): array
    {
        return [
            'title' => $this->title,
            'readOnlyHint' => $this->kind === self::READ,
            'destructiveHint' => $this->kind === self::DANGER,
            'idempotentHint' => $this->kind === self::READ,
            'openWorldHint' => false,
        ];
    }

    public function definition(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema(),
            'annotations' => $this->annotations(),
        ];
    }

    /** Checks and normalises the arguments the AI sent. Throws a ToolError that says exactly what to fix. */
    public function validate(array $args): array
    {
        $out = [];
        $problems = [];

        foreach ($this->required as $name) {
            if (!array_key_exists($name, $args) || $args[$name] === null || $args[$name] === '') {
                $problems[] = "'{$name}' is required";
            }
        }

        foreach ($this->properties as $name => $def) {
            if (!array_key_exists($name, $args) || $args[$name] === null) {
                continue;
            }
            try {
                $out[$name] = self::coerce($name, $args[$name], $def);
            } catch (\InvalidArgumentException $e) {
                $problems[] = $e->getMessage();
            }
        }

        if ($this->kind === self::DANGER) {
            $c = $args['confirm'] ?? false;
            $out['confirm'] = $c === true || $c === 'true' || $c === 1 || $c === '1';
        }

        if ($problems) {
            throw new ToolError('Invalid arguments: ' . implode('; ', $problems) . '.');
        }

        return $out;
    }

    private static function coerce(string $name, mixed $v, array $def): mixed
    {
        $type = $def['type'] ?? 'string';

        switch ($type) {
            case 'integer':
                if (is_string($v) && preg_match('/^-?\d+$/', trim($v))) {
                    $v = (int) $v;
                }
                if (!is_int($v) && !(is_float($v) && floor($v) == $v)) {
                    throw new \InvalidArgumentException("'{$name}' must be a whole number");
                }
                $v = (int) $v;
                if (isset($def['minimum']) && $v < $def['minimum']) {
                    throw new \InvalidArgumentException("'{$name}' must be at least {$def['minimum']}");
                }
                if (isset($def['maximum']) && $v > $def['maximum']) {
                    throw new \InvalidArgumentException("'{$name}' must be at most {$def['maximum']}");
                }

                return $v;

            case 'boolean':
                if (is_bool($v)) {
                    return $v;
                }
                if (in_array($v, ['true', '1', 1], true)) {
                    return true;
                }
                if (in_array($v, ['false', '0', 0], true)) {
                    return false;
                }
                throw new \InvalidArgumentException("'{$name}' must be true or false");

            case 'array':
                if (!is_array($v) || !array_is_list($v)) {
                    throw new \InvalidArgumentException("'{$name}' must be a list");
                }
                $itemType = $def['items']['type'] ?? null;
                if ($itemType === 'integer') {
                    foreach ($v as $i => $item) {
                        if (is_string($item) && preg_match('/^\d+$/', $item)) {
                            $item = (int) $item;
                        }
                        if (!is_int($item)) {
                            throw new \InvalidArgumentException("'{$name}' must be a list of whole numbers");
                        }
                        $v[$i] = $item;
                    }
                }
                if (isset($def['maxItems']) && count($v) > $def['maxItems']) {
                    throw new \InvalidArgumentException("'{$name}' can have at most {$def['maxItems']} items");
                }

                return $v;

            case 'object':
                if (!is_array($v) || ($v !== [] && array_is_list($v))) {
                    throw new \InvalidArgumentException("'{$name}' must be an object");
                }

                return $v;

            default: // string
                if (is_int($v) || is_float($v)) {
                    $v = (string) $v;
                }
                if (!is_string($v)) {
                    throw new \InvalidArgumentException("'{$name}' must be text");
                }
                $v = trim($v);
                if (isset($def['enum']) && !in_array($v, $def['enum'], true)) {
                    // be forgiving about case
                    $hit = null;
                    foreach ($def['enum'] as $opt) {
                        if (strcasecmp($opt, $v) === 0) {
                            $hit = $opt;
                        }
                    }
                    if ($hit === null) {
                        throw new \InvalidArgumentException("'{$name}' must be one of: " . implode(', ', $def['enum']));
                    }
                    $v = $hit;
                }
                if (($def['format'] ?? null) === 'date') {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                        throw new \InvalidArgumentException("'{$name}' must be a date like 2026-10-31");
                    }
                    try {
                        Carbon::createFromFormat('Y-m-d', $v);
                    } catch (\Throwable $e) {
                        throw new \InvalidArgumentException("'{$name}' is not a real date");
                    }
                }
                if (isset($def['maxLength']) && mb_strlen($v) > $def['maxLength']) {
                    throw new \InvalidArgumentException("'{$name}' is too long (max {$def['maxLength']} characters)");
                }

                return $v;
        }
    }
}
