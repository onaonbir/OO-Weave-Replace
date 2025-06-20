<?php

namespace OnaOnbir\OOWeaveReplace\Core;

use Illuminate\Support\Arr;

class RuleMatcher
{
    public static function matches(array $rules, array $context): bool
    {
        $overallMatch = false;
        $isFirst = true;

        foreach ($rules as $rule) {
            $columnKey = $rule['columnKey'] ?? null;
            $operator = $rule['operator'] ?? null;
            $value = $rule['value'] ?? null;
            $type = strtolower($rule['type'] ?? 'and');

            if (! is_string($columnKey) || ! is_string($operator)) {
                continue;
            }

            // Normalize value if needed
            if (in_array($operator, ['in', 'not_in']) && ! is_array($value)) {
                $value = [$value];
            }

            $values = self::extractWildcardValues($context, explode('.', $columnKey));

            // Eğer hiç değer yoksa bile, yine de evaluate edilmesini sağlayabiliriz (opsiyonel)
            $values = empty($values) ? [null] : $values;

            $matched = false;
            foreach ($values as $item) {
                if (self::evaluate($item, $operator, $value)) {
                    $matched = true;
                    break;
                }
            }

            // AND/OR tipiyle tüm sonucu güncelle
            if ($isFirst) {
                $overallMatch = $matched;
                $isFirst = false;
            } else {
                $overallMatch = ($type === 'and') ? ($overallMatch && $matched) : ($overallMatch || $matched);
            }
        }

        return $overallMatch;
    }

    protected static function evaluate($columnData, $operator, $value): bool
    {
        // Enum -> value
        if (is_object($columnData) && enum_exists(get_class($columnData))) {
            $columnData = $columnData->value;
        }
        if (is_object($value) && enum_exists(get_class($value))) {
            $value = $value->value;
        }

        return match ($operator) {
            '=' => $columnData == $value,
            '!=' => $columnData != $value,
            '>' => $columnData > $value,
            '<' => $columnData < $value,
            '>=' => $columnData >= $value,
            '<=' => $columnData <= $value,
            'in' => in_array($columnData, (array) $value),
            'not_in' => ! in_array($columnData, (array) $value),
            default => false,
        };
    }

    protected static function extractWildcardValues(array $data, array $keys): array
    {
        $results = [];
        $flatKey = implode('.', $keys);

        // WILDCARD DESTEKLİ FLAT KEY EŞLEŞME
        foreach ($data as $k => $v) {
            if (! is_string($k)) {
                continue;
            }

            $pattern = str_replace('\*', '\d+', preg_quote($flatKey));
            if (preg_match('/^'.$pattern.'$/', $k)) {
                $results[] = $v;
            }
        }

        // Eğer yukarıda eşleşmediyse, recursive fallback
        if (! empty($results)) {
            return $results;
        }

        // Eski fallback sistem (nested için)
        $currentKey = array_shift($keys);

        if ($currentKey === '*') {
            if (! is_array($data)) {
                return [];
            }

            $values = [];
            foreach ($data as $item) {
                $values = array_merge($values, self::extractWildcardValues((array) $item, $keys));
            }

            return $values;
        }

        $value = Arr::get($data, $currentKey);

        if (empty($keys)) {
            return isset($value) ? [$value] : [];
        }

        return self::extractWildcardValues((array) $value, $keys);
    }

    public static function matchPaths(array $rules, array $context): array
    {
        $matches = [];

        foreach ($rules as $rule) {
            $columnKey = $rule['columnKey'] ?? null;
            $operator = $rule['operator'] ?? null;
            $value = $rule['value'] ?? null;

            if (! is_string($columnKey) || ! is_string($operator)) {
                continue;
            }

            // wildcardlı key: r_users.*.username
            $pattern = str_replace('\*', '\d+', preg_quote($columnKey));

            foreach ($context as $flatKey => $itemValue) {
                if (preg_match('/^'.$pattern.'$/', $flatKey)) {
                    if (self::evaluate($itemValue, $operator, $value)) {
                        $matches[] = [
                            'path' => $flatKey,
                            'value' => $itemValue,
                            'rule' => $rule,
                        ];
                    }
                }
            }
        }

        return $matches;
    }
}
