<?php

namespace Timewave\Logger\Classes;

class AttributeValue
{
    /**
     * Stringify a log-context value for an OTLP stringValue attribute.
     * Scalars, __toString objects and null cast directly; arrays and other
     * objects are JSON-encoded so their content survives in the logs. Any
     * unencodable leaf is nulled rather than losing the whole value
     * (JSON_PARTIAL_OUTPUT_ON_ERROR, matching Logger::toJson). The false-guard
     * is a defensive net: json_encode is typed string|false, but with this flag
     * it never actually returns false, so the placeholder is effectively
     * unreachable — it just keeps the : string return type honest.
     */
    public static function stringify($value): string
    {
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString')) || is_null($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR, 128);

        return $encoded === false ? 'Non-stringeable value' : $encoded;
    }
}
