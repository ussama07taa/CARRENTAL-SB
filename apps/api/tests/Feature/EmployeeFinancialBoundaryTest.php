<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class EmployeeFinancialBoundaryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_direct_financial_routes_are_forbidden_to_employee_and_lists_work_for_manager(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $company = Company::factory()->create();
        [$manager,$employee] = Tenant::run($company->id, fn () => [CompanyMembership::factory()->responsible()->create(), CompanyMembership::factory()->create()]);
        $this->actingAs($employee->user)->withSession(['company_id' => $company->id]);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('can_manage_expenses', false);
        foreach (['expenses', 'expenses/payables', 'expenses/1', 'expenses/1/documents/1', 'cash', 'cash/entries/1', 'loans', 'loans/1', 'loans/1/documents/1/content', 'reports', 'reports/movements'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertForbidden();
        }
        foreach (['expenses', 'expenses/1', 'expenses/1/movements', 'expenses/1/cancel', 'expenses/1/documents', 'cash/entries', 'cash/entries/1', 'cash/counts', 'loans', 'loans/1/cancellations', 'loans/1/installments/1/payments', 'loans/1/documents', 'loans/1/purchase-payments', 'loans/1/payments/1/corrections', 'loans/1/payments/1/cancellations', 'pricing'] as $path) {
            $this->postJson('/api/v1/'.$path, [])->assertForbidden();
        }
        $this->actingAs($manager->user)->withSession(['company_id' => $company->id]);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('can_manage_expenses', true);
        foreach (['expenses', 'expenses/payables', 'cash', 'loans', 'reports', 'reports/movements'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertOk();
        }
    }
}
