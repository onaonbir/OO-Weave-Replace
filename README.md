# DynamicReplacer - Usage Documentation

DynamicReplacer, template strings ve arrays üzerinde dinamik değişken değiştirme ve fonksiyon uygulama işlemlerini gerçekleştiren güçlü bir PHP sınıfıdır.

## Temel Kullanım

```php
use OnaOnbir\OOAutoWeave\Core\Data\DynamicReplacer;

$context = [
    'user.name' => 'John Doe',
    'user.email' => 'john@example.com',
    'products.0.name' => 'Laptop',
    'products.0.price' => 1500,
    'products.1.name' => 'Mouse',
    'products.1.price' => 25
];

$template = 'Merhaba {{user.name}}, email adresiniz: {{user.email}}';
$result = DynamicReplacer::replace($template, $context);
// Sonuç: "Merhaba John Doe, email adresiniz: john@example.com"
```

## Yapılandırma

DynamicReplacer, placeholder'ları config dosyasından okur:

```php
// config/oo-auto-weave.php
return [
    'placeholders' => [
        'variable' => [
            'start' => '{{',
            'end' => '}}'
        ],
        'function' => [
            'start' => '@@',
            'end' => '@@'
        ]
    ]
];
```

## Değişken Değiştirme

### Basit Değişkenler

```php
$context = ['name' => 'Ali', 'age' => 25];
$template = 'İsim: {{name}}, Yaş: {{age}}';
$result = DynamicReplacer::replace($template, $context);
// Sonuç: "İsim: Ali, Yaş: 25"
```

### Noktalı Notation

```php
$context = [
    'user.profile.name' => 'Ayşe',
    'user.profile.city' => 'İstanbul'
];
$template = '{{user.profile.name}} - {{user.profile.city}}';
$result = DynamicReplacer::replace($template, $context);
// Sonuç: "Ayşe - İstanbul"
```

### Tek Değişken Template

Template tamamen tek bir değişken ise, o değişkenin orijinal türü korunur:

```php
$context = ['users' => ['Ali', 'Veli', 'Deli']];
$template = '{{users}}';
$result = DynamicReplacer::replace($template, $context);
// Sonuç: ['Ali', 'Veli', 'Deli'] (array olarak)
```

## Wildcard Kullanımı

### Basit Wildcard

```php
$context = [
    'users.0.name' => 'Ali',
    'users.1.name' => 'Veli',
    'users.2.name' => 'Ahmet'
];
$template = '{{users.*.name}}';
$result = DynamicReplacer::replace($template, $context);
// Sonuç: ['Ali', 'Veli', 'Ahmet']
```

### Çoklu Wildcard

```php
$context = [
    'departments.0.users.0.name' => 'Ali',
    'departments.0.users.1.name' => 'Veli',
    'departments.1.users.0.name' => 'Ayşe',
    'departments.1.users.1.name' => 'Fatma'
];
$template = '{{departments.*.users.*.name}}';
$result = DynamicReplacer::replace($template, $context);
// Sonuç: ['Ali', 'Veli', 'Ayşe', 'Fatma']
```

### İç İçe Array Wildcard

```php
$context = [
    'managers.0.additional_emails.0.name' => 'work@example.com',
    'managers.0.additional_emails.1.name' => 'personal@example.com',
    'managers.1.additional_emails.0.name' => 'admin@example.com'
];
$template = '{{managers.*.additional_emails.*.name}}';
$result = DynamicReplacer::replace($template, $context);
// Sonuç: ['work@example.com', 'personal@example.com', 'admin@example.com']
```

## Fonksiyon Kullanımı

### Temel Fonksiyon Syntax

```php
$template = '@@function_name({{variable}})@@';
$template = '@@function_name({{variable}}, {"option": "value"})@@';
```

### Fonksiyon Kaydetme

