<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VehicleDocumentFactory extends Factory
{
    public function definition(): array
    {
        return ['kind' => 'insurance', 'version' => 1, 'change_kind' => 'initial', 'effective_on' => now()->subMonth()->toDateString(),
            'expires_on' => now()->addMonth()->toDateString(), 'provider' => 'Assureur fictif', 'policy_number' => 'TEST-001',
            'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', 'factory')];
    }
}
