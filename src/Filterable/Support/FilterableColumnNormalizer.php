<?php

namespace OnaOnbir\OOWeaveReplace\Core\Filterable\Support;

class FilterableColumnNormalizer
{
    public static function flatten(array $columns): array
    {
        return array_values(array_filter($columns));
    }

    public static function toSelectOptions(array $columns, string $prefix = ''): array
    {
        $results = [];

        foreach ($columns as $column) {
            if (! isset($column['columnKey'])) {
                continue;
            }

            $fullKey = $prefix ? "{$prefix}.{$column['columnKey']}" : $column['columnKey'];

            $results[] = [
                'value' => $fullKey,
                'label' => $column['label'] ?? $fullKey,
            ];

            if (isset($column['inner']) && is_array($column['inner'])) {
                $results = array_merge(
                    $results,
                    self::toSelectOptions($column['inner'], $fullKey)
                );
            }
        }

        return $results;
    }
}
