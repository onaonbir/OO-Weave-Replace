<?php

namespace OnaOnbir\OOWeaveReplace\Registry;

class FunctionRegistry
{
    protected static array $functions = [];

    public static function register(string $name, callable $callback): void
    {
        static::$functions[$name] = $callback;
    }

    public static function call(string $name, mixed $value, array $options = []): mixed
    {
        if (! isset(static::$functions[$name])) {
            return $value; // fallback: bilinmeyen fonksiyon = input döner
        }

        return call_user_func(static::$functions[$name], $value, $options);
    }

    public static function has(string $name): bool
    {
        return isset(static::$functions[$name]);
    }

    public static function all(): array
    {
        return static::$functions;
    }
}
