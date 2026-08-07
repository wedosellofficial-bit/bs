<?php

declare(strict_types=1);

/**
 * Self-tests for the pure logic that has no business being wrong:
 * address validation, money parsing, webhook signature verification,
 * and ledger arithmetic.
 *
 * Run with:  php bin/test.php
 *
 * These are deliberately dependency-free (no PHPUnit) so they can be run
 * on a bare PHP install, including over SSH on a host that has one - and
 * so that "did the address validator survive that refactor" is always
 * one command away.
 *
 * Tests that need a database are skipped automatically when no
 * connection is configured; everything here except the ledger group is
 * pure and always runs.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Lib\Base58Check;
use App\Lib\Bech32;
use App\Lib\Fmt;
use App\Ordinals;
use App\Payments;

final class Tap
{
    private int $count = 0;
    private int $failed = 0;
    private string $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
        echo "\n# {$name}\n";
    }

    public function ok(bool $condition, string $description): void
    {
        $this->count++;
        if ($condition) {
            echo "ok {$this->count} - {$description}\n";

            return;
        }

        $this->failed++;
        echo "not ok {$this->count} - {$description}\n";
    }

    public function same(mixed $actual, mixed $expected, string $description): void
    {
        $pass = $actual === $expected;
        $this->ok($pass, $description);
        if (!$pass) {
            printf(
                "  # expected: %s\n  # actual:   %s\n",
                var_export($expected, true),
                var_export($actual, true)
            );
        }
    }

    public function throws(callable $fn, string $description): void
    {
        try {
            $fn();
            $this->ok(false, $description);
        } catch (Throwable) {
            $this->ok(true, $description);
        }
    }

    public function finish(): never
    {
        printf("\n1..%d\n", $this->count);
        printf("# %d passed, %d failed\n", $this->count - $this->failed, $this->failed);
        exit($this->failed === 0 ? 0 : 1);
    }
}

$t = new Tap();

//---------------------------------------------------------------------
// bech32 / bech32m, against the BIP-173 and BIP-350 test vectors.
//---------------------------------------------------------------------
$t->group('Bech32 / BIP-350 valid address vectors');

/** @var array<string,array{0:string,1:int,2:string}> address => [hrp, witness version, program hex] */
$validVectors = [
    'BC1QW508D6QEJXTDG4Y5R3ZARVARY0C5XW7KV8F3T4'
        => ['bc', 0, '751e76e8199196d454941c45d1b3a323f1433bd6'],
    'tb1qrp33g0q5c5txsp9arysrx4k6zdkfs4nce4xj0gdcccefvpysxf3q0sl5k7'
        => ['tb', 0, '1863143c14c5166804bd19203356da136c985678cd4d27a1b8c6329604903262'],
    'bc1pw508d6qejxtdg4y5r3zarvary0c5xw7kw508d6qejxtdg4y5r3zarvary0c5xw7kt5nd6y'
        => ['bc', 1, '751e76e8199196d454941c45d1b3a323f1433bd6751e76e8199196d454941c45d1b3a323f1433bd6'],
    'BC1SW50QGDZ25J'
        => ['bc', 16, '751e'],
    'bc1zw508d6qejxtdg4y5r3zarvaryvaxxpcs'
        => ['bc', 2, '751e76e8199196d454941c45d1b3a323'],
    'tb1pqqqqp399et2xygdj5xreqhjjvcmzhxw4aywxecjdzew6hylgvsesf3hn0c'
        => ['tb', 1, '000000c4a5cad46221b2a187905e5266362b99d5e91c6ce24d165dab93e86433'],
    'bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0'
        => ['bc', 1, '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798'],
];

foreach ($validVectors as $address => [$hrp, $version, $programHex]) {
    $decoded = Bech32::decodeSegwit($address, [$hrp]);
    $t->ok($decoded !== null, "decodes {$address}");
    if ($decoded !== null) {
        $t->same($decoded['version'], $version, "  witness version {$version}");
        $t->same(bin2hex($decoded['program']), $programHex, '  witness program matches');
    }
}

