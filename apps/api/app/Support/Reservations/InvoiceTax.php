<?php

namespace App\Support\Reservations;

/** Amounts and rate basis points stay integer throughout. */
class InvoiceTax
{
    public static function forPrice(int $amount, string $rate, string $mode): array
    {
        $added = self::calculate($amount, $rate);
        if (! in_array($mode, ['included', 'added'], true)) {
            throw new \InvalidArgumentException('Invalid tax mode.');
        }
        if ($mode === 'included') {
            $parts = explode('.', $added['rate']);
            $bps = ((int) $parts[0]) * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
            $divisor = 10000 + $bps;
            $net = intdiv($amount * 10000 + intdiv($divisor, 2), $divisor);
            $added = [...$added, 'net_centimes' => $net, 'vat_centimes' => $amount - $net, 'gross_centimes' => $amount];
        }
        return [...$added, 'mode' => $mode, 'input_centimes' => $amount];
    }

    public static function calculate(int $netCentimes, string $rate): array
    {
        if ($netCentimes < 0 || ! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $rate)) {
            throw new \InvalidArgumentException('Invalid tax calculation input.');
        }
        $parts = explode('.', $rate);
        $basisPoints = ((int) $parts[0]) * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        if ($basisPoints > 10000 || $netCentimes > intdiv(PHP_INT_MAX - 5000, 10001)) {
            throw new \InvalidArgumentException('Tax calculation input exceeds supported range.');
        }
        // Round the total VAT to the nearest centime; half a centime rounds up.
        $tax = intdiv($netCentimes * $basisPoints + 5000, 10000);
        return ['rate' => intdiv($basisPoints, 100).($basisPoints % 100 ? '.'.rtrim(str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT), '0') : ''),
            'net_centimes' => $netCentimes, 'vat_centimes' => $tax, 'gross_centimes' => $netCentimes + $tax];
    }
}
