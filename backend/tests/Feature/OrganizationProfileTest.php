<?php

namespace Tests\Feature;

use App\Models\OperatingBranch;
use App\Models\OperatingCompany;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\DarakTestCase;

class OrganizationProfileTest extends DarakTestCase
{
    public function test_owner_can_update_organization_data_and_logo(): void
    {
        Storage::fake('public');
        $company = $this->operatingCompany;
        $company->forceFill(['name' => 'مؤسسة قديمة', 'currency' => 'SAR'])->save();

        $this->actingAs($this->owner, 'web')
            ->get(route('panel.admin.organization'))
            ->assertOk()
            ->assertSee('بيانات المؤسسة والشعار')
            ->assertSee('مؤسسة قديمة');

        $response = $this->actingAs($this->owner, 'web')->put(route('panel.admin.company.update', $company), [
            'name' => 'مؤسسة الاختبار',
            'legal_name' => 'مؤسسة الاختبار للصيانة',
            'cr_number' => '1010101010',
            'vat_number' => '310000000000003',
            'currency' => 'SAR',
            'phone' => '0110000000',
            'whatsapp' => '0558048004',
            'email' => 'info@example.test',
            'website' => 'https://example.test',
            'address' => 'حي الأعمال، طريق الملك',
            'city' => 'الرياض',
            'postal_code' => '12345',
            'country' => 'السعودية',
            'logo' => UploadedFile::fake()->image('logo.png', 600, 300),
        ]);

        $response->assertRedirect()->assertSessionHas('ok');
        $company->refresh();
        $this->assertSame('مؤسسة الاختبار', $company->name);
        $this->assertSame('0558048004', $company->whatsapp);
        $this->assertNotNull($company->logo_path);
        Storage::disk('public')->assertExists($company->logo_path);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'operating_company.profile_updated',
            'auditable_type' => OperatingCompany::class,
            'auditable_id' => $company->id,
        ]);

        $this->actingAs($this->owner, 'web')
            ->get(route('panel.organization.logo', $company))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_logo_upload_rejects_unsupported_files(): void
    {
        Storage::fake('public');
        $company = $this->operatingCompany;
        $company->forceFill(['name' => 'مؤسسة', 'currency' => 'SAR'])->save();

        $this->actingAs($this->owner, 'web')->from(route('panel.admin.organization'))
            ->put(route('panel.admin.company.update', $company), [
                'name' => 'مؤسسة',
                'currency' => 'SAR',
                'logo' => UploadedFile::fake()->create('logo.svg', 20, 'image/svg+xml'),
            ])
            ->assertRedirect(route('panel.admin.organization'))
            ->assertSessionHasErrors('logo');

        $this->assertNull($company->fresh()->logo_path);
    }

    public function test_branch_admin_cannot_edit_another_company_profile(): void
    {
        $ownCompany = OperatingCompany::create(['name' => 'الشركة الأولى', 'currency' => 'SAR', 'is_active' => true]);
        $otherCompany = OperatingCompany::create(['name' => 'الشركة الثانية', 'currency' => 'SAR', 'is_active' => true]);
        $branch = OperatingBranch::create([
            'operating_company_id' => $ownCompany->id,
            'name' => 'الفرع الرئيسي',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $admin = User::create([
            'name' => 'Branch Admin',
            'email' => 'branch-admin@test.local',
            'password' => Hash::make('secret'),
            'role' => User::ROLE_ADMIN,
            'operating_company_id' => $ownCompany->id,
            'operating_branch_id' => $branch->id,
            'permissions' => ['admin'],
            'is_active' => true,
        ]);
        $admin->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($admin, 'web')->put(route('panel.admin.company.update', $otherCompany), [
            'name' => 'تعديل غير مصرح',
            'currency' => 'SAR',
        ])->assertForbidden();

        $this->assertSame('الشركة الثانية', $otherCompany->fresh()->name);
        $this->actingAs($admin, 'web')->get(route('panel.admin.organization'))
            ->assertOk()
            ->assertSee('الشركة الأولى')
            ->assertDontSee('الشركة الثانية');
    }
}
