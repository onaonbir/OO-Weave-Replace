<?php

namespace OnaOnbir\OOWeaveReplace\Core;

use OnaOnbir\OOWeaveReplace\Core;

class DataProcessor
{
    public static function extractContext(mixed $model, array $filterableColumns): array
    {
        return ContextExtractor::extract($model, $filterableColumns);
    }

    public static function replace(array|string $template, array $context): mixed
    {
        return DynamicReplacer::replace($template, $context);
    }

    public static function match(array|Rule $rules, array|object $context): bool
    {
        $contextArray = is_array($context) ? $context : ContextExtractor::extract($context, []);
        $ruleArray = $rules instanceof Rule ? $rules->get() : $rules;

        return RuleMatcher::matches($ruleArray, $contextArray);
    }
}
