<?php

/**
 * APDelivery PHP Library
 *
 * PHP client for the APDelivery API (https://api.apdelivery.site/)
 * Compatible with PHP 5.6 – 8.4
 *
 * @version  1.0.0
 * @license  MIT
 * @link     https://api.apdelivery.site/
 */

if (!defined('APDELIVERY_LOADED')) {
    define('APDELIVERY_LOADED', true);
}

// ─────────────────────────────────────────────
//  Exception hierarchy
// ─────────────────────────────────────────────

class APDeliveryException extends RuntimeException {}

class APDeliveryHttpException extends APDeliveryException
{
    /** @var int */
    private $statusCode;

    /** @var array|null */
    private $responseBody;

    /**
     * @param string     $message
     * @param int        $statusCode
     * @param array|null $responseBody
     */
    public function __construct($message, $statusCode = 0, $responseBody = null)
    {
        parent::__construct($message, $statusCode);
        $this->statusCode   = $statusCode;
        $this->responseBody = $responseBody;
    }

    /** @return int */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /** @return array|null */
    public function getResponseBody()
    {
        return $this->responseBody;
    }
}

class APDeliveryValidationException extends APDeliveryException {}
class APDeliveryAuthException       extends APDeliveryHttpException {}

// ─────────────────────────────────────────────
//  Main client
// ─────────────────────────────────────────────

class APDelivery
{
    const VERSION      = '1.0.0';
    const API_BASE_URL = 'https://api.apdelivery.site';

    // HTTP methods
    const METHOD_GET    = 'GET';
    const METHOD_POST   = 'POST';
    const METHOD_PUT    = 'PUT';
    const METHOD_PATCH  = 'PATCH';
    const METHOD_DELETE = 'DELETE';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    /** @var int  Connect + read timeout in seconds */
    private $timeout;

    /** @var bool  Verify SSL certificate */
    private $sslVerify;

    /** @var array  Extra default headers */
    private $defaultHeaders;

    /** @var array|null  Proxy settings ['host' => '', 'port' => 0, 'user' => '', 'pass' => ''] */
    private $proxy;

    /** @var int  Max retries on transient errors */
    private $maxRetries;

    /** @var array  Last raw HTTP response info */
    private $lastResponseInfo = array();

    /**
     * @param string $apiKey         Your API key / token
     * @param array  $options        Optional settings:
     *   'base_url'        (string)  Override API base URL
     *   'timeout'         (int)     Request timeout in seconds  (default 30)
     *   'ssl_verify'      (bool)    Verify SSL certificate      (default true)
     *   'default_headers' (array)   Additional request headers
     *   'proxy'           (array)   Proxy configuration
     *   'max_retries'     (int)     Retry count on 5xx / network errors (default 1)
     *
     * @throws APDeliveryValidationException
     */
    public function __construct($apiKey, array $options = array())
    {
        $this->setApiKey($apiKey);

        $this->baseUrl        = isset($options['base_url'])        ? rtrim($this->sanitizeUrl($options['base_url']), '/')  : self::API_BASE_URL;
        $this->timeout        = isset($options['timeout'])         ? (int) $options['timeout']         : 30;
        $this->sslVerify      = isset($options['ssl_verify'])      ? (bool) $options['ssl_verify']      : true;
        $this->defaultHeaders = isset($options['default_headers']) ? (array) $options['default_headers'] : array();
        $this->proxy          = isset($options['proxy'])           ? (array) $options['proxy']           : null;
        $this->maxRetries     = isset($options['max_retries'])     ? max(0, (int) $options['max_retries']) : 1;

        if (!function_exists('curl_init')) {
            throw new APDeliveryException('The cURL extension is required but not loaded.');
        }
    }

    // ─────────────────────────────────────────
    //  API key management
    // ─────────────────────────────────────────