$t->group('Bech32 / invalid address vectors');

$invalidVectors = [
    // BIP-350 invalid list, plus the mutations that matter most in practice.
    'an84characterslonghumanreadablepartthatcontainsthetheexcludedcharactersbioandnumber11d6pts4'
        => 'over 90 characters',
    'bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj1'
        => 'single mutated final character fails the checksum',
    'bc1P0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0'
        => 'mixed case is rejected',
    'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t5'
        => 'v0 address with a bech32m checksum',
    'bc1pw5dgrnzv'
        => 'witness program shorter than 2 bytes',
    'bc1rw5uspcuh'
        => 'invalid program length for the witness version',
    'BC13W508D6QEJXTDG4Y5R3ZARVARY0C5XW7KN40WF2'
        => 'witness version above 16',
    'bc1gmk9yu'
        => 'empty data section',
    'tb1qrp33g0q5c5txsp9arysrx4k6zdkfs4nce4xj0gdcccefvpysxf3pjxtptv'
        => 'non-zero padding in the 8-to-5 bit conversion',
    'bc1zw508d6qejxtdg4y5r3zarvaryvqyzf3du'
        => 'zero padding of more than four bits',
    'BC1QR508D6QEJXTDG4Y5R3ZARVARYV98GJ9P'
        => 'program length invalid for witness version 0',
    'tc1qw508d6qejxtdg4y5r3zarvary0c5xw7kg3g4ty'
        => 'unrecognised human-readable part',
    ''  => 'empty string',
    'bc1' => 'separator with no data',
];

foreach ($invalidVectors as $address => $why) {
    $t->ok(
        Bech32::decodeSegwit($address, ['bc', 'tb']) === null,
        'rejects: ' . $why
    );
}

$t->ok(
    Bech32::decodeSegwit('tb1qrp33g0q5c5txsp9arysrx4k6zdkfs4nce4xj0gdcccefvpysxf3q0sl5k7', ['bc']) === null,
    'rejects a testnet address when only mainnet hrp is allowed'
);

//---------------------------------------------------------------------
// Payout policy: taproot only.
//---------------------------------------------------------------------
$t->group('Ordinals payout address policy');

$taproot = 'bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0';
$segwitV0 = 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4';
$legacy = '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa';
$p2sh = '3J98t1WpEZ73CNmQviecrnyiWrnqRhWNLy';

$result = Ordinals::validatePayoutAddress($taproot);
$t->ok($result['ok'], 'accepts a taproot address');
$t->same($result['kind'], 'taproot', '  classified as taproot');

$result = Ordinals::validatePayoutAddress(strtoupper($taproot));
$t->ok($result['ok'], 'accepts the uppercase form of a taproot address');
$t->same($result['normalized'], $taproot, '  normalises to lowercase for storage');

$result = Ordinals::validatePayoutAddress($segwitV0);
$t->ok(!$result['ok'], 'rejects a SegWit v0 address');
$t->same($result['kind'], 'segwit-v0', '  classified as segwit-v0');
$t->ok(str_contains($result['error'], 'bc1p'), '  error names the address prefix to use instead');

$result = Ordinals::validatePayoutAddress(
    'bc1pw508d6qejxtdg4y5r3zarvary0c5xw7kw508d6qejxtdg4y5r3zarvary0c5xw7kt5nd6y'
);
$t->ok(!$result['ok'], 'rejects witness v1 with a non-32-byte program');

foreach ([$legacy => 'P2PKH', $p2sh => 'P2SH'] as $addr => $label) {
    $result = Ordinals::validatePayoutAddress($addr);
    $t->ok(!$result['ok'], "rejects a legacy {$label} address");
    $t->same($result['kind'], 'legacy', "  classified as legacy, not a typo ({$label})");
}

$result = Ordinals::validatePayoutAddress("bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3\nk2e72q4k9hcz7vqzk5jj0");
$t->ok(!$result['ok'], 'rejects an address containing a line break');
$t->ok(str_contains($result['error'], 'single unbroken'), '  and says why rather than stripping it');

