<?php

namespace App\Support\Customers;

class CustomerFields
{
    public const EDITABLE = ['first_name', 'last_name', 'phone', 'secondary_phone', 'email', 'city', 'address', 'language', 'identity_type', 'identity_number', 'licence_number', 'licence_expires_on'];

    public static function phone(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $phone = preg_replace('/[\s().-]/u', '', $value);
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }
        if (preg_match('/^0[5-7][0-9]{8}$/', $phone)) {
            $phone = '+212'.substr($phone, 1);
        }

        return $phone;
    }

    public static function identity(?string $value): ?string
    {
        return $value ? mb_strtoupper(preg_replace('/[\s-]/u', '', $value)) : null;
    }
}
