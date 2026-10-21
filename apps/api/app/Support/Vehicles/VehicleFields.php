<?php

namespace App\Support\Vehicles;

class VehicleFields
{
    public const EDITABLE = ['vehicle_type', 'plate', 'make', 'model', 'trim', 'year', 'fuel', 'transmission', 'color', 'seats', 'doors', 'vin', 'equipment'];

    public const EQUIPMENT = ['air_conditioning', 'bluetooth', 'carplay', 'android_auto', 'usb', 'gps', 'parking_sensors', 'rear_camera', 'cruise_control', 'child_seat_mounts', 'sunroof'];

    public const TYPES = ['passenger_car', 'family', 'suv', 'van', 'minibus', 'utility'];

    public const FUELS = ['diesel', 'petrol', 'hybrid', 'electric'];

    public const TRANSMISSIONS = ['manual', 'automatic'];

    public static function identifier(string $value): string
    {
        return mb_strtoupper(preg_replace('/[^\pL\pN]/u', '', $value));
    }
}