    /**
     * Replace the API key at runtime.
     *
     * @param string $apiKey
     * @return $this
     * @throws APDeliveryValidationException
     */
    public function setApiKey($apiKey)
    {
        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new APDeliveryValidationException('API key must be a non-empty string.');
        }
        // Reject keys that contain control characters / newlines (header injection guard)
        if (preg_match('/[\x00-\x1F\x7F]/', $apiKey)) {
            throw new APDeliveryValidationException('API key contains invalid characters.');
        }
        $this->apiKey = $apiKey;
        return $this;
    }

    // ─────────────────────────────────────────
    //  Generic HTTP helpers (public surface)
    // ─────────────────────────────────────────

    /**
     * Perform a GET request.
     *
     * @param string $endpoint  e.g. '/api/v1/cities'
     * @param array  $params    Query-string parameters
     * @param array  $headers   Additional request headers
     * @return array            Decoded JSON response
     */
    public function get($endpoint, array $params = array(), array $headers = array())
    {
        return $this->request(self::METHOD_GET, $endpoint, array(), $params, $headers);
    }

    /**
     * Perform a POST request.
     *
     * @param string $endpoint
     * @param array  $body     Request body (will be JSON-encoded)
     * @param array  $headers
     * @return array
     */
    public function post($endpoint, array $body = array(), array $headers = array())
    {
        return $this->request(self::METHOD_POST, $endpoint, $body, array(), $headers);
    }

    /**
     * Perform a PUT request.
     *
     * @param string $endpoint
     * @param array  $body
     * @param array  $headers
     * @return array
     */
    public function put($endpoint, array $body = array(), array $headers = array())
    {
        return $this->request(self::METHOD_PUT, $endpoint, $body, array(), $headers);
    }

    /**
     * Perform a PATCH request.
     *
     * @param string $endpoint
     * @param array  $body
     * @param array  $headers
     * @return array
     */
    public function patch($endpoint, array $body = array(), array $headers = array())
    {
        return $this->request(self::METHOD_PATCH, $endpoint, $body, array(), $headers);
    }

    /**
     * Perform a DELETE request.
     *
     * @param string $endpoint
     * @param array  $body
     * @param array  $headers
     * @return array
     */
    public function delete($endpoint, array $body = array(), array $headers = array())
    {
        return $this->request(self::METHOD_DELETE, $endpoint, $body, array(), $headers);
    }

    /** @return array  cURL info from the last request */
    public function getLastResponseInfo()
    {
        return $this->lastResponseInfo;
    }

    // ─────────────────────────────────────────
    //  Delivery-specific convenience methods
    // ─────────────────────────────────────────

    // --- Reference data ---

    /**
     * Get list of cities / settlements.
     *
     * @param array $filters  e.g. ['name' => 'Kyiv', 'page' => 1, 'per_page' => 50]
     * @return array
     */
    public function getCities(array $filters = array())
    {
        return $this->get('/api/v1/cities', $filters);
    }

    /**
     * Get a single city by its ID.
     *
     * @param int|string $cityId
     * @return array
     */
    public function getCity($cityId)
    {
        return $this->get('/api/v1/cities/' . $this->sanitizeId($cityId));
    }

    /**
     * Get list of regions / oblasts.
     *
     * @param array $filters
     * @return array
     */
    public function getRegions(array $filters = array())
    {
        return $this->get('/api/v1/regions', $filters);
    }

    /**
     * Get warehouses / post offices / branches.
     *
     * @param array $filters  e.g. ['city_id' => 123, 'page' => 1]
     * @return array
     */
    public function getWarehouses(array $filters = array())
    {
        return $this->get('/api/v1/warehouses', $filters);
    }

    /**
     * Get a single warehouse by its ID.
     *
     * @param int|string $warehouseId
     * @return array
     */
    public function getWarehouse($warehouseId)
    {
        return $this->get('/api/v1/warehouses/' . $this->sanitizeId($warehouseId));
    }

    /**
     * Get available delivery services / tariff types.
     *
     * @return array
     */
    public function getDeliveryServices()
    {
        return $this->get('/api/v1/services');
    }

    // --- Tariff / price calculation ---

    /**
     * Calculate shipping cost.
     *
     * @param array $data  Required fields depend on the API, typically:
     *   'from_city_id'    (int)
     *   'to_city_id'      (int)
     *   'weight'          (float)  kg
     *   'width'           (float)  cm
     *   'height'          (float)  cm
     *   'length'          (float)  cm
     *   'service_type'    (string)
     *   'declared_value'  (float)
     * @return array
     */
    public function calculateShipping(array $data)
    {
        $this->requireFields($data, array('from_city_id', 'to_city_id', 'weight'));
        return $this->post('/api/v1/calculate', $data);
    }

    // --- Orders ---

    /**
     * Create a new shipment / order.
     *
     * @param array $data
     * @return array
     */
    public function createOrder(array $data)
    {
        $this->requireFields($data, array('from_city_id', 'to_city_id', 'sender', 'recipient'));
        return $this->post('/api/v1/orders', $data);
    }

    /**
     * Get an order by its ID.
     *
     * @param int|string $orderId
     * @return array
     */
    public function getOrder($orderId)
    {
        return $this->get('/api/v1/orders/' . $this->sanitizeId($orderId));
    }

    /**
     * Update an existing order.
     *
     * @param int|string $orderId
     * @param array      $data
     * @return array
     */
    public function updateOrder($orderId, array $data)
    {
        return $this->put('/api/v1/orders/' . $this->sanitizeId($orderId), $data);
    }

    /**
     * Cancel an order.
     *
     * @param int|string $orderId
     * @return array
     */
    public function cancelOrder($orderId)
    {
        return $this->delete('/api/v1/orders/' . $this->sanitizeId($orderId));
    }

    /**
     * List orders for the authenticated account.
     *
     * @param array $filters  e.g. ['status' => 'pending', 'page' => 1, 'per_page' => 20]
     * @return array
     */
    public function getOrders(array $filters = array())
    {
        return $this->get('/api/v1/orders', $filters);
    }

    // --- Tracking ---

    /**
     * Track a shipment by its tracking number.
     *
     * @param string $trackingNumber
     * @return array
     */
    public function trackShipment($trackingNumber)
    {
        if (!is_string($trackingNumber) || trim($trackingNumber) === '') {
            throw new APDeliveryValidationException('Tracking number must be a non-empty string.');
        }
        return $this->get('/api/v1/tracking/' . rawurlencode(trim($trackingNumber)));
    }

    /**
     * Get tracking history for a shipment.
     *
     * @param string $trackingNumber
     * @return array
     */
    public function getTrackingHistory($trackingNumber)
    {
        if (!is_string($trackingNumber) || trim($trackingNumber) === '') {
            throw new APDeliveryValidationException('Tracking number must be a non-empty string.');
        }
        return $this->get('/api/v1/tracking/' . rawurlencode(trim($trackingNumber)) . '/history');
    }

    // --- Shipping labels ---

    /**
     * Get a shipping label (PDF / ZPL URL or base64).
     *
     * @param int|string $orderId
     * @param string     $format  'pdf' | 'zpl' | 'png'
     * @return array
     */
    public function getLabel($orderId, $format = 'pdf')
    {
        $allowedFormats = array('pdf', 'zpl', 'png');
        if (!in_array($format, $allowedFormats, true)) {
            throw new APDeliveryValidationException('Label format must be one of: ' . implode(', ', $allowedFormats));
        }
        return $this->get('/api/v1/orders/' . $this->sanitizeId($orderId) . '/label', array('format' => $format));
    }

    // --- Webhooks ---

    /**
     * Register a webhook endpoint.
     *
     * @param array $data  e.g. ['url' => 'https://…', 'events' => ['order.created', 'order.delivered']]
     * @return array
     */
    public function createWebhook(array $data)
    {
        $this->requireFields($data, array('url', 'events'));
        $this->sanitizeUrl($data['url']);   // validates URL format
        return $this->post('/api/v1/webhooks', $data);
    }

    /**
     * List registered webhooks.
     *
     * @return array
     */
    public function getWebhooks()
    {
        return $this->get('/api/v1/webhooks');
    }

    /**
     * Delete a webhook.
     *
     * @param int|string $webhookId
     * @return array
     */
    public function deleteWebhook($webhookId)
    {
        return $this->delete('/api/v1/webhooks/' . $this->sanitizeId($webhookId));
    }

    // --- Account ---

    /**
     * Get authenticated account / profile information.
     *
     * @return array
     */
    public function getProfile()
    {
        return $this->get('/api/v1/profile');
    }

    /**
     * Get account balance.
     *
     * @return array
     */
    public function getBalance()
    {
        return $this->get('/api/v1/balance');
    }

    // ─────────────────────────────────────────
    //  Core HTTP request engine
    // ─────────────────────────────────────────

    /**
     * Execute an HTTP request with optional retry logic.
     *
     * @param string $method
     * @param string $endpoint
     * @param array  $body
     * @param array  $queryParams
     * @param array  $extraHeaders
     * @return array
     * @throws APDeliveryException
     */
    public function request($method, $endpoint, array $body = array(), array $queryParams = array(), array $extraHeaders = array())
    {
        $method   = strtoupper($method);
        $endpoint = '/' . ltrim($endpoint, '/');
        $url      = $this->baseUrl . $endpoint;

        if (!empty($queryParams)) {
            $url .= '?' . $this->buildQuery($queryParams);
        }

        $headers = $this->buildHeaders($extraHeaders);
        $attempt = 0;

        do {
            $attempt++;
            list($responseBody, $httpCode, $curlError) = $this->executeCurl($method, $url, $headers, $body);

            // Retry on network error or 5xx (up to $maxRetries)
            $isTransient = ($curlError !== '' || ($httpCode >= 500 && $httpCode < 600));
            if ($isTransient && $attempt <= $this->maxRetries) {
                // exponential back-off: 0.5s, 1s, 2s …
                $sleep = (int) round(500000 * pow(2, $attempt - 1));
                usleep(min($sleep, 4000000)); // cap at 4 s
                continue;
            }
            break;
        } while (true);

        if ($curlError !== '') {
            throw new APDeliveryException('cURL error: ' . $curlError);
        }

        return $this->handleResponse($httpCode, $responseBody);
    }

    // ─────────────────────────────────────────
    //  Internal helpers
    // ─────────────────────────────────────────

    /**
     * @param string $method
     * @param string $url
     * @param array  $headers
     * @param array  $body
     * @return array  [responseBody (string), httpCode (int), curlError (string)]
     */
    private function executeCurl($method, $url, array $headers, array $body)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL,            $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeout));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // no redirects – security
        curl_setopt($ch, CURLOPT_MAXREDIRS,      0);
        curl_setopt($ch, CURLOPT_USERAGENT,      'APDelivery-PHP/' . self::VERSION . ' PHP/' . PHP_VERSION);

        // SSL
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->sslVerify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->sslVerify ? 2 : 0);

        // Proxy
        if ($this->proxy !== null) {
            $host = isset($this->proxy['host']) ? $this->proxy['host'] : '';
            $port = isset($this->proxy['port']) ? (int) $this->proxy['port'] : 0;
            if ($host !== '') {
                curl_setopt($ch, CURLOPT_PROXY,     $host);
                curl_setopt($ch, CURLOPT_PROXYPORT, $port);
            }
            if (isset($this->proxy['user']) && $this->proxy['user'] !== '') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->proxy['user'] . ':' . (isset($this->proxy['pass']) ? $this->proxy['pass'] : ''));
            }
        }

        // Method & body
        switch ($method) {
            case self::METHOD_GET:
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;

            case self::METHOD_POST:
                curl_setopt($ch, CURLOPT_POST, true);
                if (!empty($body)) {
                    $payload = $this->encodeJson($body);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                }
                break;

            case self::METHOD_PUT:
            case self::METHOD_PATCH:
            case self::METHOD_DELETE:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if (!empty($body)) {
                    $payload = $this->encodeJson($body);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                }
                break;

            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        // Headers – set after body so Content-Length is correct
        $headerLines = array();
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);

        $raw       = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        $this->lastResponseInfo = curl_getinfo($ch);
        curl_close($ch);

        return array($raw === false ? '' : $raw, $httpCode, $curlError);
    }

    /**
     * Decode and validate an HTTP response.
     *
     * @param int    $httpCode
     * @param string $responseBody
     * @return array
     * @throws APDeliveryHttpException
     * @throws APDeliveryAuthException
     */
    private function handleResponse($httpCode, $responseBody)
    {
        $decoded = null;
        if ($responseBody !== '') {
            $decoded = json_decode($responseBody, true);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return is_array($decoded) ? $decoded : array('raw' => $responseBody);
        }

        $message = $this->extractErrorMessage($decoded, $responseBody, $httpCode);

        if ($httpCode === 401 || $httpCode === 403) {
            throw new APDeliveryAuthException($message, $httpCode, $decoded);
        }

        throw new APDeliveryHttpException($message, $httpCode, $decoded);
    }

    /**
     * @param mixed  $decoded
     * @param string $rawBody
     * @param int    $httpCode
     * @return string
     */
    private function extractErrorMessage($decoded, $rawBody, $httpCode)
    {
        if (is_array($decoded)) {
            foreach (array('message', 'error', 'error_message', 'description', 'detail') as $key) {
                if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                    return $decoded[$key];
                }
            }
        }
        return 'HTTP ' . $httpCode . ' – ' . substr($rawBody, 0, 200);
    }

    /**
     * Build the full headers array for a request.
     *
     * @param array $extra
     * @return array  [Header-Name => value, …]
     */
    private function buildHeaders(array $extra)
    {
        $headers = array(
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'X-Api-Version' => '1',
        );

        foreach ($this->defaultHeaders as $name => $value) {
            $headers[$name] = $value;
        }
        foreach ($extra as $name => $value) {
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * Safely JSON-encode a value.
     *
     * @param mixed $data
     * @return string
     * @throws APDeliveryException
     */
    private function encodeJson($data)
    {
        $flags = 0;
        if (defined('JSON_UNESCAPED_UNICODE')) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }
        if (defined('JSON_UNESCAPED_SLASHES')) {
            $flags |= JSON_UNESCAPED_SLASHES;
        }

        $json = json_encode($data, $flags);
        if ($json === false) {
            throw new APDeliveryException('Failed to JSON-encode request body: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * Build a URL query string from an array, recursively.
     *
     * @param array $params
     * @return string
     */
    private function buildQuery(array $params)
    {
        return http_build_query($params, '', '&');
    }

    /**
     * Sanitize and validate a URL to prevent SSRF.
     * Only https:// is allowed; internal/private ranges are rejected.
     *
     * @param string $url
     * @return string
     * @throws APDeliveryValidationException
     */
    private function sanitizeUrl($url)
    {
        if (!is_string($url) || trim($url) === '') {
            throw new APDeliveryValidationException('URL must be a non-empty string.');
        }

        $url = trim($url);

        // Must be HTTPS
        if (stripos($url, 'https://') !== 0) {
            throw new APDeliveryValidationException('Only HTTPS URLs are allowed. Got: ' . $url);
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new APDeliveryValidationException('Invalid URL: ' . $url);
        }

        $host = strtolower($parts['host']);

        // Block localhost / loopback variants
        $blocked = array('localhost', '127.0.0.1', '::1', '0.0.0.0');
        if (in_array($host, $blocked, true)) {
            throw new APDeliveryValidationException('URL host is not allowed: ' . $host);
        }

        // Block private / link-local IPv4 ranges (SSRF protection)
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $privateRanges = array(
                array('10.0.0.0',    'AF_CIDR_10'),
                array('172.16.0.0',  'AF_CIDR_172'),
                array('192.168.0.0', 'AF_CIDR_192'),
                array('169.254.0.0', 'AF_CIDR_LINK'),
            );
            $ipLong = ip2long($host);
            if (
                ($ipLong >= ip2long('10.0.0.0')    && $ipLong <= ip2long('10.255.255.255'))   ||
                ($ipLong >= ip2long('172.16.0.0')   && $ipLong <= ip2long('172.31.255.255'))   ||
                ($ipLong >= ip2long('192.168.0.0')  && $ipLong <= ip2long('192.168.255.255'))  ||
                ($ipLong >= ip2long('169.254.0.0')  && $ipLong <= ip2long('169.254.255.255'))
            ) {
                throw new APDeliveryValidationException('Private/link-local IP addresses are not allowed: ' . $host);
            }
        }

        return $url;
    }

    /**
     * Sanitize a resource ID (integer or alphanumeric slug).
     *
     * @param int|string $id
     * @return string
     * @throws APDeliveryValidationException
     */
    private function sanitizeId($id)
    {
        if (is_int($id) || ctype_digit((string) $id)) {
            return (string) (int) $id;
        }
        // Allow UUIDs and alphanumeric slugs
        if (is_string($id) && preg_match('/^[a-zA-Z0-9\-_]{1,64}$/', $id)) {
            return $id;
        }
        throw new APDeliveryValidationException('Invalid resource ID: ' . print_r($id, true));
    }

    /**
     * Assert that required keys exist and are non-empty in $data.
     *
     * @param array    $data
     * @param string[] $required
     * @throws APDeliveryValidationException
     */
    private function requireFields(array $data, array $required)
    {
        $missing = array();
        foreach ($required as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            throw new APDeliveryValidationException(
                'Missing required field(s): ' . implode(', ', $missing)
            );
        }
    }
}
