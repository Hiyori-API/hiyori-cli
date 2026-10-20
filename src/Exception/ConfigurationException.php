<?php

namespace Hiyori\Exception;

/**
 * A configuration file (or a config array handed to a factory) is not valid.
 *
 * Configuration is checked where it is read, so the message names the file,
 * the key and the offending value. A misconfiguration that is silently
 * ignored is how a run ends up doing something nobody asked for.
 */
class ConfigurationException extends \InvalidArgumentException
{
    /**
     * A short, printable rendering of a config value.
     *
     * Long values are truncated: the point of the message is to name the key
     * and the value, not to dump a whole config array into the terminal.
     */
    public static function describe(mixed $value): string
    {
        if (is_string($value)) {
            $short = mb_strlen($value) > 40 ? mb_substr($value, 0, 40) . '…' : $value;

            return sprintf('string("%s")', $short);
        }

        if (is_bool($value)) {
            return $value ? 'bool(true)' : 'bool(false)';
        }

        if (is_null($value)) {
            return 'null';
        }

        if (is_array($value)) {
            return 'array';
        }

        if (is_object($value)) {
            return get_debug_type($value);
        }

        return get_debug_type($value) . '(' . (string) $value . ')';
    }
}
