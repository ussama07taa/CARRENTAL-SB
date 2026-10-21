<?php

namespace Database\Factories;

use App\Models\CompanyMembership;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanyMembership> */
class CompanyMembershipFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Tenant::id(), 'user_id' => User::factory(), 'is_primary_responsible' => false];
    }

    public function responsible(): static
    {
        return $this->state(fn () => ['is_primary_responsible' => true]);
    }
}
