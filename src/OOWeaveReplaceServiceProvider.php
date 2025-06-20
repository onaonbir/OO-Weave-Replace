<?php

namespace OnaOnbir\OOWeaveReplace;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use OnaOnbir\OOWeaveReplace\Registry\FunctionRegistry;

class OOWeaveReplaceServiceProvider extends ServiceProvider
{
    private string $packageName = 'oo-weave-replace';

    public function boot()
    {
        //
    }

    public function register()
    {
        $this->registerFunctionRegisters();
    }

    private function registerFunctionRegisters(): void
    {

        FunctionRegistry::register('json_encode', fn ($value, $options) => json_encode($value));

        FunctionRegistry::register('implode', function ($value, $options) {
            return is_array($value) ? implode($options['separator'] ?? ',', $value) : (string) $value;
        });

        FunctionRegistry::register('custom_function', fn ($value, $options) => '❗️TODO: örnek');

        FunctionRegistry::register('is_empty', fn ($value) => empty($value));
        FunctionRegistry::register('is_numeric', fn ($value) => is_numeric($value));
        FunctionRegistry::register('is_array', fn ($value) => is_array($value));

        // HASH
        FunctionRegistry::register('md5', fn ($value) => md5($value));
        FunctionRegistry::register('sha1', fn ($value) => sha1($value));

        FunctionRegistry::register('uuid', fn () => (string) \Illuminate\Support\Str::uuid());
        FunctionRegistry::register('ulid', fn () => (string) \Illuminate\Support\Str::ulid());

        FunctionRegistry::register('starts_with', fn ($value, $options) => str_starts_with($value, $options['needle'] ?? ''));
        FunctionRegistry::register('ends_with', fn ($value, $options) => str_ends_with($value, $options['needle'] ?? ''));
        FunctionRegistry::register('contains', fn ($value, $options) => str_contains($value, $options['needle'] ?? ''));

        FunctionRegistry::register('try', function ($value, $options) {
            $callback = $options['callback'] ?? null;

            try {
                if (is_string($callback) && FunctionRegistry::has($callback)) {
                    return FunctionRegistry::call($callback, $value, $options);
                }

                return is_callable($callback) ? $callback($value) : $value;
            } catch (\Throwable $e) {
                return $options['catch'] ?? 'error';
            }
        });

        FunctionRegistry::register('func_pipe', function ($data, $options) {


            if (!isset($options['functions']) || !is_array($options['functions'])) {

                return $data;
            }

            $result = $data;

            foreach ($options['functions'] as $functionName) {
                if (!isset($options[$functionName])) {
                    continue;
                }

                $functionOptions = $options[$functionName];

                // FunctionRegistry üzerinden fonksiyonu çağır
                $result = FunctionRegistry::call(
                    $functionName,
                    $result,
                    $functionOptions
                );
            }



            return $result;
        });

        // STRING FUNCTIONS
        FunctionRegistry::register('to_array', fn ($value) => json_decode($value));
        FunctionRegistry::register('upper', fn ($value) => strtoupper($value));
        FunctionRegistry::register('lower', fn ($value) => strtolower($value));
        FunctionRegistry::register('title', fn ($value) => ucwords($value));
        FunctionRegistry::register('trim', fn ($value) => trim($value));
        FunctionRegistry::register('substr', function ($value, $options) {
            $start = $options['start'] ?? 0;
            $length = $options['length'] ?? null;

            return substr($value, $start, $length);
        });
        FunctionRegistry::register('replace', function ($value, $options) {
            return str_replace($options['search'], $options['replace'], $value);
        });
        FunctionRegistry::register('slug', function ($value) {
            return Str::slug($value); // Laravel Str helper
        });
        FunctionRegistry::register('limit', function ($value, $options) {
            $limit = $options['limit'] ?? 10;

            return substr($value, 0, $limit);
        });

        // ARRAY FUNCTIONS
        FunctionRegistry::register('count', fn ($value) => is_array($value) ? count($value) : 1);
        FunctionRegistry::register('first', fn ($value) => is_array($value) ? reset($value) : $value);
        FunctionRegistry::register('last', fn ($value) => is_array($value) ? end($value) : $value);
        FunctionRegistry::register('unique', fn ($value) => is_array($value) ? array_unique($value) : [$value]);
        FunctionRegistry::register('sort', function ($value, $options) {
            if (! is_array($value)) {
                return [$value];
            }
            $sorted = $value;
            $direction = $options['direction'] ?? 'asc';
            $direction === 'desc' ? rsort($sorted) : sort($sorted);

            return $sorted;
        });
        FunctionRegistry::register('filter_pluck', function ($value, $options = []) {
            if (!is_array($value)) {
                return [];
            }

            // Ayıklama (filter) için seçenekler
            $filterKey = $options['filter_key'] ?? null;
            $operator = $options['operator'] ?? '=';
            $filterValue = $options['value'] ?? null;

            // Filtreleme işlemi
            $filtered = array_filter($value, function ($item) use ($filterKey, $operator, $filterValue) {
                $itemValue = is_array($item) && $filterKey ? ($item[$filterKey] ?? null) : $item;

                return match ($operator) {
                    '=' => $itemValue == $filterValue,
                    '!=' => $itemValue != $filterValue,
                    '>' => $itemValue > $filterValue,
                    '<' => $itemValue < $filterValue,
                    '>=' => $itemValue >= $filterValue,
                    '<=' => $itemValue <= $filterValue,
                    'in' => in_array($itemValue, (array)$filterValue),
                    'not_in' => !in_array($itemValue, (array)$filterValue),
                    'contains' => str_contains((string)$itemValue, (string)$filterValue),
                    'starts_with' => str_starts_with((string)$itemValue, (string)$filterValue),
                    'ends_with' => str_ends_with((string)$itemValue, (string)$filterValue),
                    'empty' => empty($itemValue),
                    'not_empty' => !empty($itemValue),
                    default => true
                };
            });

            $filtered = array_values($filtered);

            // Pluck işlemi
            $pluckKey = $options['pluck_key'] ?? $options['key'] ?? $options['column'] ?? $options['field'] ?? null;

            if (!$pluckKey) {
                return $filtered;
            }

            return array_column($filtered, $pluckKey);
        });
        FunctionRegistry::register('filter', function ($value, $options = []) {
            if (! is_array($value)) {
                return [];
            }

            $key = $options['key'] ?? null;
            $operator = $options['operator'] ?? '=';
            $filterValue = $options['value'] ?? null;

            $filtered = array_filter($value, function ($item) use ($key, $operator, $filterValue) {
                // Eğer item array ise ve key belirtilmişse
                if (is_array($item) && $key) {
                    $itemValue = $item[$key] ?? null;
                } else {
                    $itemValue = $item;
                }

                return match ($operator) {
                    '=' => $itemValue == $filterValue,
                    '!=' => $itemValue != $filterValue,
                    '>' => $itemValue > $filterValue,
                    '<' => $itemValue < $filterValue,
                    '>=' => $itemValue >= $filterValue,
                    '<=' => $itemValue <= $filterValue,
                    'in' => in_array($itemValue, (array) $filterValue),
                    'not_in' => !in_array($itemValue, (array) $filterValue),
                    'contains' => str_contains((string)$itemValue, (string)$filterValue),
                    'starts_with' => str_starts_with((string)$itemValue, (string)$filterValue),
                    'ends_with' => str_ends_with((string)$itemValue, (string)$filterValue),
                    'empty' => empty($itemValue),
                    'not_empty' => !empty($itemValue),
                    default => true
                };
            });

            // Array indekslerini yeniden düzenle
            return array_values($filtered);
        });
        FunctionRegistry::register('pluck', function ($value, $options = []) {
            if (!is_array($value)) {
                return [];
            }

            // String olarak key geçilmişse
            if (is_string($options)) {
                return array_column($value, $options);
            }

            // Array olarak options geçilmişse
            $key = $options['key'] ?? $options['column'] ?? $options['field'] ?? null;

            if (!$key) {
                return $value;
            }

            return array_column($value, $key);
        });

        FunctionRegistry::register('map', function ($value, $options = []) {
            if (!is_array($value)) {
                return $value;
            }

            $key = $options['key'] ?? $options['field'] ?? null;

            // Sadece belirli key'i al (pluck benzeri)
            if ($key) {
                return array_column($value, $key);
            }

            return $value;
        });
        FunctionRegistry::register('chunk', function ($value, $options) {
            if (! is_array($value)) {
                return [$value];
            }
            $size = $options['size'] ?? 2;

            return array_chunk($value, $size);
        });
        FunctionRegistry::register('array_map', function ($value, $options = []) {
            if (! is_array($value)) {
                return $value;
            }

            $callback = $options['callback'] ?? null;

            // Fonksiyon adı string olarak geçilmişse, çağrılabilir mi kontrol et
            if (is_string($callback) && function_exists($callback)) {
                return array_map($callback, $value);
            }

            // Closure ise direkt kullan
            if (is_callable($callback)) {
                return array_map($callback, $value);
            }

            // Callback geçerli değilse orijinal veriyi döndür
            return $value;
        });

        // DATE FUNCTIONS
        FunctionRegistry::register('date_format', function ($value, $options) {
            $format = $options['format'] ?? 'Y-m-d';
            try {
                return Carbon::parse($value)->format($format);
            } catch (Exception $e) {
                return $value;
            }
        });
        FunctionRegistry::register('date_diff', function ($value, $options) {
            $from = $options['from'] ?? now();
            try {
                return Carbon::parse($from)->diffInDays(Carbon::parse($value));
            } catch (Exception $e) {
                return 0;
            }
        });
        FunctionRegistry::register('age', function ($value) {
            try {
                return Carbon::parse($value)->age;
            } catch (Exception $e) {
                return 0;
            }
        });

        // MATHEMATICAL FUNCTIONS
        FunctionRegistry::register('sum', fn ($value) => is_array($value) ? array_sum($value) : (float) $value);
        FunctionRegistry::register('avg', fn ($value) => is_array($value) ? array_sum($value) / count($value) : (float) $value);
        FunctionRegistry::register('min', fn ($value) => is_array($value) ? min($value) : $value);
        FunctionRegistry::register('max', fn ($value) => is_array($value) ? max($value) : $value);
        FunctionRegistry::register('round', function ($value, $options) {
            $precision = $options['precision'] ?? 0;

            return round((float) $value, $precision);
        });

        // FORMATTING FUNCTIONS
        FunctionRegistry::register('number_format', function ($value, $options) {
            $decimals = $options['decimals'] ?? 0;
            $decimal_sep = $options['decimal_separator'] ?? ',';
            $thousands_sep = $options['thousands_separator'] ?? '.';

            return number_format((float) $value, $decimals, $decimal_sep, $thousands_sep);
        });
        FunctionRegistry::register('currency', function ($value, $options) {
            $currency = $options['currency'] ?? '₺';
            $position = $options['position'] ?? 'after'; // before, after
            $formatted = number_format((float) $value, 2, ',', '.');

            return $position === 'before' ? $currency.$formatted : $formatted.' '.$currency;
        });
        FunctionRegistry::register('percentage', function ($value, $options) {
            $total = $options['total'] ?? 100;
            $precision = $options['precision'] ?? 1;
            $percentage = ((float) $value / (float) $total) * 100;

            return round($percentage, $precision).'%';
        });

        // CONDITIONAL FUNCTIONS
        FunctionRegistry::register('default', function ($value, $options) {
            $default = $options['default'] ?? '';

            return empty($value) ? $default : $value;
        });
        FunctionRegistry::register('conditional', function ($value, $options) {
            $condition = $options['condition'] ?? 'not_empty';
            $true_value = $options['true'] ?? $value;
            $false_value = $options['false'] ?? '';

            $result = match ($condition) {
                'not_empty' => ! empty($value),
                'empty' => empty($value),
                'equals' => $value == ($options['equals'] ?? null),
                'greater_than' => (float) $value > (float) ($options['than'] ?? 0),
                'less_than' => (float) $value < (float) ($options['than'] ?? 0),
                default => ! empty($value)
            };

            return $result ? $true_value : $false_value;
        });

        // EMAIL & CONTACT FUNCTIONS
        FunctionRegistry::register('email_domain', function ($value) {
            if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return substr(strrchr($value, '@'), 1);
            }

            return '';
        });
        FunctionRegistry::register('phone_format', function ($value, $options) {
            $format = $options['format'] ?? 'international'; // national, international
            // Telefon formatlama logic'i burada
            $cleaned = preg_replace('/[^0-9]/', '', $value);
            if ($format === 'international' && ! str_starts_with($cleaned, '90')) {
                $cleaned = '90'.$cleaned;
            }

            return '+'.$cleaned;
        });

