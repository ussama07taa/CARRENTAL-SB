<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(),
            'phone' => '+2126'.fake()->numerify('########'), 'language' => 'fr',
            'creation_key' => (string) Str::uuid(), 'creation_hash' => hash('sha256', 'factory'), 'version' => 1,
        ];
    }
}
