<?php

namespace OnaOnbir\OOWeaveReplace\Core;

use Illuminate\Support\Arr;
use OnaOnbir\OOWeaveReplace\Registry\FunctionRegistry;

class DynamicReplacer
{
    public static function replace(mixed $template, array $context): mixed
    {
        if (is_array($template)) {
            return array_map(function ($item) use ($context) {
                return self::replace($item, $context);
            }, $template);
        }

        if (! is_string($template)) {
            return $template;
        }

        $placeholders = config('oo-auto-weave.placeholders');
        $funcRegex = self::buildFunctionRegex($placeholders['function']);
        $varRegex = self::buildVariableRegex($placeholders['variable']);
        $varExactRegex = self::buildVariableExactRegex($placeholders['variable']);

        // Template'in tamamı bir function call'u mu kontrol et
        $funcRegexFull = '/^'.preg_quote($placeholders['function']['start'], '/').
            '(\w+)\((.*?)(?:,\s*(\{.*\}))?\)'.
            preg_quote($placeholders['function']['end'], '/').'$/';

        if (preg_match($funcRegexFull, $template, $fullMatch)) {
            return self::executeFunction($fullMatch, $context);
        }

        // Tek değişken kontrolü - function işlemlerinden ÖNCE kontrol et
        if (preg_match($varExactRegex, $template, $singleMatch)) {
            return self::resolveRaw(trim($singleMatch[1]), $context);
        }

        // Önce tüm değişkenleri çöz (sadece mixed template'ler için)
        $template = preg_replace_callback($varRegex, function ($matches) use ($context) {
            $resolved = self::resolveRaw(trim($matches[1]), $context);

            return is_array($resolved) ? json_encode($resolved, JSON_UNESCAPED_UNICODE) : $resolved;
        }, $template);

        // Sonra function çağrılarını en içten dışa doğru çöz
        $maxIterations = 10; // Sonsuz döngü koruması
        $iteration = 0;

        do {
            $lastTemplate = $template;
            $template = self::processInnerMostFunctions($template, $context, $placeholders);
            $iteration++;
        } while ($template !== $lastTemplate && $iteration < $maxIterations);

        // Tek değişken kontrolü - function işlemlerinden sonra tekrar kontrol et
        if (preg_match($varExactRegex, $template, $singleMatch)) {
            return self::resolveRaw(trim($singleMatch[1]), $context);
        }

        // Eğer template tamamı JSON string ise ve bu bir array ise, array olarak döndür
        if (self::isJsonString($template)) {
            $decoded = json_decode($template, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $template;
    }

    /**
     * En içteki (nested olmayan) function çağrılarını bulur ve işler
     */
    protected static function processInnerMostFunctions(string $template, array $context, array $placeholders): string
    {
        $start = preg_quote($placeholders['function']['start'], '/');
        $end = preg_quote($placeholders['function']['end'], '/');

        // En içteki function'ları yakala - içinde başka function start bulunmayan
        $pattern = "/{$start}(\w+)\(([^{$start}]+?)(?:,\s*(\{[^}]*\}))?\){$end}/";

        return preg_replace_callback($pattern, function ($matches) use ($context) {
            $result = self::executeFunction($matches, $context);

            // Sonucu string context'e uygun formatta döndür
            if (is_array($result)) {
                // Array'leri re-index et (array_filter sonrası için)
                $result = array_values($result);

                // Array'i özel bir işaretleyici ile wrap et
                return '___ARRAY_PLACEHOLDER___'.base64_encode(serialize($result)).'___END_ARRAY___';
            }

            return $result;
        }, $template);
    }

    protected static function executeFunction(array $matches, array $context): mixed
    {
        $function = $matches[1];
        $inner = trim($matches[2]);
        $options = isset($matches[3]) ? json_decode($matches[3], true) : [];

        // Array placeholder kontrolü - nested function sonuçları için
        if (str_contains($inner, '___ARRAY_PLACEHOLDER___')) {
            $inner = self::restoreArrayPlaceholders($inner);
        } elseif (self::isJsonString($inner)) {
            // JSON string mi kontrol et
            $inner = json_decode($inner, true);
        } else {
            // Normal string olarak işle
            $inner = self::replace($inner, $context);
        }

        return self::applyFunction($function, $inner, $options ?? []);
    }

    /**
     * Array placeholder'ları gerçek array'lere çevirir
     */
    protected static function restoreArrayPlaceholders(string $input): mixed
    {
        // Tek bir array placeholder'ı mı kontrol et
        if (preg_match('/^___ARRAY_PLACEHOLDER___(.+?)___END_ARRAY___$/', $input, $matches)) {
            return unserialize(base64_decode($matches[1]));
        }

        // Multiple placeholder'lar varsa bunları da handle et
        return preg_replace_callback('/___ARRAY_PLACEHOLDER___(.+?)___END_ARRAY___/', function ($matches) {
            return json_encode(unserialize(base64_decode($matches[1])), JSON_UNESCAPED_UNICODE);
        }, $input);
    }

    /**
     * Bir string'in geçerli JSON olup olmadığını kontrol eder
     */
    protected static function isJsonString(string $str): bool
    {
        if (empty($str)) {
            return false;
        }

        $trimmed = trim($str);
        if (! in_array($trimmed[0], ['[', '{'])) {
            return false;
        }

        json_decode($trimmed);

        return json_last_error() === JSON_ERROR_NONE;
    }

    protected static function buildFunctionRegex(array $config): string
    {
        $start = preg_quote($config['start'], '/');
        $end = preg_quote($config['end'], '/');

        return "/{$start}(\w+)\((.*?)(?:,\s*(\{.*\}))?\){$end}/";
    }

    protected static function buildVariableRegex(array $config): string
    {
        $start = preg_quote($config['start'], '/');
        $end = preg_quote($config['end'], '/');

        return "/{$start}(.*?){$end}/";
    }

    protected static function buildVariableExactRegex(array $config): string
    {
        $start = preg_quote($config['start'], '/');
        $end = preg_quote($config['end'], '/');

        return "/^{$start}(.*?){$end}$/";
    }

    protected static function resolveRaw(string $key, array $context): mixed
    {
        if (Arr::has($context, $key)) {
            return Arr::get($context, $key);
        }

        if (str_contains($key, '*')) {
            return self::resolveWildcardGroup($key, $context);
        }

        return Arr::get(Arr::undot($context), $key);
    }

    /**
     * Joker karakter içeren bir yolu context içinde arar ve eşleşen değerleri döndürür.
     */
    protected static function resolveWildcardGroup(string $wildcardPath, array $context): array
    {
        $resolvedValues = [];

        // wildcardPath'in son parçası (örn: 'additional_emails' veya 'name')
        $targetProperty = null;
        $pathParts = explode('.', $wildcardPath);
        if (! empty($pathParts)) {
            $targetProperty = array_pop($pathParts);
        }

        // Ana wildcard yolunu oluştur (örn: 'r_causer.r_managers.*' veya 'r_users.*')
        $baseWildcardPath = implode('.', $pathParts);

        // Regex pattern'ini oluştur
        $regexBasePattern = '/^'.str_replace('\*', '([^.]+)', preg_quote($baseWildcardPath, '/')).'\.(.*)$/';

        $groupedMatches = [];

        foreach ($context as $flatKey => $value) {
            if (preg_match($regexBasePattern, $flatKey, $matches)) {
                // Her bir * tarafından yakalanan değerleri al
                $wildcardValues = [];
                for ($i = 1; $i <= substr_count($baseWildcardPath, '*'); $i++) {
                    $wildcardValues[] = $matches[$i];
                }

                // Benzersiz grup anahtarı oluştur
                $groupKey = str_replace(array_fill(0, count($wildcardValues), '*'), $wildcardValues, $baseWildcardPath);

                // Kalan düzleştirilmiş anahtar
                $remainingFlatKey = $matches[count($matches) - 1];

                if (! isset($groupedMatches[$groupKey])) {
                    $groupedMatches[$groupKey] = [];
                }
                $groupedMatches[$groupKey][$remainingFlatKey] = $value;
            }
        }

        foreach ($groupedMatches as $groupKey => $groupData) {
            $undottedGroupData = Arr::undot($groupData);

            // Hedef property kontrolü
            if ($targetProperty && ! str_contains($wildcardPath, $targetProperty.'.*')) {
                if (isset($undottedGroupData[$targetProperty]) && ! is_array($undottedGroupData[$targetProperty])) {
                    $resolvedValues[] = $undottedGroupData[$targetProperty];
                } elseif (isset($undottedGroupData[$targetProperty]) && is_array($undottedGroupData[$targetProperty])) {
                    $resolvedValues[] = array_values($undottedGroupData[$targetProperty]);
                } else {
                    $resolvedValues[] = $undottedGroupData;
                }
            } else {
                if (is_array($undottedGroupData)) {
                    if (array_is_list($undottedGroupData)) {
                        $resolvedValues[] = $undottedGroupData;
                    } else {
                        $resolvedValues[] = array_values($undottedGroupData);
                    }
                } else {
                    $resolvedValues[] = $undottedGroupData;
                }
            }
        }

        // Flatten işlemi
        $flattened = [];
        foreach ($resolvedValues as $value) {
            if (is_array($value) && ! empty($value) && is_array(reset($value))) {
                $flattened = array_merge($flattened, $value);
            } else {
                $flattened[] = $value;
            }
        }

        return $flattened;
    }

    protected static function applyFunction(string $function, mixed $value, array $options = []): mixed
    {
        return FunctionRegistry::call($function, $value, $options);
    }
}
