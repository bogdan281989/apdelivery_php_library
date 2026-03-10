<?php

/**
 * APDelivery PHP Library
 *
 * PHP-клієнт для APDelivery API (https://api.apdelivery.site/)
 * Географічні довідники України та відділення поштових перевізників.
 *
 * Підтримувані перевізники: novaposhta, ukrposhta, meest, rozetka
 *
 * Сумісність: PHP 5.6 – 8.4
 * Залежності: тільки розширення cURL (стандартне)
 *
 * @version 2.0.0
 * @license MIT
 * @link    https://api.apdelivery.site/
 */

if (!defined('APDELIVERY_LOADED')) {
    define('APDELIVERY_LOADED', true);
}

// ─────────────────────────────────────────────────────────────────────────────
//  Exception hierarchy
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Base exception for all APDelivery errors.
 */
class APDeliveryException extends RuntimeException {}

/**
 * Thrown when an HTTP response with status ≥ 400 is received.
 */
class APDeliveryHttpException extends APDeliveryException
{
    /** @var int */
    private $statusCode;

    /** @var string|null  API error code from response body (e.g. "RATE_LIMIT_EXCEEDED") */
    private $apiCode;

    /** @var array|null */
    private $responseBody;

    /**
     * @param string     $message
     * @param int        $statusCode
     * @param string     $apiCode
     * @param array|null $responseBody
     */
    public function __construct($message, $statusCode = 0, $apiCode = '', $responseBody = null)
    {
        parent::__construct($message, $statusCode);
        $this->statusCode   = $statusCode;
        $this->apiCode      = $apiCode;
        $this->responseBody = $responseBody;
    }

    /** @return int */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /** @return string */
    public function getApiCode()
    {
        return (string) $this->apiCode;
    }

    /** @return array|null */
    public function getResponseBody()
    {
        return $this->responseBody;
    }
}

/**
 * Thrown on HTTP 401 / 403 — authentication or authorisation failure.
 */
class APDeliveryAuthException extends APDeliveryHttpException {}

/**
 * Thrown on HTTP 429 — daily rate limit exceeded.
 */
class APDeliveryRateLimitException extends APDeliveryHttpException {}

/**
 * Thrown when client-side input validation fails before sending the request.
 */
class APDeliveryValidationException extends APDeliveryException {}

// ─────────────────────────────────────────────────────────────────────────────
//  Main client
// ─────────────────────────────────────────────────────────────────────────────

/**
 * APDelivery API client.
 *
 * Usage (simple Bearer auth):
 *   $client = new APDelivery('YOUR_API_KEY');
 *   $regions = $client->getRegions(['lang' => 'ua']);
 *
 * Usage (HMAC auth):
 *   $client = new APDelivery('YOUR_API_KEY', ['hmac_secret' => 'YOUR_SECRET']);
 *   $cities = $client->getCities(['search' => 'Київ']);
 */
class APDelivery
{
    const VERSION      = '2.0.0';
    const API_BASE_URL = 'https://api.apdelivery.site';

    /** Allowed carrier identifiers */
    const CARRIER_NOVA_POSHTA = 'novaposhta';
    const CARRIER_UKRPOSHTA   = 'ukrposhta';
    const CARRIER_MEEST       = 'meest';
    const CARRIER_ROZETKA     = 'rozetka';

    /** Allowed warehouse type filters */
    const WAREHOUSE_TYPE_POSTOMAT = 'postomat';
    const WAREHOUSE_TYPE_BRANCH   = 'branch';

    /** Allowed language codes */
    const LANG_UA = 'ua';
    const LANG_EN = 'en';

    // ── Internal state ────────────────────────────────────────────────────────

    /** @var string */
    private $apiKey;

    /** @var string|null  HMAC secret; null = plain Bearer auth */
    private $hmacSecret;

    /** @var string */
    private $baseUrl;

    /** @var int  Timeout in seconds */
    private $timeout;

    /** @var bool */
    private $sslVerify;

