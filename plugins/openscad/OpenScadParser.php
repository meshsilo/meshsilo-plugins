<?php
/**
 * OpenSCAD Parameter Parser
 *
 * Parses OpenSCAD Customizer parameters from .scad files.
 * Supports the standard Customizer syntax:
 *   variable = value; // [min:step:max] or [option1, option2] or description
 *
 * Tab/section headers use: slash-star [Tab Name] star-slash
 */
class OpenScadParser
{
    /**
     * Parse a .scad file and extract customizer parameters.
     *
     * @param string $filePath Path to the .scad file
     * @return array ['parameters' => [...], 'sections' => [...], 'source' => string]
     */
    public static function parse(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return ['parameters' => [], 'sections' => [], 'source' => ''];
        }

        $source = file_get_contents($filePath);
        $lines = explode("\n", $source);
        $parameters = [];
        $sections = [];
        $currentSection = 'General';

        foreach ($lines as $lineNum => $line) {
            $trimmed = trim($line);

            // Check for section/tab header: /* [Section Name] */
            if (preg_match('#^/\*\s*\[(.+?)\]\s*\*/$#', $trimmed, $m)) {
                $currentSection = trim($m[1]);
                if (!in_array($currentSection, $sections)) {
                    $sections[] = $currentSection;
                }
                continue;
            }

            // Skip comments, includes, uses, modules, functions
            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')
                || str_starts_with($trimmed, 'module ') || str_starts_with($trimmed, 'function ')
                || str_starts_with($trimmed, 'use ') || str_starts_with($trimmed, 'include ')) {
                continue;
            }

            // Match: variable = value; // comment
            if (preg_match('/^(\w+)\s*=\s*(.+?)\s*;\s*(\/\/\s*(.*))?$/', $trimmed, $m)) {
                $name = $m[1];
                $rawValue = trim($m[2]);
                $comment = trim($m[4] ?? '');

                $param = [
                    'name' => $name,
                    'raw_value' => $rawValue,
                    'value' => self::parseValue($rawValue),
                    'type' => self::detectType($rawValue, $comment),
                    'section' => $currentSection,
                    'line' => $lineNum + 1,
                    'description' => '',
                    'options' => null,
                    'min' => null,
                    'max' => null,
                    'step' => null,
                ];

                // Parse the comment for constraints
                if ($comment !== '') {
                    $param = array_merge($param, self::parseComment($comment, $param['type']));
                }

                $parameters[$name] = $param;
            }
        }

        if (empty($sections)) {
            $sections[] = 'General';
        }

        return [
            'parameters' => $parameters,
            'sections' => $sections,
            'source' => $source,
        ];
    }

    /**
     * Parse a raw OpenSCAD value into a PHP value.
     */
    private static function parseValue(string $raw): mixed
    {
        // Boolean
        if ($raw === 'true') return true;
        if ($raw === 'false') return false;

        // String (quoted)
        if (preg_match('/^"(.*)"$/', $raw, $m)) return $m[1];

        // Number (int or float)
        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float)$raw : (int)$raw;
        }

        // Vector [x, y, z]
        if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
            $inner = substr($raw, 1, -1);
            return array_map('trim', explode(',', $inner));
        }

        return $raw;
    }

    /**
     * Detect the parameter type from its value and comment.
     */
    private static function detectType(string $rawValue, string $comment): string
    {
        // Boolean
        if ($rawValue === 'true' || $rawValue === 'false') return 'checkbox';

        // String
        if (preg_match('/^".*"$/', $rawValue)) {
            // Check if comment has dropdown options [opt1, opt2]
            if (preg_match('/^\[([^:]+,[^:]+)\]/', $comment)) return 'select';
            return 'text';
        }

        // Number
        if (is_numeric($rawValue)) {
            // Check for slider [min:step:max] or [min:max]
            if (preg_match('/^\[\s*[\d.\-]+\s*:\s*[\d.\-]+/', $comment)) return 'slider';
            return 'number';
        }

        // Vector
        if (str_starts_with($rawValue, '[')) return 'vector';

        return 'text';
    }

    /**
     * Parse a comment for constraints, options, or description.
     */
    private static function parseComment(string $comment, string $type): array
    {
        $result = ['description' => $comment];

        // Dropdown: [option1, option2, option3]
        if (preg_match('/^\[([^\]:]+(?:,[^\]:]+)+)\]\s*(.*)$/', $comment, $m)) {
            $options = array_map('trim', explode(',', $m[1]));
            // Remove quotes from options
            $options = array_map(function ($o) {
                return trim($o, '" ');
            }, $options);
            $result['options'] = $options;
            $result['description'] = trim($m[2] ?? '');
            return $result;
        }

        // Slider: [min:step:max] or [min:max]
        if (preg_match('/^\[\s*([\d.\-]+)\s*:\s*([\d.\-]+)\s*(?::\s*([\d.\-]+))?\s*\]\s*(.*)$/', $comment, $m)) {
            if (isset($m[3]) && $m[3] !== '') {
                // [min:step:max]
                $result['min'] = (float)$m[1];
                $result['step'] = (float)$m[2];
                $result['max'] = (float)$m[3];
            } else {
                // [min:max]
                $result['min'] = (float)$m[1];
                $result['max'] = (float)$m[2];
            }
            $result['description'] = trim($m[4] ?? '');
            return $result;
        }

        return $result;
    }

    /**
     * Generate OpenSCAD command-line -D arguments from parameter overrides.
     *
     * @param array $parameters Original parsed parameters
     * @param array $overrides Key => value overrides from form POST
     * @return array Array of -D arguments
     */
    public static function buildOverrides(array $parameters, array $overrides): array
    {
        $args = [];

        foreach ($overrides as $name => $value) {
            if (!isset($parameters[$name])) continue;

            $param = $parameters[$name];
            $type = $param['type'];

            switch ($type) {
                case 'checkbox':
                    $args[] = '-D';
                    $args[] = "$name=" . ($value ? 'true' : 'false');
                    break;

                case 'text':
                case 'select':
                    $escaped = str_replace('"', '\\"', $value);
                    $args[] = '-D';
                    $args[] = "$name=\"$escaped\"";
                    break;

                case 'vector':
                    if (is_array($value)) {
                        $args[] = '-D';
                        $args[] = "$name=[" . implode(',', $value) . "]";
                    }
                    break;

                default: // number, slider
                    if (is_numeric($value)) {
                        $args[] = '-D';
                        $args[] = "$name=$value";
                    }
                    break;
            }
        }

        return $args;
    }
}
