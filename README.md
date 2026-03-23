# APDelivery PHP Library

PHP-клієнт для [APDelivery API](https://api.apdelivery.site/) — географічні довідники України та відділення поштових перевізників.

Один файл, без зовнішніх залежностей. Підтримує **PHP 5.6 – 8.4**.

---

## Зміст

- [Вимоги](#вимоги)
- [Встановлення](#встановлення)
- [Швидкий старт](#швидкий-старт)
- [Конфігурація](#конфігурація)
- [Автентифікація](#автентифікація)
  - [Bearer Token (звичайний режим)](#bearer-token-звичайний-режим)
  - [HMAC підпис](#hmac-підпис)
- [Методи API](#методи-api)
  - [Географія](#географія)
  - [Відділення перевізників](#відділення-перевізників)
  - [Інформація про API](#інформація-про-api)
  - [Перевірка API-ключа](#перевірка-api-ключа)
  - [Довільні запити](#довільні-запити)
- [Обробка помилок](#обробка-помилок)
- [Безпека](#безпека)
- [Ліцензія](#ліцензія)

---

## Вимоги

| Вимога          | Версія    |
|-----------------|-----------|
| PHP             | 5.6 – 8.4 |
| Розширення cURL | будь-яке  |

---

## Встановлення

### Варіант 1 — один файл (рекомендовано)

Скопіюйте `APDelivery.php` до вашого проекту та підключіть:

```php
require_once '/path/to/APDelivery.php';
```

### Варіант 2 — через Composer

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/bogdan281989/apdelivery_php_library"
        }
    ],
    "require": {
        "bogdan281989/apdelivery": "^2.0"
    }
}
```

```bash
composer install
```

---

## Швидкий старт

```php
<?php
require_once 'APDelivery.php';

$client = new APDelivery('YOUR_API_KEY');

// Отримати всі області
$regions = $client->getRegions(['lang' => 'ua']);
foreach ($regions['data'] as $region) {
    echo $region['name'] . "\n";
}

// Знайти місто
$cities = $client->getCities(['search' => 'Київ', 'lang' => 'ua']);

// Відділення Нової Пошти у місті
$warehouses = $client->getWarehouses([
    'carrier'   => APDelivery::CARRIER_NOVA_POSHTA,
    'city_uuid' => $cities['data'][0]['uuid'],
]);
```

---

## Конфігурація

```php
$client = new APDelivery('YOUR_API_KEY', [
    // HMAC-підпис (для ключів з restriction_type=hmac)
    'hmac_secret'     => 'YOUR_HMAC_SECRET',

    // Таймаут запиту у секундах (за замовчуванням: 30)
    'timeout'         => 30,

    // Перевіряти SSL-сертифікат (за замовчуванням: true, не вимикайте у production)
    'ssl_verify'      => true,

    // Кількість повторних спроб при помилках 5xx / мережі (за замовчуванням: 1)
    'max_retries'     => 1,

    // Базова URL API (за замовчуванням: https://api.apdelivery.site)
    'base_url'        => 'https://api.apdelivery.site',

    // Додаткові заголовки до кожного запиту
    'default_headers' => [
        'X-Custom-Header' => 'value',
    ],

    // Проксі (необов'язково)
    'proxy' => [
        'host' => 'proxy.example.com',
        'port' => 8080,
        'user' => 'proxyuser', // необов'язково
        'pass' => 'proxypass', // необов'язково
    ],
]);
```

### Зміна ключа під час роботи

```php
$client->setApiKey('NEW_API_KEY');
$client->setHmacSecret('NEW_SECRET');
$client->disableHmac(); // повернутися до звичайного Bearer
```

---

## Автентифікація

### Bearer Token (звичайний режим)

Для ключів з `restriction_type = none`, `ip` або `domain` достатньо передати лише API ключ:

```php
$client = new APDelivery('YOUR_API_KEY');
```

Кожен запит автоматично матиме заголовок:
```
Authorization: Bearer YOUR_API_KEY
```

### HMAC підпис

Для ключів з `restriction_type = hmac` кожен запит підписується за допомогою HMAC-SHA256.

```php
$client = new APDelivery('YOUR_API_KEY', [
    'hmac_secret' => 'YOUR_HMAC_SECRET',
]);
```

Бібліотека автоматично додає до кожного запиту:
- `X-Timestamp` — поточний Unix timestamp
- `X-Signature` — HMAC-SHA256 підпис

**Формула підпису:**
```
string_to_sign = timestamp + "\n" + METHOD + "\n" + path + query_string
signature      = HMAC-SHA256(hmac_secret, string_to_sign)
```

**Приклад для `GET /v1/regions?lang=ua`:**
```
string_to_sign = "1709900000\nGET\n/v1/regions?lang=ua"
signature      = HMAC-SHA256("your_hmac_secret", string_to_sign)
```

---

## Методи API

### Географія

#### Області (`/v1/regions`)

```php
// Усі області
$result = $client->getRegions();

// З параметрами
$result = $client->getRegions([
    'lang'    => 'ua',            // 'ua' (за замовчуванням) або 'en'
    'uuid'    => 'a1b2c3d4-...',  // конкретна область
    'search'  => 'Київ',          // пошук за назвою
    'carrier' => 'novaposhta',    // лише області з відділеннями перевізника
]);

// Структура відповіді:
// $result['success'] === true
// $result['data']    — масив об'єктів Region
// $result['meta']['total'] — загальна кількість
foreach ($result['data'] as $region) {
    echo $region['uuid'] . ' — ' . $region['name'] . "\n";
    // Також: name_ua, name_en, koatuu, katottg
}
```

#### Райони (`/v1/districts`)

```php
$result = $client->getDistricts([
    'region_uuid' => 'a1b2c3d4-...',  // фільтр за областю
    'lang'        => 'ua',
    'search'      => 'Бориспіль',
    'carrier'     => 'ukrposhta',
    'page'        => 1,
    'limit'       => 50,              // макс. 100
]);

// $result['meta'] містить: total, page, limit, pages

foreach ($result['data'] as $district) {
    echo $district['name'] . "\n";
    // Також: uuid, region_uuid, name_ua, name_en, koatuu, katottg,
    //        region_name_ua, region_name_en
}
```

#### Міста (`/v1/cities`)

Підтримує full-text пошук (від 3 символів).

```php
$result = $client->getCities([
    'search'        => 'Харків',      // full-text (мін. 3 символи)
    'lang'          => 'ua',
    'region_uuid'   => 'a1b2c3d4-...',
    'district_uuid' => 'b2c3d4e5-...',
    'carrier'       => 'meest',
    'page'          => 1,
    'limit'         => 20,
]);

foreach ($result['data'] as $city) {
    echo $city['name_ua'] . ' (' . $city['city_type_short'] . ')' . "\n";
    // Також: uuid, region_uuid, district_uuid, name_en, koatuu, katottg,
    //        city_type, city_type_en, city_type_short_en,
    //        population, latitude, longitude, is_districtcenter,
    //        region_name_ua, region_name_en, district_name_ua, district_name_en
}
```

#### Вулиці (`/v1/streets`)

Обов'язковий параметр: `city_uuid` або `city_id`.

```php
$result = $client->getStreets([
    'city_uuid' => 'uuid-міста',   // обов'язково (або city_id)
    'search'    => 'Хрещатик',     // full-text (мін. 3 символи)
    'lang'      => 'ua',
    'page'      => 1,
    'limit'     => 50,
]);

foreach ($result['data'] as $street) {
    echo $street['street_type_short_ua'] . ' ' . $street['name_ua'] . "\n";
    // Також: uuid, city_uuid, name_en, street_type_ua, street_type_en,
    //        street_type_short_en, old_name_ua, old_name_en
}
```

---

### Відділення перевізників

#### `/v1/warehouses`

Обов'язкові параметри: `carrier` та `city_uuid`.

```php
// Константи перевізників
APDelivery::CARRIER_NOVA_POSHTA  // 'novaposhta'
APDelivery::CARRIER_UKRPOSHTA    // 'ukrposhta'
APDelivery::CARRIER_MEEST        // 'meest'
APDelivery::CARRIER_ROZETKA      // 'rozetka'

// Константи типів
APDelivery::WAREHOUSE_TYPE_POSTOMAT  // 'postomat'
APDelivery::WAREHOUSE_TYPE_BRANCH    // 'branch'
```

**Нова Пошта:**

```php
$result = $client->getWarehouses([
    'carrier'   => APDelivery::CARRIER_NOVA_POSHTA,
    'city_uuid' => 'uuid-міста',
    'type'      => APDelivery::WAREHOUSE_TYPE_BRANCH, // або 'postomat'
    'search'    => 'Пирогівський',
    'number'    => '1',
    'page'      => 1,
    'limit'     => 50,
]);

foreach ($result['data'] as $w) {
    echo $w['number'] . ': ' . $w['name'] . "\n";
    // Також: uuid, city_uuid, np_ref, address, latitude, longitude,
    //        phone, status, category
}
```

**Укрпошта:**

```php
$result = $client->getWarehouses([
    'carrier'   => APDelivery::CARRIER_UKRPOSHTA,
    'city_uuid' => 'uuid-міста',
    'postcode'  => '01001',  // пошуку за індексом (лише ukrposhta)
]);

foreach ($result['data'] as $w) {
    echo $w['postcode'] . ' — ' . $w['name'] . "\n";
    // Також: uuid, city_uuid, region_uuid, po_index, number, name_en, address,
    //        type, type_description, category, is_mobile, is_stationary,
    //        latitude, longitude, schedule, phone
}
```

**Meest Express:**

```php
$result = $client->getWarehouses([
    'carrier'   => APDelivery::CARRIER_MEEST,
    'city_uuid' => 'uuid-міста',
]);

foreach ($result['data'] as $w) {
    echo $w['number_showcase'] . ': ' . $w['street_ua'] . ', ' . $w['street_number'] . "\n";
    // Також: uuid, city_uuid, region_uuid, meest_br_id, city_ua, city_en, street_en,
    //        postcode, latitude, longitude, location_description, type_ua, type_en,
    //        schedule, parcel_max_kg, place_max_kg
}
```

**Rozetka Delivery:**

```php
$result = $client->getWarehouses([
    'carrier'   => APDelivery::CARRIER_ROZETKA,
    'city_uuid' => 'uuid-міста',
]);

foreach ($result['data'] as $w) {
    echo $w['name'] . ' — ' . $w['street_name'] . ', ' . $w['house'] . "\n";
    // Також: rz_department_id, city_name, latitude, longitude,
    //        schedule, carrier_name, department_type_name
}
```

**Структура `meta` для відділень:**

```php
$result['meta']['total']   // загальна кількість відділень
$result['meta']['page']
$result['meta']['limit']
$result['meta']['pages']
$result['meta']['carrier'] // підтверджує перевізника запиту
```

---

### Інформація про API

```php
$info = $client->getInfo();

echo $info['data']['api_version'];          // '1.0'
echo $info['data']['rate_limit']['limit'];     // ліміт запитів на добу
echo $info['data']['rate_limit']['used'];      // використано сьогодні
echo $info['data']['rate_limit']['remaining']; // залишилось

// Список ендпоінтів
foreach ($info['data']['endpoints'] as $endpoint => $description) {
    echo $endpoint . ' — ' . $description . "\n";
}
```

### Перевірка API-ключа

`validateApiKey()` — безпечна альтернатива `getInfo()` для перевірки ключа.
Повертає `true`, якщо ключ дійсний, або `false` при HTTP 401/403 (невірний,
неактивний ключ, заборонений IP тощо). Мережеві помилки та помилки сервера
(5xx) продовжують генерувати виключення, щоб їх не приховати.

```php
if ($client->validateApiKey()) {
    echo 'API-ключ дійсний';
} else {
    echo 'Невірний або неактивний API-ключ';
}
```

> **Чому не `getInfo()`?**  
> `getInfo()` генерує `APDeliveryAuthException` при невірному ключі.
> Якщо виключення не перехоплено — виникає PHP Fatal error.
> `validateApiKey()` перехоплює помилку автентифікації всередині і повертає `false`,
> тому викликаючий код не потребує блоку `try / catch`.
>
> Якщо вам потрібно отримати деталі помилки — використовуйте `getInfo()` у `try / catch`:
>
> ```php
> try {
>     $info = $client->getInfo();
> } catch (APDeliveryAuthException $e) {
>     echo 'Код помилки: ' . $e->getApiCode();   // 'API_KEY_INVALID'
>     echo 'Повідомлення: ' . $e->getMessage();
> }
> ```

---

### Довільні запити

Якщо в API з'являться нові ендпоінти, їх можна викликати напряму:

```php
$result = $client->get('/v1/some-endpoint', ['param' => 'value']);
$result = $client->post('/v1/some-endpoint', ['key' => 'value']);
$result = $client->put('/v1/some-endpoint', ['key' => 'value']);
$result = $client->patch('/v1/some-endpoint', ['key' => 'value']);
$result = $client->delete('/v1/some-endpoint');
```

### Діагностика останнього запиту

```php
$info = $client->getLastResponseInfo();
echo 'HTTP code:   ' . $info['http_code']   . "\n";
echo 'Total time:  ' . $info['total_time']  . " s\n";
echo 'URL called:  ' . $info['url']         . "\n";
```

---

## Обробка помилок

Бібліотека використовує ієрархію виключень:

| Клас                            | HTTP-код | Коли виникає                                          |
|---------------------------------|----------|-------------------------------------------------------|
| `APDeliveryException`           | —        | Базовий клас; помилка cURL, JSON тощо                 |
| `APDeliveryValidationException` | —        | Невірні вхідні параметри до відправки запиту          |
| `APDeliveryHttpException`       | ≥ 400    | Будь-яка HTTP-помилка від API                         |
| `APDeliveryAuthException`       | 401/403  | Невірний ключ, IP, домен або HMAC підпис              |
| `APDeliveryRateLimitException`  | 429      | Перевищено добовий ліміт запитів                      |

```php
<?php
require_once 'APDelivery.php';

try {
    $client = new APDelivery(getenv('APDELIVERY_API_KEY'));
    $result = $client->getWarehouses([
        'carrier'   => 'novaposhta',
        'city_uuid' => 'some-uuid',
    ]);
    print_r($result['data']);

} catch (APDeliveryRateLimitException $e) {
    // HTTP 429 — добовий ліміт вичерпано
    echo 'Ліміт запитів вичерпано: ' . $e->getMessage();
    // $e->getApiCode() === 'RATE_LIMIT_EXCEEDED'

} catch (APDeliveryAuthException $e) {
    // HTTP 401/403 — проблеми з автентифікацією
    echo 'Помилка доступу (' . $e->getApiCode() . '): ' . $e->getMessage();
    // Можливі API коди: API_KEY_MISSING, API_KEY_INVALID, API_KEY_INACTIVE,
    //                   IP_FORBIDDEN, DOMAIN_FORBIDDEN,
    //                   HMAC_MISSING, HMAC_EXPIRED, HMAC_INVALID

} catch (APDeliveryHttpException $e) {
    echo 'HTTP ' . $e->getStatusCode() . ' [' . $e->getApiCode() . ']: ' . $e->getMessage();
    $body = $e->getResponseBody(); // масив із тіла відповіді або null

} catch (APDeliveryValidationException $e) {
    // Невірний параметр ще до відправки запиту
    echo 'Помилка параметрів: ' . $e->getMessage();

} catch (APDeliveryException $e) {
    // cURL, JSON encoding тощо
    echo 'Помилка клієнта: ' . $e->getMessage();
}
```

### Коди помилок API (`getApiCode()`)

| Код                    | Опис                                        |
|------------------------|---------------------------------------------|
| `API_KEY_MISSING`      | Заголовок Authorization відсутній           |
| `API_KEY_INVALID`      | Невірний ключ                               |
| `API_KEY_INACTIVE`     | Ключ деактивовано                           |
| `IP_FORBIDDEN`         | IP-адреса не дозволена                      |
| `DOMAIN_FORBIDDEN`     | Домен не дозволений                         |
| `HMAC_MISSING`         | Відсутні заголовки X-Timestamp / X-Signature |
| `HMAC_EXPIRED`         | X-Timestamp виходить за межі ±5 хвилин      |
| `HMAC_INVALID`         | Невірний HMAC підпис                        |
| `MISSING_PARAMETER`    | Відсутній обов'язковий параметр             |
| `RATE_LIMIT_EXCEEDED`  | Добовий ліміт запитів вичерпано             |
| `SERVER_ERROR`         | Внутрішня помилка сервера                   |

---

## Безпека

| Захист                            | Реалізація                                                                                                                  |
|-----------------------------------|-----------------------------------------------------------------------------------------------------------------------------|
| **Перевірка SSL**                 | `ssl_verify => true` за замовчуванням; не вимикайте у production                                                           |
| **Захист API-ключа**              | Відхиляє порожні рядки та символи керування (`\x00–\x1F`) — header injection guard                                         |
| **HMAC підпис**                   | Автоматичне підписання запитів; timestamp в межах ±5 хв — захист від replay-атак                                           |
| **Захист від SSRF**               | `validateHttpsUrl()`: лише HTTPS, без localhost/127.x.x.x, без RFC-1918 діапазонів, без IPv6 ULA/link-local/mapped-IPv4   |
| **Без автоперенаправлень**        | `CURLOPT_FOLLOWLOCATION = false` — запити не переходять на інші хости                                                      |
| **Whitelist параметрів**          | `pickAllowed()` фільтрує лише дозволені ключі перед надсиланням на сервер                                                  |
| **Валідація enum-значень**        | `carrier`, `lang`, `type` перевіряються до відправки запиту                                                                |
| **Retry з back-off**              | Повтори тільки на 5xx/мережу з exponential back-off, не на 4xx                                                             |
| **Whitelist HTTP-методів**        | `request()` приймає лише: GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS — захист від method injection                       |
| **Санітизація кастомних заголовків** | Імена та значення `default_headers` і per-request заголовків перевіряються на CR/LF/NUL — захист від header injection   |

### Зберігання ключа

```php
// Правильно — змінна середовища
$client = new APDelivery(getenv('APDELIVERY_API_KEY'));

// Правильно — конфіг-файл поза webroot
$cfg = require '/var/secrets/apdelivery.php';
$client = new APDelivery($cfg['api_key'], ['hmac_secret' => $cfg['hmac_secret']]);

// НЕБЕЗПЕЧНО — не зберігайте ключі у коді або репозиторії
$client = new APDelivery('live_key_abc123...');
```

---

## Ліцензія

MIT License.
