<?php

namespace OnaOnbir\OOWeaveReplace\Core;


use OnaOnbir\OOWeaveReplace\Core;

class Rule
{
    protected array $rules = [];

    public static function make(): static
    {
        return new static;
    }

    public function and(string $columnKey, string $operator, mixed $value): static
    {
        $this->rules[] = [
            'columnKey' => $columnKey,
            'operator' => $operator,
            'value' => $value,
            'type' => 'and',
        ];

        return $this;
    }

    public function or(string $columnKey, string $operator, mixed $value): static
    {
        $this->rules[] = [
            'columnKey' => $columnKey,
            'operator' => $operator,
            'value' => $value,
            'type' => 'or',
        ];

        return $this;
    }

    public function get(): array
    {
        return $this->rules;
    }

    public function evaluateAgainst(mixed $model, array $filterableColumns = []): bool
    {
        $context = is_array($model)
            ? $model
            : DataProcessor::extractContext($model, $filterableColumns);

        return RuleMatcher::matches($this->get(), $context);
    }
}
