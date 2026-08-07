<?php

declare(strict_types=1);

namespace App\Lib;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Formatting and parsing for the two kinds of number this application
 * cares about: fiat minor units (signed integers) and BTC amounts
 * (decimal strings, never floats).
 *
 * BTC is handled as a string throughout. 0.1 + 0.2 in IEEE-754 is not
 * 0.3, and a satoshi lost to rounding in a deposit credit is a support
 * ticket you cannot answer.
 */
final class Fmt
{
    public const SATOSHIS_PER_BTC = 100_000_000;

    /** Placeholder for "no value" in tables and detail rows. */
    public const EM_DASH = "\u{2014}";

    /** HTML-escape. Every rendered value goes through this. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Fiat minor units -> display string, e.g. 123456 -> "$1,234.56".
     * Negative amounts render with a leading minus, not parentheses;
     * a ledger statement is read by humans, not accountants.
     */
    public static function money(int $minor, bool $withSymbol = true): string
    {
        $digits = Config::int('ledger.minor_digits', 2);
        $currency = Config::string('ledger.currency', 'USD');
        $divisor = 10 ** $digits;

        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        $formatted = number_format($abs / $divisor, $digits, '.', ',');

        if (!$withSymbol) {
            return $sign . $formatted;
        }

        $symbol = match ($currency) {
            'USD' => '$',
            'EUR' => "\u{20AC}",
            'GBP' => "\u{A3}",
            default => '',
        };

        return $symbol !== ''
            ? $sign . $symbol . $formatted
            : $sign . $formatted . ' ' . $currency;
    }

    /** Signed display for ledger rows: "+$50.00" / "-$1,200.00". */
    public static function moneySigned(int $minor): string
    {
        return ($minor >= 0 ? '+' : '') . self::money($minor);
    }

    /**
     * Parse a user-entered fiat amount ("1,250" / "1250.00" / "$1250")
     * into minor units.
     *
     * @throws InvalidArgumentException if the input is not a clean amount.
     */
    public static function parseMoneyToMinor(string $input): int
    {
        $digits = Config::int('ledger.minor_digits', 2);

        $clean = preg_replace('/[^0-9.]/', '', $input) ?? '';
        if ($clean === '' || substr_count($clean, '.') > 1) {
            throw new InvalidArgumentException('Enter an amount like 250.00');
        }

        [$whole, $frac] = array_pad(explode('.', $clean, 2), 2, '');

        if ($whole === '' && $frac === '') {
            throw new InvalidArgumentException('Enter an amount like 250.00');
        }
        if (strlen($frac) > $digits) {
            throw new InvalidArgumentException(sprintf(
                'Amounts can have at most %d decimal place%s.',
                $digits,
                $digits === 1 ? '' : 's'
            ));
        }
        if (strlen($whole) > 12) {
            throw new InvalidArgumentException('That amount is too large.');
        }

        $frac = str_pad($frac, $digits, '0');

        return (int) ($whole === '' ? '0' : $whole) * (10 ** $digits) + (int) ($frac === '' ? '0' : $frac);
    }

    /**
     * Satoshis -> BTC decimal string, 8 dp, trailing zeros kept.
     * Kept as integer arithmetic on purpose (see class docblock).
     */
    public static function satsToBtc(int $sats): string
    {
        $sign = $sats < 0 ? '-' : '';
        $abs = abs($sats);

        return $sign . intdiv($abs, self::SATOSHIS_PER_BTC) . '.'
            . str_pad((string) ($abs % self::SATOSHIS_PER_BTC), 8, '0', STR_PAD_LEFT);
    }

    /**
     * BTC decimal string -> satoshis. Rejects more than 8 decimal places
     * rather than rounding, because rounding a provider-supplied amount is
     * how a deposit gets credited for more than was sent.
     */
    public static function btcToSats(string $btc): int
    {
        $btc = trim($btc);
        if (!preg_match('/^-?\d{1,10}(\.\d{1,8})?$/', $btc)) {
            throw new InvalidArgumentException("Not a valid BTC amount: {$btc}");
        }

        $negative = str_starts_with($btc, '-');
        $btc = ltrim($btc, '-');

        [$whole, $frac] = array_pad(explode('.', $btc, 2), 2, '');
        $frac = str_pad($frac, 8, '0');

        $sats = (int) $whole * self::SATOSHIS_PER_BTC + (int) $frac;

        return $negative ? -$sats : $sats;
    }

    /** Display a BTC amount with the trailing zeros trimmed but at least 8dp kept for exactness. */
    public static function btc(string $btc): string
    {
        return rtrim(rtrim($btc, '0'), '.') === '' ? '0' : $btc;
    }

    /**
     * Middle-truncate an identifier: bc1qy0abc...zxr8df
     *
     * Used for every address, txid and inscription id in the UI. Short
     * values are returned untouched - truncating something that already
     * fits just makes it harder to read.
     */
    public static function truncateMiddle(string $value, int $head = 8, int $tail = 6): string
    {
        $len = mb_strlen($value);
        if ($len <= $head + $tail + 3) {
            return $value;
        }

        // U+2026 HORIZONTAL ELLIPSIS - one glyph, so it never wraps mid-way.
        return mb_substr($value, 0, $head) . "\u{2026}" . mb_substr($value, -$tail);
    }

    /** UTC timestamp string -> "5 Aug 2026, 19:48 UTC". */
    public static function dateTime(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return self::EM_DASH;
        }

        $dt = self::parse($utc);

        return $dt === null ? self::EM_DASH : $dt->format('j M Y, H:i') . ' UTC';
    }

    public static function date(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return self::EM_DASH;
        }

        $dt = self::parse($utc);

        return $dt === null ? self::EM_DASH : $dt->format('j M Y');
    }

    /** Machine-readable form for <time datetime="..."> */
    public static function iso(?string $utc): string
    {
        $dt = $utc === null ? null : self::parse($utc);

        return $dt?->format('c') ?? '';
    }

    /** "3 minutes ago", "in 42 minutes". */
    public static function relative(?string $utc): string
    {
        $dt = $utc === null ? null : self::parse($utc);
        if ($dt === null) {
            return self::EM_DASH;
        }

        $delta = $dt->getTimestamp() - time();
        $future = $delta > 0;
        $secs = abs($delta);

        $phrase = match (true) {
            $secs < 45     => 'just now',
            $secs < 5400   => self::plural((int) round($secs / 60), 'minute'),
            $secs < 129600 => self::plural((int) round($secs / 3600), 'hour'),
            default        => self::plural((int) round($secs / 86400), 'day'),
        };

        if ($phrase === 'just now') {
            return $phrase;
        }

        return $future ? 'in ' . $phrase : $phrase . ' ago';
    }

    private static function plural(int $n, string $unit): string
    {
        return $n . ' ' . $unit . ($n === 1 ? '' : 's');
    }

    private static function parse(string $utc): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Build a query string from a filter array, dropping empties so a
     * shareable URL does not accumulate `&sort=&min=` noise.
     *
     * @param array<string,mixed> $params
     */
    public static function queryString(array $params): string
    {
        $clean = [];

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean === [] ? '' : '?' . http_build_query($clean);
    }
}