$result = Ordinals::validatePayoutAddress('bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj9');
$t->ok(!$result['ok'], 'rejects a bc1p address with a bad checksum');
$t->ok(str_contains($result['error'], 'checksum'), '  error mentions the checksum, not the format');

//---------------------------------------------------------------------
$t->group('Base58Check');

$t->ok(Base58Check::decode($legacy) !== null, 'decodes a known P2PKH address');
$t->same(Base58Check::decode($legacy)['version'] ?? -1, 0, '  version byte 0x00');
$t->same(strlen(Base58Check::decode($legacy)['payload'] ?? ''), 20, '  20-byte hash160 payload');
$t->same(Base58Check::decode($p2sh)['version'] ?? -1, 5, 'P2SH decodes with version byte 0x05');
$t->ok(Base58Check::decode('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNb') === null, 'rejects a mutated checksum');
$t->ok(Base58Check::decode('1A1zP1eP5QGefi2DMPTfTL5SLmv70ivfNa') === null, 'rejects a character outside the alphabet');
$t->ok(Base58Check::decode('') === null, 'rejects an empty string');

//---------------------------------------------------------------------
$t->group('Inscription identifiers');

$insc = '6fb976ab49dcec017f1e201e84395983204ae1a7c2abf7ced0a85d692e442799i0';
$t->ok(Ordinals::isValidInscriptionId($insc), 'accepts a well-formed inscription id');
$t->ok(Ordinals::isValidInscriptionId(strtoupper($insc)), 'accepts uppercase hex');
$t->ok(Ordinals::isValidInscriptionId(substr($insc, 0, -1) . '42'), 'accepts a multi-digit index');
$t->ok(!Ordinals::isValidInscriptionId(substr($insc, 0, 63) . 'i0'), 'rejects a short txid');
$t->ok(!Ordinals::isValidInscriptionId(str_replace('i0', '', $insc)), 'rejects a missing index suffix');
$t->ok(!Ordinals::isValidInscriptionId(str_replace('i0', 'i01', $insc)), 'rejects a zero-padded index');
$t->ok(!Ordinals::isValidInscriptionId('zz' . substr($insc, 2)), 'rejects non-hex characters');
$t->same(Ordinals::txidFromInscriptionId($insc), substr($insc, 0, 64), 'extracts the reveal txid');
$t->same(Ordinals::explorerTxUrl('not-a-txid'), '', 'refuses to build an explorer URL from junk');
$t->ok(str_contains(Ordinals::explorerInscriptionUrl($insc), $insc), 'builds an inscription explorer URL');

//---------------------------------------------------------------------
$t->group('Money parsing and formatting');

$t->same(Fmt::parseMoneyToMinor('250'), 25000, 'parses a whole amount');
$t->same(Fmt::parseMoneyToMinor('250.00'), 25000, 'parses an explicit .00');
$t->same(Fmt::parseMoneyToMinor('1,250.75'), 125075, 'parses a thousands separator');
$t->same(Fmt::parseMoneyToMinor('$99.9'), 9990, 'parses one decimal place and a symbol');
$t->same(Fmt::parseMoneyToMinor('0.01'), 1, 'parses the smallest unit');
$t->throws(static fn () => Fmt::parseMoneyToMinor('12.345'), 'rejects three decimal places');
$t->throws(static fn () => Fmt::parseMoneyToMinor('abc'), 'rejects non-numeric input');
$t->throws(static fn () => Fmt::parseMoneyToMinor(''), 'rejects an empty amount');
$t->throws(static fn () => Fmt::parseMoneyToMinor('1.2.3'), 'rejects two decimal points');

$t->same(Fmt::money(125075), '$1,250.75', 'formats minor units with a separator');
$t->same(Fmt::money(-4200), '-$42.00', 'formats a debit with a leading minus');
$t->same(Fmt::moneySigned(4200), '+$42.00', 'formats a credit with an explicit plus');
$t->same(Fmt::money(0), '$0.00', 'formats zero');

