<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\OperatingCompany;
use App\Models\StockLocation;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DarakDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_records_belong_to_an_operating_tenant(): void
    {
        config()->set('darak.demo_password', 'test-demo-password-2026');
        $this->seed(DarakDemoSeeder::class);

        $this->assertSame(1, OperatingCompany::query()->count());
        $this->assertSame(0, User::query()->whereNull('operating_company_id')->count());
        $this->assertSame(0, Client::query()->whereNull('operating_company_id')->count());
        $this->assertSame(0, Client::query()->whereNull('operating_branch_id')->count());
        $this->assertSame(0, Vehicle::query()->whereNull('operating_branch_id')->count());
        $this->assertSame(0, StockLocation::query()->whereNull('operating_branch_id')->count());
    }
}
