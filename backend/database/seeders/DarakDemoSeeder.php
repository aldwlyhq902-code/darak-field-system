<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Part;
use App\Models\Site;
use App\Models\StockLocation;
use App\Models\Subcontractor;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Services\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data shaped like the real target: small restaurants and cafes in Jeddah,
 * two vehicles, four technicians on two shifts.
 *
 * All prices are PRE-VAT. Package values match the tested hypothesis (basic 1,200 /
 * comprehensive 1,850) — they are a HYPOTHESIS, not validated pricing, and must be
 * proven by the market gate before anything is built around them.
 */
class DarakDemoSeeder extends Seeder
{
    public function run(): void
    {
        $sla = app(SlaCalculator::class);

        $owner = User::create([
            'name' => 'مالك دارك',
            'email' => 'owner@darak.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_OWNER,
            'phone' => '0500000000',
            'is_active' => true,
        ]);

        User::create([
            'name' => 'إداري / محاسبة',
            'email' => 'admin@darak.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        // Four technicians, two shifts. The trade recorded is the one written on the
        // work permit — the system stores it, it does not interpret labour law.
        $technicians = collect([
            ['name' => 'فني أول - صباحي', 'email' => 'tech1@darak.test', 'shift' => ['07:00', '15:00'], 'spec' => ['split_ac', 'package_ac', 'chiller', 'freezer']],
            ['name' => 'فني ثانٍ - صباحي', 'email' => 'tech2@darak.test', 'shift' => ['07:00', '15:00'], 'spec' => ['split_ac', 'electrical', 'plumbing']],
            ['name' => 'فني ثالث - مسائي', 'email' => 'tech3@darak.test', 'shift' => ['15:00', '23:00'], 'spec' => ['split_ac', 'package_ac', 'electrical']],
            ['name' => 'فني رابع - مسائي', 'email' => 'tech4@darak.test', 'shift' => ['15:00', '23:00'], 'spec' => ['plumbing', 'electrical', 'split_ac']],
        ])->map(fn ($t) => User::create([
            'name' => $t['name'],
            'email' => $t['email'],
            'password' => Hash::make('password'),
            'role' => User::ROLE_TECHNICIAN,
            'trade' => 'فني تكييف وتبريد',
            'specialties' => $t['spec'],
            'shift_start' => $t['shift'][0],
            'shift_end' => $t['shift'][1],
            'is_active' => true,
        ]));

        // Two vehicles, each its own stock location.
        $warehouse = StockLocation::create(['type' => StockLocation::TYPE_WAREHOUSE, 'name' => 'المستودع المركزي']);

        $vehicleLocations = collect(['ح ن ر 1234', 'ط ب ل 5678'])->map(function (string $plate, int $i) {
            $vehicle = Vehicle::create(['plate' => $plate, 'internal_code' => 'V' . ($i + 1), 'model' => 'Hilux', 'year' => 2024]);

            return StockLocation::create([
                'type' => StockLocation::TYPE_VEHICLE,
                'name' => 'مستودع السيارة ' . ($i + 1),
                'vehicle_id' => $vehicle->id,
            ]);
        });

        // Parts. heat_sensitive is per SKU with the maker's limit — there is
        // deliberately no global temperature threshold.
        $parts = collect([
            ['sku' => 'CAP-35-440', 'name' => 'مكثف 35 ميكرو 440 فولت', 'cost' => 38, 'price' => 95, 'heat' => true, 'max' => 85],
            ['sku' => 'PCB-SPL-01', 'name' => 'بورد تحكم سبلت', 'cost' => 180, 'price' => 390, 'heat' => true, 'max' => 70],
            ['sku' => 'CONT-25A', 'name' => 'كونتاكتور 25 أمبير', 'cost' => 45, 'price' => 110, 'heat' => false, 'max' => null],
            ['sku' => 'GAS-R410-1KG', 'name' => 'فريون R410A كيلو', 'cost' => 55, 'price' => 140, 'heat' => true, 'max' => 52],
            ['sku' => 'MIX-KIT-01', 'name' => 'خلاط مطبخ', 'cost' => 70, 'price' => 165, 'heat' => false, 'max' => null],
            ['sku' => 'BRK-32A', 'name' => 'قاطع كهربائي 32 أمبير', 'cost' => 42, 'price' => 105, 'heat' => false, 'max' => null],
        ])->map(fn ($p) => Part::create([
            'sku' => $p['sku'],
            'name' => $p['name'],
            'purchase_cost' => $p['cost'],
            'sale_price' => $p['price'],
            'member_price' => round($p['price'] * 0.8, 2),
            'qr_code' => 'PART-' . $p['sku'],
            'heat_sensitive' => $p['heat'],
            'max_storage_temp_c' => $p['max'],
            'reorder_level' => 2,
        ]));

        ChecklistTemplate::create([
            'asset_type' => 'split_ac',
            'name' => 'فحص وحدة سبليت',
            'items' => [
                ['key' => 'filter', 'label_ar' => 'تنظيف الفلتر', 'requires_photo' => true],
                ['key' => 'coil', 'label_ar' => 'غسيل الملف', 'requires_photo' => true],
                ['key' => 'drain', 'label_ar' => 'تسليك التصريف', 'requires_photo' => false],
                ['key' => 'pressure', 'label_ar' => 'قياس الضغط', 'requires_photo' => false],
            ],
        ]);

        ChecklistTemplate::create([
            'asset_type' => 'chiller',
            'name' => 'فحص ثلاجة تجارية',
            'items' => [
                ['key' => 'temp', 'label_ar' => 'قياس درجة الحرارة', 'requires_photo' => true],
                ['key' => 'seal', 'label_ar' => 'فحص الحشوة', 'requires_photo' => true],
                ['key' => 'condenser', 'label_ar' => 'تنظيف المكثف', 'requires_photo' => true],
            ],
        ]);

        Subcontractor::create([
            'name' => 'شريك تنظيف الهود',
            'specialties' => ['hood'],
            'contact_name' => 'مسؤول العمليات',
            'phone' => '0560000000',
            'issues_official_certificates' => true,
            'notes' => 'يصدر الشهادة باسمه؛ دارك تنسّق فقط.',
        ]);

        Subcontractor::create([
            'name' => 'مقاول غاز معتمد',
            'specialties' => ['gas'],
            'phone' => '0550000000',
            'issues_official_certificates' => true,
            'notes' => 'حتى يُحسم بند أحقية إصدار تقرير فحص الغاز، الإصدار عبر الشريك حصراً.',
        ]);

        // Clients: one established, one under a year old (advance payment enforced),
        // and one small chain with two sites.
        $seeds = [
            ['مطعم السلامة', 'restaurant', '2019-04-01', ['فرع السلامة'], 'basic', 1200],
            ['مقهى الزهراء', 'cafe', CarbonImmutable::now()->subMonths(7)->toDateString(), ['فرع الزهراء'], 'comprehensive', 1850],
            ['سلسلة مطاعم الروضة', 'chain', '2016-09-15', ['فرع الروضة', 'فرع الصاري'], 'comprehensive', 1850],
        ];

        $visitIndex = 0;

        foreach ($seeds as [$name, $category, $established, $siteNames, $package, $price]) {
            $client = Client::create([
                'name' => $name,
                'category' => $category,
                'established_on' => $established,
                'payment_term' => 'quarterly_advance',
                'credit_limit' => 5000,
                'cr_number' => (string) random_int(4030000000, 4039999999),
            ]);

            $contract = Contract::create([
                'client_id' => $client->id,
                'contract_no' => 'DK-' . str_pad((string) $client->id, 4, '0', STR_PAD_LEFT),
                'package_code' => $package,
                'price_amount' => $price,
                'vat_rate' => 0.15,
                'starts_on' => CarbonImmutable::now()->subMonths(2)->toDateString(),
                'ends_on' => CarbonImmutable::now()->addMonths(10)->toDateString(),
                'service_window_start' => '07:00:00',
                'service_window_end' => '23:00:00',
                'sla_minutes' => $package === 'comprehensive' ? 240 : 480,
                'included_assets_cap' => 8,
                'extra_asset_price' => 100,
                'exclusions' => [
                    'الأعطال القائمة قبل التعاقد',
                    'الأعمال بعد الساعة 11 مساءً',
                    'تلف البضاعة',
                    'المعدات الساخنة (أفران وقلايات)',
                    'سقف زيارات الطوارئ لا يتراكم شهرياً',
                ],
                'is_trial' => true,
                'decision_due_on' => CarbonImmutable::now()->addDays(30)->toDateString(),
                'status' => 'active',
            ]);

            foreach ($siteNames as $siteName) {
                $site = Site::create([
                    'client_id' => $client->id,
                    'name' => $siteName,
                    'address' => $siteName . '، جدة',
                    'lat' => 21.5810 + (random_int(-40, 40) / 1000),
                    'lng' => 39.1650 + (random_int(-40, 40) / 1000),
                    'geofence_radius_m' => 100,
                    'dwell_threshold_s' => 120,
                    'access_notes' => 'الدخول من باب الخدمة الخلفي.',
                    'qr_code' => 'SITE-' . strtoupper(substr(md5($siteName), 0, 8)),
                ]);

                $contract->sites()->attach($site->id);

                foreach ([
                    ['split_ac', 'سبليت الصالة'],
                    ['split_ac', 'سبليت المطبخ'],
                    ['chiller', 'ثلاجة العرض'],
                    ['freezer', 'فريزر التخزين'],
                ] as $i => [$type, $assetName]) {
                    Asset::create([
                        'site_id' => $site->id,
                        'type' => $type,
                        'name' => $assetName,
                        'manufacturer' => ['LG', 'Carrier', 'Samsung'][$i % 3],
                        'model' => 'M-' . random_int(100, 999),
                        'serial_number' => strtoupper(substr(md5($siteName . $assetName), 0, 10)),
                        'installed_on' => CarbonImmutable::now()->subYears(random_int(1, 5))->toDateString(),
                        'warranty_until' => $i === 0 ? CarbonImmutable::now()->addMonths(8)->toDateString() : null,
                        'qr_code' => 'ASSET-' . strtoupper(substr(md5($siteName . $assetName), 0, 8)),
                    ]);
                }

                // One preventive visit today per site, spread across technicians.
                $technician = $technicians[$visitIndex % $technicians->count()];
                $start = CarbonImmutable::now()->startOfDay()->addHours(8 + ($visitIndex * 2) % 12);
                $reportedAt = $start->subHours(2);

                $workOrder = WorkOrder::create([
                    'wo_number' => 'WO-' . str_pad((string) (++$visitIndex), 5, '0', STR_PAD_LEFT),
                    'client_id' => $client->id,
                    'site_id' => $site->id,
                    'contract_id' => $contract->id,
                    'type' => 'preventive',
                    'priority' => 'normal',
                    'title' => 'زيارة صيانة وقائية شهرية',
                    'description' => 'فحص وحدات التكييف والتبريد حسب قائمة الفحص.',
                    'reported_at' => $reportedAt,
                    'sla_minutes_budget' => $contract->sla_minutes,
                    'sla_due_at' => $sla->dueAt($reportedAt, $contract->sla_minutes, $contract),
                    'status' => 'scheduled',
                    'created_by' => $owner->id,
                ]);

                Visit::create([
                    'work_order_id' => $workOrder->id,
                    'site_id' => $site->id,
                    'assigned_user_id' => $technician->id,
                    'scheduled_start' => $start,
                    'scheduled_end' => $start->addHours(2),
                    'state' => Visit::STATE_SCHEDULED,
                    'state_changed_at' => CarbonImmutable::now(),
                ]);
            }
        }

        // Seed the warehouse and both vehicles so the app has real balances.
        $inventory = app(\App\Services\InventoryService::class);

        foreach ($parts as $part) {
            $inventory->receipt(
                (string) \Illuminate\Support\Str::uuid(),
                $part->id,
                40,
                $warehouse->id,
                ['user_id' => $owner->id, 'note' => 'رصيد افتتاحي'],
            );

            foreach ($vehicleLocations as $location) {
                $inventory->loadVehicle(
                    (string) \Illuminate\Support\Str::uuid(),
                    $part->id,
                    5,
                    $warehouse->id,
                    $location->id,
                    ['user_id' => $owner->id],
                );
            }
        }

        $this->command?->info('Darak demo data seeded. Login: owner@darak.test / tech1@darak.test — password: password');
    }
}