```php
use OnaOnbir\OOWeaveReplace\Registry\FunctionRegistry;

// Basit fonksiyon
FunctionRegistry::register('upper', function ($value, $options) {
    return strtoupper($value);
});

// Opsiyonlu fonksiyon
FunctionRegistry::register('limit', function ($value, $options) {
    $limit = $options['limit'] ?? 10;
    return substr($value, 0, $limit);
});

// Array fonksiyonu
FunctionRegistry::register('sort', function ($value, $options) {
    if (!is_array($value)) return [$value];
    $sorted = $value;
    $direction = $options['direction'] ?? 'asc';
    $direction === 'desc' ? rsort($sorted) : sort($sorted);
    return $sorted;
});
```

### Fonksiyon Kullanım Örnekleri

```php
$context = [
    'name' => 'john doe',
    'description' => 'Bu çok uzun bir açıklama metnidir',
    'numbers' => [5, 2, 8, 1, 9]
];

// String fonksiyonları
$result = DynamicReplacer::replace('@@upper({{name}})@@', $context);
// Sonuç: "JOHN DOE"

$result = DynamicReplacer::replace('@@limit({{description}}, {"limit": 10})@@', $context);
// Sonuç: "Bu çok uzu"

// Array fonksiyonları
$result = DynamicReplacer::replace('@@sort({{numbers}})@@', $context);
// Sonuç: [1, 2, 5, 8, 9] (array olarak)

$result = DynamicReplacer::replace('@@sort({{numbers}}, {"direction": "desc"})@@', $context);
// Sonuç: [9, 8, 5, 2, 1] (array olarak)
```

## Önemli Davranış Farkları

### Array Döndürme vs String Döndürme

```php
// Template tamamen fonksiyon ise → Array döndürür
$result = DynamicReplacer::replace('@@sort({{numbers}})@@', $context);
// Sonuç: [1, 2, 5, 8, 9] (array)

// Template string içinde fonksiyon ise → JSON string döndürür
$result = DynamicReplacer::replace('Sayılar: @@sort({{numbers}})@@', $context);
// Sonuç: "Sayılar: [1,2,5,8,9]" (string)
```

### Tek Değişken vs Çoklu Değişken

```php
// Tek değişken → Orijinal tür korunur
$result = DynamicReplacer::replace('{{users}}', $context);
// Sonuç: ['Ali', 'Veli'] (array)

// String içinde değişken → JSON string
$result = DynamicReplacer::replace('Kullanıcılar: {{users}}', $context);
// Sonuç: 'Kullanıcılar: ["Ali","Veli"]' (string)
```

## Recursive Fonksiyon Kullanımı

DynamicReplacer, iç içe fonksiyon çağrılarını destekler:

```php
$context = [
    'text' => 'merhaba dünya',
    'numbers' => [3, 1, 4, 1, 5]
];

// İç içe fonksiyon çağrıları
$result = DynamicReplacer::replace('@@upper(@@limit({{text}}, {"limit": 7}))@@', $context);
// Sonuç: "MERHABA"

// Fonksiyon içinde wildcard
$context = [
    'users.0.name' => 'ali veli',
    'users.1.name' => 'ayşe fatma'
];
$result = DynamicReplacer::replace('@@upper(@@implode({{users.*.name}}, {"separator": " - "})@@)@@', $context);
// Sonuç: "ALI VELI - AYŞE FATMA"
```

## Karmaşık Örnekler

### E-posta Yönetimi

```php
$context = [
    'r_causer.r_managers.0.additional_emails.0.name' => 'work@company.com',
    'r_causer.r_managers.0.additional_emails.0.type' => 'work',
    'r_causer.r_managers.0.additional_emails.1.name' => 'personal@gmail.com',
    'r_causer.r_managers.0.additional_emails.1.type' => 'personal',
    'r_causer.r_managers.1.additional_emails.0.name' => 'admin@company.com',
    'r_causer.r_managers.1.additional_emails.0.type' => 'admin'
];

$templates = [
    'work_emails' => '{{r_causer.r_managers.*.additional_emails.*.name}}',
    'work_emails_full' => '{{r_causer.r_managers.*.additional_emails}}',
    'flat_json' => '@@json_encode({{r_causer.r_managers.*.additional_emails}})@@',
    'email_count' => '@@count({{r_causer.r_managers.*.additional_emails}})@@',
    'sorted_emails' => '@@sort({{r_causer.r_managers.*.additional_emails}})@@'
];

$result = DynamicReplacer::replace($templates, $context);
```