    /** @var array  Extra headers added to every request */
    private $defaultHeaders;

    /** @var array|null  Proxy config ['host','port','user','pass'] */
    private $proxy;

    /** @var int  Max retries on transient 5xx / network errors */
    private $maxRetries;

    /** @var array  curl_getinfo() data from the last executed request */
    private $lastResponseInfo = array();

    // ── Allowed-values whitelist (security guard) ─────────────────────────────

    private static $validCarriers      = array('novaposhta', 'ukrposhta', 'meest', 'rozetka');
    private static $validLangs         = array('ua', 'en');
    private static $validWarehouseTypes = array('postomat', 'branch');

    // ─────────────────────────────────────────────────────────────────────────
    //  Constructor
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param string $apiKey   Your API key (Bearer token).
     * @param array  $options  Optional settings:
     *   'hmac_secret'     (string)  HMAC secret for restriction_type=hmac keys.
     *   'base_url'        (string)  Override API base URL.
     *   'timeout'         (int)     Total request timeout in seconds (default 30).
     *   'ssl_verify'      (bool)    Verify SSL certificate (default true).
     *   'default_headers' (array)   Extra HTTP headers added to every request.
     *   'proxy'           (array)   Proxy: ['host','port','user','pass'].
     *   'max_retries'     (int)     Retries on 5xx / network errors (default 1).
     *
     * @throws APDeliveryException           If cURL is not available.
     * @throws APDeliveryValidationException If the API key is invalid.
     */
    public function __construct($apiKey, array $options = array())
    {
        if (!function_exists('curl_init')) {
            throw new APDeliveryException('The cURL extension is required but is not loaded.');
        }

        $this->setApiKey($apiKey);

        $hmacSecret = isset($options['hmac_secret']) ? $options['hmac_secret'] : null;
        if ($hmacSecret !== null) {
            $this->setHmacSecret($hmacSecret);
        }

        $this->baseUrl        = isset($options['base_url'])        ? rtrim($this->validateHttpsUrl($options['base_url']), '/') : self::API_BASE_URL;
        $this->timeout        = isset($options['timeout'])         ? max(1, (int) $options['timeout'])                          : 30;
        $this->sslVerify      = isset($options['ssl_verify'])      ? (bool) $options['ssl_verify']                              : true;
        $this->defaultHeaders = isset($options['default_headers']) ? (array) $options['default_headers']                        : array();
        $this->proxy          = isset($options['proxy'])           ? (array) $options['proxy']                                   : null;
        $this->maxRetries     = isset($options['max_retries'])     ? max(0, (int) $options['max_retries'])                       : 1;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Key / secret management
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Replace the API key at runtime.
     *
     * @param  string $apiKey
     * @return $this
     * @throws APDeliveryValidationException
     */
    public function setApiKey($apiKey)
    {
        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new APDeliveryValidationException('API key must be a non-empty string.');
        }
        // Guard against HTTP header injection
        if (preg_match('/[\x00-\x1F\x7F]/', $apiKey)) {
            throw new APDeliveryValidationException('API key contains invalid control characters.');
        }
        $this->apiKey = $apiKey;
        return $this;
    }

