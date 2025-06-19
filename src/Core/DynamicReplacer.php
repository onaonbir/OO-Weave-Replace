<?php

namespace OnaOnbir\OOWeaveReplace\Core;

use Illuminate\Support\Arr;
use OnaOnbir\OOWeaveReplace\Registry\FunctionRegistry;

class DynamicReplacer
{
    public static function replace(mixed $template, array $context): mixed
    {
        if (is_array($template)) {
            // "Only variables should be passed by reference" hatası için bu kısmı güvenceye alıyoruz.
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

        // RECURSIVE @@function(...)@@ çözümü
        do {
            $lastTemplate = $template;
            $template = preg_replace_callback($funcRegex, function ($matches) use ($context) {
                $result = self::executeFunction($matches, $context);

                // String context içinde array'i JSON olarak encode et
                return is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : $result;
            }, $template);
        } while ($template !== $lastTemplate);

        if (preg_match($varExactRegex, $template, $singleMatch)) {
            return self::resolveRaw(trim($singleMatch[1]), $context);
        }

        $template = preg_replace_callback($varRegex, function ($matches) use ($context) {
            $resolved = self::resolveRaw(trim($matches[1]), $context);

            return is_array($resolved) ? json_encode($resolved, JSON_UNESCAPED_UNICODE) : $resolved;
        }, $template);

        return $template;
    }

    protected static function executeFunction(array $matches, array $context): mixed
    {
        $function = $matches[1];
        $inner = trim($matches[2]);
        $options = isset($matches[3]) ? json_decode($matches[3], true) : [];

        if (preg_match('/^\{\{(.*?)\}\}$/', $inner, $innerMatch)) {
            $inner = self::resolveRaw(trim($innerMatch[1]), $context);
        } else {
            $inner = self::replace($inner, $context);
        }

        return self::applyFunction($function, $inner, $options ??[]);
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
     * Bu fonksiyon, verilen wildcardPath'e uyan tüm düzleştirilmiş anahtarları toplar
     * ve bunları, wildcard'ın bittiği yerdeki alt dizileri veya değerleri içerecek şekilde yeniden yapılandırır.
     *
     * @param  string  $wildcardPath  Örneğin: 'r_causer.r_managers.*.additional_emails' veya 'r_users.*.name'
     * @param  array  $context  Düzleştirilmiş bağlam verisi
     * @return array Çözümlenmiş değerlerin bir dizisi
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
        // Bu kısım, her bir * eşleşmesinin benzersiz bir "grup anahtarı" oluşturmasını sağlayacak.
        $baseWildcardPath = implode('.', $pathParts);

        // Regex pattern'ini oluştur. $baseWildcardPath ile başlayan ve ardından kalan kısmı yakalayan bir pattern.
        // DÜZELTME: Çoklu wildcard desteklemek için [^.]+ kullan
        $regexBasePattern = '/^'.str_replace('\*', '([^.]+)', preg_quote($baseWildcardPath, '/')).'\.(.*)$/';

        $groupedMatches = [];

        foreach ($context as $flatKey => $value) {
            if (preg_match($regexBasePattern, $flatKey, $matches)) {
                $matchIdxOffset = count($pathParts) - substr_count($baseWildcardPath, '*'); // Kaç * olduğunu say

                // Her bir * tarafından yakalanan değerleri al
                $wildcardValues = [];
                for ($i = 1; $i <= substr_count($baseWildcardPath, '*'); $i++) {
                    $wildcardValues[] = $matches[$i];
                }

                // Bu, 'r_causer.r_managers.0' gibi bir benzersiz grup anahtarı oluşturacak
                $groupKey = str_replace(array_fill(0, count($wildcardValues), '*'), $wildcardValues, $baseWildcardPath);

                // remainingFlatKey: baseWildcardPath'ten sonra düzleştirilmiş anahtarda kalan kısım
                // Örnek: 'additional_emails.0.name'
                $remainingFlatKey = $matches[count($matches) - 1]; // Son yakalama grubu

                if (! isset($groupedMatches[$groupKey])) {
                    $groupedMatches[$groupKey] = [];
                }
                $groupedMatches[$groupKey][$remainingFlatKey] = $value;
            }
        }

        foreach ($groupedMatches as $groupKey => $groupData) {
            $undottedGroupData = Arr::undot($groupData);

            // Eğer hedefimiz doğrudan bir properti ise (örn: 'name' için {{r_users.*.name}})
            if ($targetProperty && ! str_contains($wildcardPath, $targetProperty.'.*')) {
                // Eğer undottedGroupData içinde direkt targetProperty varsa ve değeri bir dizi değilse
                if (isset($undottedGroupData[$targetProperty]) && ! is_array($undottedGroupData[$targetProperty])) {
                    $resolvedValues[] = $undottedGroupData[$targetProperty];
                } elseif (isset($undottedGroupData[$targetProperty]) && is_array($undottedGroupData[$targetProperty])) {
                    // Eğer hedef properti bir dizi ise (additional_emails gibi), o diziyi al.
                    // Bu durumda undottedGroupData içinde additional_emails'ın kendisi de bir dizi olacaktır.
                    // Ve bazen bu dizinin içinde 0, 1 gibi anahtarlar olabilir.
                    $resolvedValues[] = array_values($undottedGroupData[$targetProperty]);
                } else {
                    // Eğer targetProperty yoksa veya beklenmedik bir durumsa, tüm grubu ekleyelim.
                    $resolvedValues[] = $undottedGroupData;
                }
            } else {
                // Eğer wildcardPath'in sonu da bir joker karakter içeriyorsa
                // (örn: {{r_causer.r_managers.*.additional_emails.*}})
                // veya {{r_causer.r_managers.*.additional_emails}} gibi bir liste hedefliyorsak,
                // undot edilmiş veriyi doğrudan eklemeliyiz.
                // Bu durumda undottedGroupData'nın kendisi, istediğimiz listeyi içerecektir.
                // Örneğin: [{"name":"test",...}]
                if (is_array($undottedGroupData)) {
                    // Eğer en dışta bir sayısal anahtar varsa (0, 1 gibi), doğrudan değerlerini al.
                    if (array_is_list($undottedGroupData)) { // PHP 8.1+
                        $resolvedValues[] = $undottedGroupData;
                    } else {
                        // Eğer PHP 8.1 öncesi veya liste değilse, değerlerini alıp liste yap.
                        $resolvedValues[] = array_values($undottedGroupData);
                    }
                } else {
                    $resolvedValues[] = $undottedGroupData;
                }
            }
        }

        $flattened = [];
        foreach ($resolvedValues as $value) {
            if (is_array($value) && ! empty($value) && is_array(reset($value))) {
                // Eğer içiçe array ise flatten et
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
