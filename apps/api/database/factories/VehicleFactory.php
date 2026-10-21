<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VehicleFactory extends Factory
{
    public function definition(): array
    {
        $plate = 'TEST'.fake()->unique()->numerify('######');

        return ['plate' => $plate, 'plate_key' => $plate, 'make' => 'Dacia', 'model' => 'Logan', 'equipment' => [],
            'creation_key' => (string) Str::uuid(), 'creation_hash' => hash('sha256', 'factory'), 'version' => 1];
    }
}
