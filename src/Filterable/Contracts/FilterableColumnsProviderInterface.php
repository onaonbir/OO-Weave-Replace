<?php

namespace OnaOnbir\OOWeaveReplace\Core\Filterable\Contracts;

interface FilterableColumnsProviderInterface
{
    public static function filterableColumns(int $deepLevel = 0, int $currentLevel = 0): array;
}
