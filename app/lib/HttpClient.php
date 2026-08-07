<?php

declare(strict_types=1);

namespace App\Lib;

use RuntimeException;

/**
 * Minimal outbound HTTP client over cURL.
 *
 * TLS verification is on and not configurable. There is no `$verify`
 * argument to pass false to, because the one place that flag ever gets
 * set is a debugging session that then ships - and this client is used to
 * ask a payment provider for a deposit address.
 */
final class HttpClient
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 15,
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL extension is required for payment provider calls.');
        }

        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('Refusing to make a non-HTTPS outbound request.');
        }

        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $formatted,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // A payment API that answers with a redirect is not something to
            // follow blindly - it could point anywhere.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'BillionsStore/1.0 (+PHP)',
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Outbound request failed: ' . $error);
        }

        return ['status' => $status, 'body' => (string) $response];
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    public static function get(string $url, array $headers = [], int $timeoutSeconds = 15): array
    {
        return self::request('GET', $url, $headers, null, $timeoutSeconds);
    }

    /**
     * @param array<string,mixed> $json
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    public static function postJson(string $url, array $json, array $headers = [], int $timeoutSeconds = 15): array
    {
        $encoded = json_encode($json, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Could not encode request body as JSON.');
        }

        return self::request('POST', $url, $headers + [
            'Content-Type'   => 'application/json',
            'Content-Length' => (string) strlen($encoded),
        ], $encoded, $timeoutSeconds);
    }

    /**
     * Decode a JSON response body, or null if it is not an object/array.
     *
     * @return array<string,mixed>|null
     */
    public static function decode(string $body): ?array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