    /**
     * Set or replace the HMAC secret at runtime.
     *
     * @param  string $secret
     * @return $this
     * @throws APDeliveryValidationException
     */
    public function setHmacSecret($secret)
    {
        if (!is_string($secret) || trim($secret) === '') {
            throw new APDeliveryValidationException('HMAC secret must be a non-empty string.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $secret)) {
            throw new APDeliveryValidationException('HMAC secret contains invalid control characters.');
        }
        $this->hmacSecret = $secret;
        return $this;
    }

    /**
     * Disable HMAC mode and fall back to plain Bearer auth.
     *
     * @return $this
     */
    public function disableHmac()
    {
        $this->hmacSecret = null;
        return $this;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Geography endpoints
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /v1/regions — List of Ukrainian oblasts.
     *
     * @param array $params  Optional filters:
     *   'uuid'    (string)  UUID of a specific region.
     *   'search'  (string)  Search by name.
     *   'lang'    (string)  'ua' (default) or 'en'.
     *   'carrier' (string)  Filter to regions that have branches of this carrier.
     *
     * @return array  {'success': true, 'data': Region[], 'meta': {'total': int}}
     */
    public function getRegions(array $params = array())
    {
        $params = $this->filterGeoParams($params, false);
        return $this->get('/v1/regions', $params);
    }

    /**
     * GET /v1/districts — List of Ukrainian districts (raions).
     *
     * @param array $params  Optional filters:
     *   'uuid'        (string)   UUID of a specific district.
     *   'region_uuid' (string)   Filter by region UUID.
     *   'region_id'   (int)      Filter by region ID.
     *   'search'      (string)   Search by name.
     *   'lang'        (string)   'ua' (default) or 'en'.
     *   'carrier'     (string)   Filter by carrier.
     *   'page'        (int)      Page number (default 1).
     *   'limit'       (int)      Results per page, max 100 (default 50).
     *
     * @return array  {'success': true, 'data': District[], 'meta': PaginationMeta}
     */
    public function getDistricts(array $params = array())
    {
        $params = $this->filterGeoParams($params, true);
        return $this->get('/v1/districts', $params);
    }

    /**
     * GET /v1/cities — List of cities / settlements.
     * Supports full-text search (min 3 characters).
     *
     * @param array $params  Optional filters:
     *   'uuid'          (string)  UUID of a specific city.
     *   'region_uuid'   (string)  Filter by region.
     *   'region_id'     (int)     Filter by region ID.
     *   'district_uuid' (string)  Filter by district.
     *   'district_id'   (int)     Filter by district ID.
     *   'search'        (string)  Full-text search (≥ 3 chars).
     *   'lang'          (string)  'ua' (default) or 'en'.
     *   'carrier'       (string)  Filter by carrier.
     *   'page'          (int)     Page number.
     *   'limit'         (int)     Results per page, max 100.
     *
     * @return array  {'success': true, 'data': City[], 'meta': PaginationMeta}
     */
    public function getCities(array $params = array())
    {
        $params = $this->filterGeoParams($params, true);
        return $this->get('/v1/cities', $params);
    }

    /**
     * GET /v1/streets — List of streets in a city.
     * Either city_uuid or city_id is required.
     *
     * @param array $params  Parameters:
     *   'city_uuid' (string) *required*  UUID of the city (or city_id).
     *   'city_id'   (int)               City ID (alternative to city_uuid).
     *   'uuid'      (string)            UUID of a specific street.
     *   'search'    (string)            Full-text search (≥ 3 chars).
     *   'lang'      (string)            'ua' (default) or 'en'.
     *   'page'      (int)               Page number.
     *   'limit'     (int)               Results per page, max 100.
     *
     * @return array  {'success': true, 'data': Street[], 'meta': PaginationMeta}
     * @throws APDeliveryValidationException If neither city_uuid nor city_id is provided.
     */
    public function getStreets(array $params = array())
    {
        if (empty($params['city_uuid']) && empty($params['city_id'])) {
            throw new APDeliveryValidationException(
                'Either "city_uuid" or "city_id" is required to fetch streets.'
            );
        }

        $allowed = array('city_uuid', 'city_id', 'uuid', 'search', 'lang', 'page', 'limit');
        $params  = $this->pickAllowed($params, $allowed);
        $params  = $this->sanitizeLang($params);
        $params  = $this->sanitizePagination($params);

        return $this->get('/v1/streets', $params);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Warehouse / branch endpoints
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /v1/warehouses — Branches and post-machines of a carrier in a city.
     * Both carrier and city_uuid are required.
     *
     * @param array $params  Parameters:
     *   'carrier'   (string) *required*  One of: novaposhta, ukrposhta, meest, rozetka.
     *   'city_uuid' (string) *required*  UUID of the city.
     *   'uuid'      (string)             UUID of a specific branch.
     *   'number'    (string)             Branch number.
     *   'postcode'  (string)             Postcode (ukrposhta only).
     *   'type'      (string)             'postomat' or 'branch'.
     *   'search'    (string)             Search by name or address.
     *   'page'      (int)                Page number.
     *   'limit'     (int)                Results per page, max 100.
     *
     * @return array  {'success': true, 'data': Warehouse[], 'meta': {..., 'carrier': string}}
     * @throws APDeliveryValidationException If required params are missing or invalid.
     */
    public function getWarehouses(array $params = array())
    {
        if (empty($params['carrier'])) {
            throw new APDeliveryValidationException(
                '"carrier" is required. Allowed values: ' . implode(', ', self::$validCarriers)
            );
        }
        if (empty($params['city_uuid'])) {
            throw new APDeliveryValidationException('"city_uuid" is required to fetch warehouses.');
        }

        if (!in_array($params['carrier'], self::$validCarriers, true)) {
            throw new APDeliveryValidationException(
                'Invalid carrier "' . $params['carrier'] . '". Allowed: ' . implode(', ', self::$validCarriers)
            );
        }

        if (isset($params['type']) && !in_array($params['type'], self::$validWarehouseTypes, true)) {
            throw new APDeliveryValidationException(
                'Invalid warehouse type "' . $params['type'] . '". Allowed: postomat, branch'
            );
        }

        $allowed = array('carrier', 'city_uuid', 'uuid', 'number', 'postcode', 'type', 'search', 'page', 'limit');
        $params  = $this->pickAllowed($params, $allowed);
        $params  = $this->sanitizePagination($params);

        return $this->get('/v1/warehouses', $params);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Service endpoint
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /v1/info — API version, endpoint list, and current key rate-limit status.
     *
     * @return array  {'success': true, 'data': {'api_version', 'endpoints', 'rate_limit'}}
     */
    public function getInfo()
    {
        return $this->get('/v1/info');
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Generic HTTP helpers (public)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Perform a GET request to any endpoint.
     *
     * @param string $endpoint  e.g. '/v1/cities'
     * @param array  $params    Query-string parameters.
     * @param array  $headers   Additional headers for this request only.
     * @return array
     */
    public function get($endpoint, array $params = array(), array $headers = array())
    {
        return $this->request('GET', $endpoint, array(), $params, $headers);
    }

    /**
     * Perform a POST request.
     *
     * @param string $endpoint
     * @param array  $body     JSON-encoded request body.
     * @param array  $headers
     * @return array
     */
    public function post($endpoint, array $body = array(), array $headers = array())
    {
        return $this->request('POST', $endpoint, $body, array(), $headers);
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
        return $this->request('PUT', $endpoint, $body, array(), $headers);
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
        return $this->request('PATCH', $endpoint, $body, array(), $headers);
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
        return $this->request('DELETE', $endpoint, $body, array(), $headers);
    }

    /**
     * Return cURL info array from the last executed request.
     *
     * @return array
     */
    public function getLastResponseInfo()
    {
        return $this->lastResponseInfo;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Core request engine
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Execute an HTTP request with retry logic.
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
        $path     = '/' . ltrim($endpoint, '/');
        $query    = !empty($queryParams) ? '?' . http_build_query($queryParams, '', '&') : '';
        $url      = $this->baseUrl . $path . $query;
        $headers  = $this->buildHeaders($method, $path . $query, $extraHeaders);

        $attempt = 0;
        $curlError = '';
        $httpCode  = 0;
        $rawBody   = '';

        do {
            $attempt++;
            list($rawBody, $httpCode, $curlError) = $this->executeCurl($method, $url, $headers, $body);

            $isTransient = ($curlError !== '') || ($httpCode >= 500 && $httpCode < 600);
            if ($isTransient && $attempt <= $this->maxRetries) {
                // exponential back-off: 500 ms, 1 s, 2 s …  cap at 4 s
                $sleepUs = (int) round(500000 * pow(2, $attempt - 1));
                usleep(min($sleepUs, 4000000));
                continue;
            }
            break;
        } while (true);

        if ($curlError !== '') {
            throw new APDeliveryException('cURL error: ' . $curlError);
        }

        return $this->parseResponse($httpCode, $rawBody);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build HTTP headers, including HMAC signature when a secret is set.
     *
     * @param string $method       HTTP method (GET, POST, …)
     * @param string $pathAndQuery Full path including query string, e.g. "/v1/regions?lang=ua"
     * @param array  $extra        Per-request extra headers.
     * @return array  [Header-Name => value]
     */
    private function buildHeaders($method, $pathAndQuery, array $extra)
    {
        $headers = array(
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        );

        // HMAC mode: add X-Timestamp and X-Signature
        if ($this->hmacSecret !== null) {
            $timestamp = (string) time();
            $stringToSign = $timestamp . "\n" . strtoupper($method) . "\n" . $pathAndQuery;
            $signature    = hash_hmac('sha256', $stringToSign, $this->hmacSecret);

            $headers['X-Timestamp'] = $timestamp;
            $headers['X-Signature'] = $signature;
        }

        foreach ($this->defaultHeaders as $name => $value) {
            $headers[$name] = $value;
        }
        foreach ($extra as $name => $value) {
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * Execute a single cURL call.
     *
     * @param string $method
     * @param string $url
     * @param array  $headers  [Name => value]
     * @param array  $body
     * @return array  [rawBody (string), httpCode (int), curlError (string)]
     */
    private function executeCurl($method, $url, array $headers, array $body)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL,            $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeout));
        // Never follow redirects — prevents open-redirect attacks
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS,      0);
        curl_setopt($ch, CURLOPT_USERAGENT,      'APDelivery-PHP/' . self::VERSION . ' PHP/' . PHP_VERSION);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->sslVerify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->sslVerify ? 2 : 0);

        // Proxy
        if ($this->proxy !== null && !empty($this->proxy['host'])) {
            curl_setopt($ch, CURLOPT_PROXY,     $this->proxy['host']);
            curl_setopt($ch, CURLOPT_PROXYPORT, isset($this->proxy['port']) ? (int) $this->proxy['port'] : 80);
            if (!empty($this->proxy['user'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD,
                    $this->proxy['user'] . ':' . (isset($this->proxy['pass']) ? $this->proxy['pass'] : ''));
            }
        }

        // Method and body
        switch ($method) {
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if (!empty($body)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $this->encodeJson($body));
                }
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if (!empty($body)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $this->encodeJson($body));
                }
        }

        // Build header array for cURL
        $headerLines = array();
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);

        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        $this->lastResponseInfo = curl_getinfo($ch);
        curl_close($ch);

        return array($raw === false ? '' : $raw, $httpCode, $error);
    }

    /**
     * Parse and validate an HTTP response.
     *
     * @param int    $httpCode
     * @param string $rawBody
     * @return array
     * @throws APDeliveryAuthException
     * @throws APDeliveryRateLimitException
     * @throws APDeliveryHttpException
     */
    private function parseResponse($httpCode, $rawBody)
    {
        $decoded = null;
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            if (is_array($decoded)) {
                return $decoded;
            }
            return array('raw' => $rawBody);
        }

        // Extract error info from { "success": false, "error": { "message": "...", "code": "..." } }
        $message = 'HTTP ' . $httpCode;
        $apiCode = '';

        if (is_array($decoded) && isset($decoded['error'])) {
            $err     = $decoded['error'];
            $message = !empty($err['message']) ? $err['message'] : $message;
            $apiCode = !empty($err['code'])    ? $err['code']    : '';
        } elseif ($rawBody !== '') {
            $message .= ' — ' . substr($rawBody, 0, 200);
        }

        if ($httpCode === 401 || $httpCode === 403) {
            throw new APDeliveryAuthException($message, $httpCode, $apiCode, $decoded);
        }

        if ($httpCode === 429) {
            throw new APDeliveryRateLimitException($message, $httpCode, $apiCode, $decoded);
        }

        throw new APDeliveryHttpException($message, $httpCode, $apiCode, $decoded);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Parameter helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Filter and sanitize common geographic endpoint parameters.
     *
     * @param array $params
     * @param bool  $withPagination
     * @return array
     */
    private function filterGeoParams(array $params, $withPagination)
    {
        $allowed = array('uuid', 'region_uuid', 'region_id', 'district_uuid', 'district_id',
                         'search', 'lang', 'carrier');
        if ($withPagination) {
            $allowed[] = 'page';
            $allowed[] = 'limit';
        }

        $params = $this->pickAllowed($params, $allowed);
        $params = $this->sanitizeLang($params);
        if ($withPagination) {
            $params = $this->sanitizePagination($params);
        }
        if (isset($params['carrier'])) {
            if (!in_array($params['carrier'], self::$validCarriers, true)) {
                throw new APDeliveryValidationException(
                    'Invalid carrier "' . $params['carrier'] . '". Allowed: ' . implode(', ', self::$validCarriers)
                );
            }
        }

        return $params;
    }

    /**
     * Return only keys present in $allowed (whitelist filter).
     *
     * @param array    $params
     * @param string[] $allowed
     * @return array
     */
    private function pickAllowed(array $params, array $allowed)
    {
        $out = array();
        foreach ($allowed as $key) {
            if (isset($params[$key])) {
                $out[$key] = $params[$key];
            }
        }
        return $out;
    }

    /**
     * Validate and normalise the 'lang' parameter.
     *
     * @param array $params
     * @return array
     * @throws APDeliveryValidationException
     */
    private function sanitizeLang(array $params)
    {
        if (isset($params['lang'])) {
            if (!in_array($params['lang'], self::$validLangs, true)) {
                throw new APDeliveryValidationException(
                    'Invalid lang "' . $params['lang'] . '". Allowed: ua, en'
                );
            }
        }
        return $params;
    }

    /**
     * Coerce 'page' and 'limit' to positive integers within API bounds.
     *
     * @param array $params
     * @return array
     */
    private function sanitizePagination(array $params)
    {
        if (isset($params['page'])) {
            $params['page'] = max(1, (int) $params['page']);
        }
        if (isset($params['limit'])) {
            $params['limit'] = min(100, max(1, (int) $params['limit']));
        }
        return $params;
    }

    /**
     * JSON-encode data, throwing on failure.
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
     * Validate that $url is a valid HTTPS URL pointing to a public host.
     * Blocks SSRF vectors: loopback, link-local, private RFC-1918 ranges.
     *
     * @param string $url
     * @return string  The validated URL.
     * @throws APDeliveryValidationException
     */
    private function validateHttpsUrl($url)
    {
        if (!is_string($url) || trim($url) === '') {
            throw new APDeliveryValidationException('URL must be a non-empty string.');
        }

        $url = trim($url);

        if (stripos($url, 'https://') !== 0) {
            throw new APDeliveryValidationException('Only HTTPS URLs are allowed.');
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new APDeliveryValidationException('Invalid URL: ' . $url);
        }

        $host    = strtolower($parts['host']);
        $blocked = array('localhost', '127.0.0.1', '::1', '0.0.0.0');

        if (in_array($host, $blocked, true)) {
            throw new APDeliveryValidationException('URL host is not allowed: ' . $host);
        }

        // Block private IPv4 ranges (SSRF guard)
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($host);
            if (
                ($n >= ip2long('10.0.0.0')    && $n <= ip2long('10.255.255.255'))   ||
                ($n >= ip2long('172.16.0.0')   && $n <= ip2long('172.31.255.255'))   ||
                ($n >= ip2long('192.168.0.0')  && $n <= ip2long('192.168.255.255'))  ||
                ($n >= ip2long('169.254.0.0')  && $n <= ip2long('169.254.255.255'))
            ) {
                throw new APDeliveryValidationException('Private/link-local IP addresses are not allowed.');
            }
        }

        return $url;
    }
}