$t->same(Fmt::btcToSats('0.00042'), 42000, 'converts BTC to satoshis');
$t->same(Fmt::btcToSats('1'), 100000000, 'converts a whole BTC');
$t->same(Fmt::btcToSats('0.00000001'), 1, 'converts one satoshi');
$t->same(Fmt::satsToBtc(42000), '0.00042000', 'converts satoshis back to a fixed-8 string');
$t->same(Fmt::satsToBtc(100000001), '1.00000001', 'keeps precision above 1 BTC');
$t->throws(static fn () => Fmt::btcToSats('0.000000001'), 'rejects sub-satoshi precision instead of rounding it');
$t->throws(static fn () => Fmt::btcToSats('1e-8'), 'rejects scientific notation');

// Round-trip: no amount may change value passing through both directions.
$roundTripOk = true;
foreach (['0.00000001', '0.10000000', '1.00000000', '21.00000000', '0.00042000'] as $btc) {
    if (Fmt::satsToBtc(Fmt::btcToSats($btc)) !== $btc) {
        $roundTripOk = false;
    }
}
$t->ok($roundTripOk, 'BTC <-> satoshi conversion round-trips exactly');

//---------------------------------------------------------------------
$t->group('Identifier truncation');

$t->same(
    Fmt::truncateMiddle('bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0', 8, 6),
    "bc1p0xlx\u{2026}zk5jj0",
    'middle-truncates a long address'
);
$t->same(Fmt::truncateMiddle('short', 8, 6), 'short', 'leaves a short value alone');
$t->same(Fmt::truncateMiddle('', 8, 6), '', 'leaves an empty value alone');

//---------------------------------------------------------------------
// Webhook signature verification. This is the check that decides whether
// an anonymous HTTP request is allowed to create money.
//---------------------------------------------------------------------
$t->group('Coinbase Commerce webhook signatures');

$secret = 'whsec_test_only_not_a_real_secret';
$body = '{"event":{"type":"charge:confirmed","data":{"id":"abc"}}}';
$goodSig = hash_hmac('sha256', $body, $secret);

$t->ok(Payments::verifyWebhookSignature($body, $goodSig, $secret), 'accepts a correct signature');
$t->ok(Payments::verifyWebhookSignature($body, strtoupper($goodSig), $secret), 'accepts an uppercase hex signature');
$t->ok(!Payments::verifyWebhookSignature($body, $goodSig, 'wrong-secret'), 'rejects a signature from the wrong secret');
$t->ok(!Payments::verifyWebhookSignature($body . ' ', $goodSig, $secret), 'rejects a body altered by one byte');
$t->ok(!Payments::verifyWebhookSignature($body, '', $secret), 'rejects a missing signature');
$t->ok(!Payments::verifyWebhookSignature($body, 'deadbeef', $secret), 'rejects a truncated signature');
$t->ok(!Payments::verifyWebhookSignature($body, $goodSig, ''), 'rejects verification against an empty secret');
$t->ok(
    !Payments::verifyWebhookSignature('{"event":{"type":"charge:confirmed"}}', $goodSig, $secret),
    'rejects a replayed signature against a different body'
);

//---------------------------------------------------------------------
$t->group('Deposit credit arithmetic');

// A 1% fee on a $100 deposit credits $99.00, and the fee is floored so
// rounding never favours the customer at the store's expense (or the
// reverse - it must be deterministic either way).
$t->same(Payments::creditableMinor(10000, 0), 10000, 'no fee credits the full amount');
$t->same(Payments::creditableMinor(10000, 100), 9900, '1% fee on $100.00 credits $99.00');
$t->same(Payments::creditableMinor(999, 100), 990, 'fee rounds in the store\'s favour, deterministically');
$t->same(Payments::creditableMinor(1, 100), 1, 'a 1-cent deposit is never reduced to zero');
$t->same(Payments::creditableMinor(0, 100), 0, 'a zero deposit credits zero');

$t->finish();
