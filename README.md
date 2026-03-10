# APDelivery PHP Library

PHP-клієнт для роботи з [APDelivery API](https://api.apdelivery.site/).
Один файл, без зовнішніх залежностей. Підтримує **PHP 5.6 – 8.4**.

---

## Зміст

- [Вимоги](#вимоги)
- [Встановлення](#встановлення)
- [Швидкий старт](#швидкий-старт)
- [Конфігурація](#конфігурація)
- [Методи API](#методи-api)
  - [Довідники](#довідники)
  - [Розрахунок вартості](#розрахунок-вартості)
  - [Замовлення](#замовлення)
  - [Відстеження](#відстеження)
  - [Мітки / Накладні](#мітки--накладні)
  - [Вебхуки](#вебхуки)
  - [Обліковий запис](#обліковий-запис)
  - [Довільні запити](#довільні-запити)
- [Обробка помилок](#обробка-помилок)
- [Безпека](#безпека)
- [Ліцензія](#ліцензія)

---

## Вимоги

| Вимога          | Версія      |
|-----------------|-------------|
| PHP             | 5.6 – 8.4   |
| Розширення cURL | будь-яке    |

---

## Встановлення

### Варіант 1 — один файл (рекомендовано)

Скопіюйте `APDelivery.php` до вашого проекту та підключіть:

```php
require_once '/path/to/APDelivery.php';
```

### Варіант 2 — через Composer (якщо підключено репозиторій)

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/your-org/apdelivery_php_library"
        }
    ],
    "require": {
        "your-org/apdelivery": "^1.0"
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

// Отримати список міст
$cities = $client->getCities(['name' => 'Київ']);
print_r($cities);

// Відстежити посилку
$tracking = $client->trackShipment('1234567890');
print_r($tracking);
```

---

## Конфігурація

Конструктор приймає API-ключ та масив опцій:

```php
$client = new APDelivery('YOUR_API_KEY', [
    'timeout'         => 30,          // Таймаут запиту, сек (за замовчуванням: 30)
    'ssl_verify'      => true,        // Перевіряти SSL-сертифікат (за замовчуванням: true)
    'max_retries'     => 1,           // Повторні спроби при помилках 5xx / мережі (за замовчуванням: 1)
    'base_url'        => 'https://api.apdelivery.site', // Базова URL (за замовчуванням)
    'default_headers' => [
        'X-Custom-Header' => 'value', // Додаткові заголовки до кожного запиту
    ],
    'proxy'           => [            // Налаштування проксі (необов'язково)
        'host' => 'proxy.example.com',
        'port' => 8080,
        'user' => 'proxyuser',        // необов'язково
        'pass' => 'proxypass',        // необов'язково
    ],
]);
```

### Зміна API-ключа під час роботи

```php
$client->setApiKey('NEW_API_KEY');
```

---

## Методи API

### Довідники

#### Міста

```php
// Список усіх міст
$client->getCities();

// Пошук міст з фільтрами
$client->getCities([
    'name'     => 'Харків',
    'page'     => 1,
    'per_page' => 50,
]);

// Отримати місто за ID
$client->getCity(123);
```

#### Регіони

```php
$client->getRegions();
$client->getRegions(['page' => 1, 'per_page' => 25]);
```

#### Відділення / склади

```php
// Список усіх відділень
$client->getWarehouses();

// Відділення в конкретному місті
$client->getWarehouses(['city_id' => 123]);

// Конкретне відділення
$client->getWarehouse(456);
```

#### Послуги доставки

```php
$client->getDeliveryServices();
```

---

### Розрахунок вартості

```php
$price = $client->calculateShipping([
    'from_city_id'   => 1,       // ID міста відправника
    'to_city_id'     => 2,       // ID міста отримувача
    'weight'         => 2.5,     // кг
    'width'          => 30,      // см
    'height'         => 20,      // см
    'length'         => 40,      // см
    'service_type'   => 'standard',
    'declared_value' => 500.00,  // грн
]);

echo $price['total_cost'];
```

---

### Замовлення

#### Створити замовлення

```php
$order = $client->createOrder([
    'from_city_id' => 1,
    'to_city_id'   => 2,
    'sender' => [
        'name'  => 'Іван Іванов',
        'phone' => '+380991234567',
    ],
    'recipient' => [
        'name'         => 'Петро Петров',
        'phone'        => '+380997654321',
        'warehouse_id' => 456,
    ],
    'cargo' => [
        'weight'          => 1.5,
        'declared_value'  => 300,
        'description'     => 'Електроніка',
    ],
    'service_type'  => 'standard',
    'payment_payer' => 'sender',   // 'sender' | 'recipient'
]);

echo $order['order_id'];
```

#### Отримати замовлення

```php
$order = $client->getOrder('ORD-001234');
```

#### Список замовлень

```php
$orders = $client->getOrders([
    'status'   => 'pending',
    'page'     => 1,
    'per_page' => 20,
]);
```

#### Оновити замовлення

```php
$client->updateOrder('ORD-001234', [
    'recipient' => [
        'phone' => '+380661112233',
    ],
]);
```

#### Скасувати замовлення

```php
$client->cancelOrder('ORD-001234');
```

---

### Відстеження

```php
// Поточний статус
$status = $client->trackShipment('1234567890');

// Повна історія переміщень
$history = $client->getTrackingHistory('1234567890');
foreach ($history['events'] as $event) {
    echo $event['date'] . ' — ' . $event['status'] . "\n";
}
```

---

### Мітки / Накладні

```php
// PDF (за замовчуванням)
$label = $client->getLabel('ORD-001234');
file_put_contents('label.pdf', base64_decode($label['data']));

// ZPL для термопринтерів
$label = $client->getLabel('ORD-001234', 'zpl');

// PNG
$label = $client->getLabel('ORD-001234', 'png');
```

---

### Вебхуки

```php
// Реєстрація вебхука
$webhook = $client->createWebhook([
    'url'    => 'https://yoursite.com/webhooks/delivery',
    'events' => ['order.created', 'order.delivered', 'order.cancelled'],
]);

// Список вебхуків
$client->getWebhooks();

// Видалити вебхук
$client->deleteWebhook(789);
```

---

### Обліковий запис

```php
// Дані профілю
$profile = $client->getProfile();
echo $profile['name'];

// Баланс рахунку
$balance = $client->getBalance();
echo $balance['amount'] . ' ' . $balance['currency'];
```

---

### Довільні запити

Якщо API має ендпоінти, яких ще немає в бібліотеці, використовуйте generic-методи:

```php
// GET
$result = $client->get('/api/v1/some-endpoint', ['param' => 'value']);

// POST
$result = $client->post('/api/v1/some-endpoint', ['key' => 'value']);

// PUT
$result = $client->put('/api/v1/resource/123', ['field' => 'newvalue']);

// PATCH
$result = $client->patch('/api/v1/resource/123', ['field' => 'newvalue']);

// DELETE
$result = $client->delete('/api/v1/resource/123');
```

---

## Обробка помилок

Бібліотека використовує ієрархію виключень:

| Клас                            | Коли виникає                                            |
|---------------------------------|----------------------------------------------------------|
| `APDeliveryException`           | Базовий клас; загальні помилки (відсутній cURL тощо)    |
| `APDeliveryValidationException` | Невірні параметри, відсутні обов'язкові поля            |
| `APDeliveryHttpException`       | HTTP-відповідь з кодом ≥ 400                            |
| `APDeliveryAuthException`       | HTTP 401 / 403 — помилка автентифікації                 |

```php
<?php
require_once 'APDelivery.php';

try {
    $client = new APDelivery('YOUR_API_KEY');
    $order  = $client->getOrder('ORD-INVALID');

} catch (APDeliveryAuthException $e) {
    // Невірний або прострочений API-ключ
    echo 'Помилка автентифікації: ' . $e->getMessage();
    echo ' | HTTP-код: ' . $e->getStatusCode();

} catch (APDeliveryHttpException $e) {
    // Будь-яка інша HTTP-помилка (404, 422, 500 тощо)
    echo 'HTTP-помилка ' . $e->getStatusCode() . ': ' . $e->getMessage();
    $body = $e->getResponseBody(); // масив із тіла відповіді або null

} catch (APDeliveryValidationException $e) {
    // Невірні вхідні дані ще до відправки запиту
    echo 'Помилка валідації: ' . $e->getMessage();

} catch (APDeliveryException $e) {
    // Все інше (помилка cURL, проблема кодування JSON тощо)
    echo 'Помилка бібліотеки: ' . $e->getMessage();
}
```

### Діагностика останнього запиту

```php
$info = $client->getLastResponseInfo();
echo 'Total time: ' . $info['total_time'] . 's' . "\n";
echo 'HTTP code: '  . $info['http_code']  . "\n";
```

---

## Безпека

Бібліотека розроблена з урахуванням таких заходів захисту:

| Захист                        | Реалізація                                                                              |
|-------------------------------|-----------------------------------------------------------------------------------------|
| **Перевірка SSL**             | `ssl_verify => true` за замовчуванням; не вимикайте у production                       |
| **Валідація API-ключа**       | Відхиляє порожні рядки та ключі з керуючими символами (захист від header injection)    |
| **Захист від SSRF**           | `sanitizeUrl()` дозволяє лише HTTPS і відхиляє localhost, приватні та link-local IP    |
| **Без автоперенаправлень**    | `CURLOPT_FOLLOWLOCATION = false` — запити не переходять на інші хости                  |
| **Санітизація ID ресурсів**   | Лише цілі числа або UUID/slug — неможливо вставити зайве у URL-шлях                   |
| **Обов'язкові поля**          | `requireFields()` перевіряє вхідні дані до відправки запиту                            |
| **Безпечне кодування URL**    | `rawurlencode` для шляху, `http_build_query` для рядка запиту                          |

### Рекомендації зі зберігання ключа

```php
// Правильно — читати ключ зі змінної середовища
$client = new APDelivery(getenv('APDELIVERY_API_KEY'));

// Правильно — або з конфіг-файлу поза webroot
$config = require '/var/secrets/apdelivery.php';
$client = new APDelivery($config['api_key']);

// НЕБЕЗПЕЧНО — жорстко закодований ключ у коді/репозиторії
$client = new APDelivery('live_abc123secretkey');
```

---

## Ліцензія

MIT License.