        // HTML & MARKUP FUNCTIONS
        FunctionRegistry::register('escape', fn ($value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
        FunctionRegistry::register('strip_tags', fn ($value) => strip_tags($value));
        FunctionRegistry::register('nl2br', fn ($value) => nl2br($value));
        FunctionRegistry::register('markdown', function ($value) {
            // Markdown parser kullanabilirsiniz
            return Str::markdown($value); // Laravel 9+
        });

        // FILE & URL FUNCTIONS
        FunctionRegistry::register('file_size', function ($value, $options) {
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $bytes = (int) $value;
            $i = 0;
            while ($bytes > 1024 && $i < count($units) - 1) {
                $bytes /= 1024;
                $i++;
            }

            return round($bytes, 2).' '.$units[$i];
        });
        FunctionRegistry::register('url_encode', fn ($value) => urlencode($value));
        FunctionRegistry::register('base64_encode', fn ($value) => base64_encode($value));

        // LOCALIZATION FUNCTIONS
        FunctionRegistry::register('trans', function ($value, $options) {
            $locale = $options['locale'] ?? app()->getLocale();

            return __($value, [], $locale);
        });
        FunctionRegistry::register('pluralize', function ($value, $options) {
            $count = $options['count'] ?? 1;

            return Str::plural($value, $count);
        });

        // ADVANCED FUNCTIONS
        FunctionRegistry::register('template', function ($value, $options) {
            $template = $options['template'] ?? '{value}';

            return str_replace('{value}', $value, $template);
        });
        FunctionRegistry::register('pipe', function ($value, $options) {
            $functions = $options['functions'] ?? [];
            $result = $value;
            foreach ($functions as $func) {
                $result = FunctionRegistry::call($func, $result, $options);
            }

            return $result;
        });

        // TÜRKÇE ÖZELLEŞTİRMELER
        FunctionRegistry::register('turkish_upper', function ($value) {
            $search = ['ç', 'ğ', 'ı', 'ö', 'ş', 'ü'];
            $replace = ['Ç', 'Ğ', 'I', 'Ö', 'Ş', 'Ü'];

            return str_replace($search, $replace, strtoupper($value));
        });
        FunctionRegistry::register('turkish_slug', function ($value) {
            $search = ['ç', 'ğ', 'ı', 'ö', 'ş', 'ü', 'Ç', 'Ğ', 'I', 'Ö', 'Ş', 'Ü'];
            $replace = ['c', 'g', 'i', 'o', 's', 'u', 'c', 'g', 'i', 'o', 's', 'u'];

            return Str::slug(str_replace($search, $replace, $value));
        });

        // DEBUG FUNCTIONS
        FunctionRegistry::register('dump', function ($value, $options = []) {
            $label = $options['label'] ?? 'DEBUG';

            error_log("=== {$label} ===");
            error_log(print_r($value, true));
            error_log("=== /{$label} ===");

            return $value; // Passthrough
        });

    }
}
