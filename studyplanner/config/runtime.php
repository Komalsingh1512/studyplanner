<?php

function loadRuntimeConfig() {
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/runtime.json';
    if (!file_exists($path)) {
        $config = [];
        return $config;
    }

    $json = file_get_contents($path);
    $decoded = json_decode($json, true);
    $config = is_array($decoded) ? $decoded : [];
    return $config;
}

function runtimeConfigValue(array $path, $default = null) {
    $config = loadRuntimeConfig();
    $value = $config;

    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function getGroqApiKey() {
    $envKey = getenv('GROQ_API_KEY');
    if (!empty($envKey)) {
        return trim($envKey);
    }

    return trim((string) runtimeConfigValue(['groq', 'api_key'], ''));
}

function getGroqModel() {
    return trim((string) runtimeConfigValue(['groq', 'model'], 'llama-3.1-8b-instant'));
}

function getGroqApiUrl() {
    return trim((string) runtimeConfigValue(['groq', 'api_url'], 'https://api.groq.com/openai/v1/chat/completions'));
}

function getRealtimeWsUrl() {
    return trim((string) runtimeConfigValue(['realtime', 'ws_url'], 'ws://127.0.0.1:8081'));
}

function getRealtimeInternalBaseUrl() {
    return trim((string) runtimeConfigValue(['realtime', 'internal_base_url'], 'http://127.0.0.1/studyplanner/studyplanner'));
}

function getRealtimeSharedSecret() {
    $envSecret = getenv('STUDYPLANNER_REALTIME_SECRET');
    if (!empty($envSecret)) {
        return trim($envSecret);
    }

    return trim((string) runtimeConfigValue(['realtime', 'shared_secret'], ''));
}

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function signRealtimeToken(array $payload) {
    $secret = getRealtimeSharedSecret();
    $encodedPayload = base64UrlEncode(json_encode($payload));
    $signature = base64UrlEncode(hash_hmac('sha256', $encodedPayload, $secret, true));
    return $encodedPayload . '.' . $signature;
}

function buildRealtimeInternalHeaders($rawBody) {
    $timestamp = (string) time();
    $secret = getRealtimeSharedSecret();
    $signature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

    return [
        'X-Realtime-Timestamp: ' . $timestamp,
        'X-Realtime-Signature: ' . $signature
    ];
}

function verifyRealtimeInternalRequest($rawBody) {
    $timestamp = $_SERVER['HTTP_X_REALTIME_TIMESTAMP'] ?? '';
    $signature = $_SERVER['HTTP_X_REALTIME_SIGNATURE'] ?? '';
    $secret = getRealtimeSharedSecret();

    if ($timestamp === '' || $signature === '' || $secret === '') {
        return false;
    }

    if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    return hash_equals($expected, $signature);
}
?>
