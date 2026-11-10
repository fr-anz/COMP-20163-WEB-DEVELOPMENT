<?php

declare(strict_types=1);

namespace Habi\Services;

/**
 * Simulated card gateway for checkout (M5, PRD R13/R14, SDD §9, N3/N4).
 *
 * Validation mirrors a real form gate: a Luhn-valid 13-19 digit card
 * number (spaces tolerated), a strictly future MM/YY expiry and a 3-4
 * digit CVC. Known test cards decide the outcome; every other Luhn-valid
 * number is approved. Valid submissions wait a random 1.5-2.5 s server-side
 * to mimic processing.
 *
 * Results never carry the full card number, the expiry or the CVC - only
 * the outcome and, when approved, the last four digits and brand for
 * display (N4). Nothing is persisted here and no real gateway is contacted.
 */
final class PaymentSimulator
{
    public const RESULT_APPROVED = 'approved';
    public const RESULT_DECLINED = 'declined';
    public const RESULT_INSUFFICIENT_FUNDS = 'insufficient_funds';
    public const RESULT_INVALID = 'invalid';

    /** PRD R14 test cards, PAN without spaces => simulated outcome. */
    private const TEST_CARDS = [
        '4242424242424242' => self::RESULT_APPROVED,
        '4000000000000002' => self::RESULT_DECLINED,
        '4000000000009995' => self::RESULT_INSUFFICIENT_FUNDS,
    ];

    /** Server-side simulated latency bounds, microseconds (PRD R14). */
    private const MIN_DELAY_MICROSECONDS = 1500000;
    private const MAX_DELAY_MICROSECONDS = 2500000;

    /** @var callable(int): void */
    private $sleep;

    /**
     * The sleeper is injectable so tests can observe the delay bounds
     * without real waiting; production uses usleep().
     *
     * @param callable(int): void|null $sleep
     */
    public function __construct(?callable $sleep = null)
    {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * Runs one simulated authorization.
     *
     * @return array{result: string, errors: array<string, string>, last4?: string, brand?: string}
     */
    public function simulate(array $input): array
    {
        $errors = [];

        $cardNumber = $input['card_number'] ?? '';
        $pan = is_string($cardNumber) ? (string) preg_replace('/\s+/', '', $cardNumber) : '';
        if (
            $pan === ''
            || preg_match('/^\d{13,19}$/', $pan) !== 1
            || !$this->passesLuhn($pan)
        ) {
            $errors['card_number'] = 'Enter a valid card number.';
        }

        $expiry = $input['expiry'] ?? '';
        if (!is_string($expiry) || !$this->isFutureExpiry($expiry)) {
            $errors['expiry'] = 'Enter a valid future expiry date as MM/YY.';
        }

        $cvc = $input['cvc'] ?? '';
        if (!is_string($cvc) || preg_match('/^\d{3,4}$/', $cvc) !== 1) {
            $errors['cvc'] = 'Enter the 3-4 digit security code.';
        }

        if ($errors !== []) {
            // Invalid submissions echo inline errors only - never the
            // submitted card data and never a partial authorization.
            return ['result' => self::RESULT_INVALID, 'errors' => $errors];
        }

        $result = self::TEST_CARDS[$pan] ?? self::RESULT_APPROVED;

        ($this->sleep)(random_int(
            self::MIN_DELAY_MICROSECONDS,
            self::MAX_DELAY_MICROSECONDS
        ));

        if ($result !== self::RESULT_APPROVED) {
            // Declines keep the cart intact and reveal no card data.
            return ['result' => $result, 'errors' => []];
        }

        return [
            'result' => self::RESULT_APPROVED,
            'errors' => [],
            'last4' => substr($pan, -4),
            'brand' => $this->brand($pan),
        ];
    }

    /**
     * Standard Luhn checksum over a digit string.
     */
    public static function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $length = strlen($digits);
        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$length - 1 - $i];
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    /**
     * Strict MM/YY: month 01-12, two-digit year, and the month strictly
     * later than the current one (the current month already expired).
     */
    private function isFutureExpiry(string $expiry): bool
    {
        if (preg_match('/^(0[1-9]|1[0-2])\/(\d{2})$/', $expiry, $matches) !== 1) {
            return false;
        }

        $month = (int) $matches[1];
        $year = 2000 + (int) $matches[2];

        return [$year, $month] > [(int) date('Y'), (int) date('n')];
    }

    private function brand(string $pan): string
    {
        return match (true) {
            $pan[0] === '4' => 'Visa',
            (bool) preg_match('/^(5[1-5]|2[2-7])/', $pan) => 'Mastercard',
            (bool) preg_match('/^3[47]/', $pan) => 'American Express',
            $pan[0] === '6' => 'Discover',
            default => 'Card',
        };
    }
}
