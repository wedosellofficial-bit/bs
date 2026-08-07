<?php

declare(strict_types=1);

/**
 * Configuration. Reads `.env` from the project root (one level above
 * public_html) and returns a flat, dot-addressable array.
 *
 * This file is committed; `.env` is not. Anything secret belongs in
 * `.env` and must be referenced here through env(), never inlined.
 */

$root = dirname(__DIR__);

/**
 * Minimal .env reader.
 *
 * Deliberately not a full dotenv implementation: no variable
 * interpolation, no `export` prefixes, no multiline values. Those
 * features are where dotenv parsers grow surprises, and a config file
 * that silently resolves `${...}` is a config file that can be made to
 * leak one value into another.
 *
 * Values are read into a static array rather than putenv()/$_ENV so they
 * cannot show up in phpinfo(), a stack trace's superglobal dump, or a
 * child process's environment.
 *
 * @return array<string,string>
 */
$readEnvFile = static function (string $path): array {
    if (!is_readable($path)) {
        return [];
    }

    $out = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Strip one layer of matching quotes. Unquoted values may carry a
        // trailing `# comment`; quoted values may legitimately contain #.
        $len = strlen($value);
        if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$len - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        } elseif (str_contains($value, ' #')) {
            $value = rtrim(substr($value, 0, strpos($value, ' #')));
        }

        $out[$key] = $value;
    }

    return $out;
};

$env = $readEnvFile($root . '/.env');

/** Fetch an env value with a typed fallback. */
$get = static function (string $key, string $default = '') use ($env): string {
    $v = $env[$key] ?? '';

    return $v === '' ? $default : $v;
};

$bool = static fn (string $key, bool $default): bool => match (strtolower($get($key, $default ? 'true' : 'false'))) {
    '1', 'true', 'yes', 'on' => true,
    default => false,
};

$int = static fn (string $key, int $default): int => (int) $get($key, (string) $default);

$appEnv = $get('APP_ENV', 'production');

return [
    'app' => [
        'env'        => $appEnv,
        'debug'      => $appEnv !== 'production',
        'name'       => $get('APP_NAME', 'Billions Store'),
        'url'        => rtrim($get('APP_URL', ''), '/'),
        // Signing key for stateless tokens (email verification, password
        // reset) and for encrypting stored TOTP secrets at rest.
        'key'        => base64_decode($get('APP_KEY'), true) ?: '',
        'root'       => $root,
        'storage'    => $root . '/storage',
        'views'      => __DIR__ . '/views',
        'migrations' => __DIR__ . '/migrations',
        // Cache-buster appended to asset URLs. Bump on deploy; falls back
        // to the compiled stylesheet's mtime so a forgotten bump still
        // invalidates.
        'asset_version' => (string) @filemtime($root . '/public_html/assets/css/app.css') ?: '1',
    ],

    'db' => [
        'host'    => $get('DB_HOST', 'localhost'),
        'port'    => $int('DB_PORT', 3306),
        'name'    => $get('DB_NAME'),
        'user'    => $get('DB_USER'),
        'pass'    => $get('DB_PASS'),
        'charset' => $get('DB_CHARSET', 'utf8mb4'),
    ],

    'ledger' => [
        'currency'     => $get('LEDGER_CURRENCY', 'USD'),
        'minor_digits' => $int('LEDGER_CURRENCY_MINOR_DIGITS', 2),
        'topup_min'    => $int('TOPUP_MIN_MINOR', 2500),
        'topup_max'    => $int('TOPUP_MAX_MINOR', 2500000),
    ],

    'chain' => [
        'id'              => $get('CHAIN', 'bitcoin-ordinals'),
        'label'           => 'Bitcoin Ordinals',
        'network'         => $get('BITCOIN_NETWORK', 'mainnet'),
        // Accepted bech32 human-readable parts for buyer payout addresses.
        'address_hrp'     => array_values(array_filter(array_map(
            'trim',
            explode(',', $get('BITCOIN_ADDRESS_HRP', 'bc'))
        ))),
        'require_taproot' => $bool('REQUIRE_TAPROOT_PAYOUT', true),
        'explorer_tx'     => $get('EXPLORER_TX_URL', 'https://mempool.space/tx/%s'),
        'explorer_addr'   => $get('EXPLORER_ADDRESS_URL', 'https://mempool.space/address/%s'),
        'explorer_insc'   => $get('EXPLORER_INSCRIPTION_URL', 'https://ordinals.com/inscription/%s'),
    ],

    'payments' => [
        'provider'        => 'coinbase_commerce',
        'api_url'         => rtrim($get('COINBASE_COMMERCE_API_URL', 'https://api.commerce.coinbase.com'), '/'),
        'api_key'         => $get('COINBASE_COMMERCE_API_KEY'),
        'webhook_secret'  => $get('COINBASE_COMMERCE_WEBHOOK_SECRET'),
        'asset'           => $get('DEPOSIT_ASSET', 'BTC'),
        'required_confs'  => $int('DEPOSIT_REQUIRED_CONFIRMATIONS', 2),
        'quote_lock'      => $int('DEPOSIT_QUOTE_LOCK_SECONDS', 3600),
        'network_fee'     => $int('DEPOSIT_DISCLOSED_NETWORK_FEE_MINOR', 0),
        'fee_bps'         => $int('DEPOSIT_FEE_BPS', 0),
    ],

    'mail' => [
        'transport'  => $get('MAIL_TRANSPORT', 'smtp'),
        'host'       => $get('MAIL_HOST'),
        'port'       => $int('MAIL_PORT', 465),
        'encryption' => $get('MAIL_ENCRYPTION', 'ssl'),
        'user'       => $get('MAIL_USER'),
        'pass'       => $get('MAIL_PASS'),
        'from_addr'  => $get('MAIL_FROM_ADDRESS', 'no-reply@localhost'),
        'from_name'  => $get('MAIL_FROM_NAME', 'Billions Store'),
    ],

    'cron' => [
        'token' => $get('CRON_TOKEN'),
    ],

    'session' => [
        'name'             => 'bsid',
        'absolute_timeout' => $int('SESSION_ABSOLUTE_TIMEOUT', 28800),
        'idle_timeout'     => $int('SESSION_IDLE_TIMEOUT', 7200),
        // Only honour a forwarded-for style header if it is named here.
        // Empty means "use REMOTE_ADDR only", which is the safe default:
        // an attacker who can set their own X-Forwarded-For gets a fresh
        // rate-limit bucket per request.
        'ip_header'        => $get('TRUSTED_PROXY_IP_HEADER'),
    ],

    'seed' => [
        'admin_email'    => $get('SEED_ADMIN_EMAIL'),
        'admin_password' => $get('SEED_ADMIN_PASSWORD'),
    ],
];
