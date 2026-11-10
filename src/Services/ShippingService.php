<?php

declare(strict_types=1);

namespace Habi\Services;

use InvalidArgumentException;

/**
 * Checkout shipping validation and region fees (M5, PRD R11/R12).
 *
 * validate() is the single server-side gate for the shipping step: it
 * trims and length-bounds every field, rejects array payloads, preserves
 * the safe submitted values for redisplay and returns errors keyed by
 * field. fee() applies the binding PRD R12 rates in integer centavos;
 * the free-shipping threshold is strict (free only above 5,000 pesos).
 */
final class ShippingService
{
    /** Free shipping applies strictly above this subtotal (5,000 pesos). */
    public const FREE_THRESHOLD_CENTAVOS = 500000;

    /** Region keys for the shipping select, key => label (PRD R12). */
    public const REGIONS = [
        'metro_manila' => 'Metro Manila',
        'luzon' => 'Luzon',
        'visayas' => 'Visayas',
        'mindanao' => 'Mindanao',
    ];

    /** Binding PRD R12 rates, integer centavos. */
    private const REGION_FEES = [
        'metro_manila' => 12000,
        'luzon' => 18000,
        'visayas' => 22000,
        'mindanao' => 25000,
    ];

    private const FIELD_LIMITS = [
        'name' => 100,
        'email' => 200,
        'phone' => 30,
        'address1' => 200,
        'address2' => 200,
        'city' => 100,
        'postal_code' => 10,
        'region' => 20,
    ];

    private const REQUIRED_FIELDS = [
        'name', 'email', 'phone', 'address1', 'city', 'postal_code', 'region',
    ];

    /**
     * Validates a shipping submission. Returns the sanitized shipping
     * fields (safe submitted values preserved for redisplay, unknown or
     * unsafe input blanked) and inline errors keyed by field.
     *
     * @param array<string, mixed> $input
     *
     * @return array{shipping: array<string, string>, errors: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $shipping = [];
        $errors = [];

        foreach (self::FIELD_LIMITS as $field => $limit) {
            $value = $input[$field] ?? '';
            if (!is_string($value)) {
                // Arrays and other non-string payloads are rejected outright
                // and never echoed back.
                $shipping[$field] = '';
                $errors[$field] = 'Enter a valid value.';
                continue;
            }

            $shipping[$field] = trim($value);

            if ($shipping[$field] === '') {
                if (in_array($field, self::REQUIRED_FIELDS, true)) {
                    $errors[$field] = self::requiredMessage($field);
                }
                continue;
            }

            if (mb_strlen($shipping[$field]) > $limit) {
                $errors[$field] = sprintf(
                    'Must be %d characters or fewer.',
                    $limit
                );
                continue;
            }

            $error = self::fieldError($field, $shipping[$field]);
            if ($error !== null) {
                $errors[$field] = $error;
            }
        }

        // Region is validated against the known map, not as free text.
        if (
            ($errors['region'] ?? null) === null
            && !isset(self::REGIONS[$shipping['region']])
        ) {
            $errors['region'] = 'Choose a shipping region.';
        }

        return ['shipping' => $shipping, 'errors' => $errors];
    }

    /**
     * The shipping fee for a region and subtotal in centavos. Free strictly
     * above the 5,000-peso subtotal (PRD R12); unknown regions are a
     * programming error because validate() gates every user submission.
     */
    public static function fee(string $region, int $subtotal): int
    {
        if (!isset(self::REGION_FEES[$region])) {
            throw new InvalidArgumentException(
                sprintf('Unknown shipping region "%s".', $region)
            );
        }

        if ($subtotal > self::FREE_THRESHOLD_CENTAVOS) {
            return 0;
        }

        return self::REGION_FEES[$region];
    }

    private static function fieldError(string $field, string $value): ?string
    {
        return match ($field) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false
                ? 'Enter a valid email address.'
                : null,
            'phone' => self::isValidPhone($value)
                ? null
                : 'Enter a valid contact number.',
            'postal_code' => preg_match('/^\d{3,10}$/', $value) === 1
                ? null
                : 'Enter a valid postal code.',
            default => null,
        };
    }

    private static function isValidPhone(string $value): bool
    {
        if (preg_match('/^\+?[0-9()\-\s]{7,30}$/', $value) !== 1) {
            return false;
        }

        $digits = preg_match_all('/\d/', $value);

        return $digits >= 7 && $digits <= 15;
    }

    private static function requiredMessage(string $field): string
    {
        return match ($field) {
            'name' => 'Enter your full name.',
            'email' => 'Enter your email address.',
            'phone' => 'Enter your contact number.',
            'address1' => 'Enter your street address.',
            'city' => 'Enter your city or municipality.',
            'postal_code' => 'Enter your postal code.',
            'region' => 'Choose a shipping region.',
            default => 'This field is required.',
        };
    }
}
