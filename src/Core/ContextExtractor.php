<?php

namespace OnaOnbir\OOWeaveReplace\Core;

class ContextExtractor
{
    public static function extract(mixed $model, array $filterableColumns): array
    {
        return self::processColumns($model, $filterableColumns);
    }

    protected static function processColumns($model, array $columnTypes, string $prefix = ''): array
    {
        $results = [];

        foreach ($columnTypes as $column) {
            if (! isset($column['columnType'], $column['columnName'], $column['columnKey'])) {
                continue;
            }

            $fullKey = $prefix ? "{$prefix}.{$column['columnKey']}" : $column['columnKey'];

            if ($column['columnType'] == 'enum') {
                $enumValue = $model->{$column['columnName']};
                $results[$fullKey] = is_object($enumValue)
                    ? $enumValue->value
                    : $enumValue;
            }

            if (in_array($column['columnType'], ['text', 'datetime'])) {
                $results[$fullKey] = $model->{$column['columnName']};
            }

            if ($column['columnType'] === 'json') {
                $jsonData = $model->{$column['columnName']};
                $decoded = is_array($jsonData) ? $jsonData : (json_decode($jsonData, true) ?? []);

                $flattened = self::dotFlatten($decoded, $fullKey);
                $results = array_merge($results, $flattened);

            }

            if (str_starts_with($column['columnType'], 'relation_')) {
                $relationData = $model->{$column['columnName']};

                if (in_array($column['columnType'], ['relation_belongsTo', 'relation_hasOne']) && $relationData) {
                    $results = array_merge(
                        $results,
                        self::processColumns($relationData, $column['inner'] ?? [], $fullKey)
                    );
                }

                if ($column['columnType'] === 'relation_hasMany' && $relationData) {
                    foreach ($relationData as $index => $relatedItem) {
                        $indexedPrefix = "{$fullKey}.{$index}";
                        $results = array_merge(
                            $results,
                            self::processColumns($relatedItem, $column['inner'] ?? [], $indexedPrefix)
                        );
                    }
                }
            }
        }

        return $results;
    }

    protected static function dotFlatten(array $array, string $prefix = ''): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            $dotKey = $prefix.'.'.$key;

            if (is_array($value) && ! array_is_list($value)) {
                $results += self::dotFlatten($value, $dotKey);
            } else {
                $results[$dotKey] = $value;
            }
        }

        return $results;
    }

    public static function flattenFilterableColumnsKeyValue(array $columns, string $prefixLabel = '', string $prefixKey = ''): array
    {
        $result = [];

        foreach ($columns as $column) {
            if (!$column) continue;

            $label = trim($prefixLabel . ($column['label'] ?? $column['columnKey']));
            $columnKey = trim($prefixKey . ($column['columnKey'] ?? ''));

            if (isset($column['inner']) && is_array($column['inner'])) {
                if ($column['columnType'] === 'relation_hasMany') {
                    $columnKey .= '.*';
                }

                $result = array_merge($result, self::flattenFilterableColumnsKeyValue($column['inner'], $label . ' › ', $columnKey . '.'));
            } else {
                $result[] = [
                    'label' => $label,
                    'value' => rtrim($columnKey, '.'),
                ];
            }
        }

        return $result;
    }
}