### Kullanıcı Formatı

```php
$context = [
    'user.name' => 'Mehmet Ali Özkan',
    'user.email' => 'mehmet.ali@example.com'
];

// Slug oluşturma
FunctionRegistry::register('slug', function ($value, $options) {
    $slug = strtolower($value);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-');
});

$result = DynamicReplacer::replace('@@slug({{user.name}})@@', $context);
// Sonuç: "mehmet-ali-ozkan"
```

### Custom Fonksiyon ile Separator

```php
FunctionRegistry::register('custom_join', function ($value, $options) {
    if (!is_array($value)) return $value;
    $separator = $options['separator'] ?? ', ';
    $result = [];
    foreach ($value as $item) {
        if (is_array($item) && isset($item['name'])) {
            $result[] = $item['name'];
        } else {
            $result[] = $item;
        }
    }
    return implode($separator, $result);
});

$result = DynamicReplacer::replace(
    '@@custom_join({{users.*.name}}, {"separator": " | "})@@',
    $context
);
```

## Array İşleme

### Array Map Fonksiyonu

```php
$context = [
    'users.0.name' => 'ali',
    'users.1.name' => 'veli',
    'users.2.name' => 'deli'
];

$result = DynamicReplacer::replace([
    'user_names' => '{{users.*.name}}',
    'user_names_upper' => '@@array_map({{users.*.name}}, {"callback": "strtoupper"})'
], $context);
```

## Hata Yönetimi

DynamicReplacer, eksik değişkenler için güvenli varsayılanlar sağlar:

```php
$context = ['name' => 'Ali'];
$result = DynamicReplacer::replace('{{name}} - {{missing_var}}', $context);
// Sonuç: "Ali - " (missing_var boş string olur)
```

## Performans İpuçları

1. **Büyük veri setleri**: Wildcard kullanırken context'i mümkün olduğunca düz tutun
2. **Recursive fonksiyonlar**: Çok derin iç içe fonksiyonlardan kaçının
3. **Array fonksiyonları**: Büyük array'ler için memory kullanımına dikkat edin

## Örnek Kullanım Senaryoları

### 1. E-posta Template'i

```php
$context = [
    'user.name' => 'Ahmet',
    'order.id' => 12345,
    'order.items.0.name' => 'Laptop',
    'order.items.1.name' => 'Mouse'
];

$emailTemplate = [
    'subject' => 'Sipariş Onayı - #{{order.id}}',
    'body' => 'Merhaba {{user.name}}, siparişiniz onaylandı. Ürünler: @@implode({{order.items.*.name}}, {"separator": ", "})@@'
];

$result = DynamicReplacer::replace($emailTemplate, $context);
```

### 2. Dinamik Menü Oluşturma

```php
$context = [
    'menu.0.title' => 'Ana Sayfa',
    'menu.0.url' => '/',
    'menu.1.title' => 'Ürünler',
    'menu.1.url' => '/products'
];

$menuTemplate = [
    'titles' => '{{menu.*.title}}',
    'urls' => '{{menu.*.url}}',
    'full_menu' => '{{menu}}'
];
```

### 3. Rapor Oluşturma

```php
$context = [
    'sales.0.amount' => 1500,
    'sales.1.amount' => 2300,
    'sales.2.amount' => 1800
];

$reportTemplate = [
    'total_sales' => '@@sum({{sales.*.amount}})@@',
    'average_sale' => '@@avg({{sales.*.amount}})@@',
    'max_sale' => '@@max({{sales.*.amount}})@@'
];
```
