<?php

namespace Tests\Feature;

use App\Models\EmergencyReport;
use App\Models\NotificationMessage;
use App\Models\WebPushSubscription;
use App\Services\SalesPushService;
use GuzzleHttp\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\DarakTestCase;

/** Review items 9 (Web Push SSRF), 11 (QR report abuse) and 12 (SW scope). */
class PublicSurfaceHardeningTest extends DarakTestCase
{
    // --- item 9: Web Push endpoints -------------------------------------------

    public function test_only_browser_push_services_are_accepted_as_endpoints(): void
    {
        foreach ([
            'https://fcm.googleapis.com/fcm/send/abc',
            'https://updates.push.services.mozilla.com/wpush/v2/abc',
            'https://eu-west.push.services.mozilla.com/wpush/v2/abc',
            'https://wns2-by3p.notify.windows.com/w/?token=abc',
            'https://web.push.apple.com/QGx',
            'https://api.push.apple.com/3/device/abc',
        ] as $allowed) {
            $this->assertTrue(SalesPushService::isAllowedEndpoint($allowed), $allowed);
        }

        foreach ([
            'http://fcm.googleapis.com/fcm/send/abc',
            'https://169.254.169.254/latest/meta-data',
            'https://[::1]/x',
            'https://127.0.0.1/x',
            'https://fcm.googleapis.com.attacker.test/x',
            'https://attacker.test/fcm.googleapis.com',
            'https://fcm.googleapis.com:8443/x',
            'https://user:pass@fcm.googleapis.com/x',
            'https://notify.windows.com/x',
            'https://localhost/x',
            'file:///etc/passwd',
        ] as $refused) {
            $this->assertFalse(SalesPushService::isAllowedEndpoint($refused), $refused);
        }
    }

    public function test_subscribing_an_internal_endpoint_is_refused(): void
    {
        $this->configureVapid();

        $this->actingAs($this->owner, 'web')
            ->post(route('sales.push.subscribe'), $this->subscription('https://169.254.169.254/latest/meta-data'))
            ->assertSessionHasErrors('endpoint');
        $this->assertSame(0, WebPushSubscription::count());

        $this->actingAs($this->owner, 'web')
            ->postJson(route('sales.push.subscribe'), $this->subscription('https://fcm.googleapis.com/fcm/send/'.Str::random(20)))
            ->assertOk();
        $this->assertSame(1, WebPushSubscription::count());
    }

    public function test_a_stored_disallowed_endpoint_is_dropped_before_any_request_is_made(): void
    {
        $this->configureVapid();
        WebPushSubscription::create([
            'user_id' => $this->owner->id,
            'endpoint_hash' => hash('sha256', 'http://10.0.0.5/internal'),
            'endpoint' => 'http://10.0.0.5/internal',
            'public_key' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
            'auth_token' => 'tBHItJI5svbpez7KI4CCXg',
            'content_encoding' => 'aes128gcm',
        ]);

        $this->assertSame(0, app(SalesPushService::class)->send($this->owner, 'title', 'body'));
        $this->assertSame(0, WebPushSubscription::count());
    }

    public function test_push_http_client_never_follows_redirects(): void
    {
        $method = new \ReflectionMethod(SalesPushService::class, 'httpClient');
        /** @var Client $client */
        $client = $method->invoke(app(SalesPushService::class));

        $this->assertFalse($client->getConfig('allow_redirects'));
    }

    // --- item 11: QR emergency reports ---------------------------------------

    public function test_a_site_past_its_hourly_cap_still_takes_the_report_but_flags_it(): void
    {
        // Refusing here let anyone with a photo of the sticker silence a real
        // gas report for an hour from a few addresses. Now it is kept.
        Storage::fake('local');
        $token = $this->qrToken();
        for ($i = 0; $i < 10; $i++) {
            $this->existingReport(now()->subMinutes(5));
        }

        $this->post(route('emergency.store', $token), $this->reportPayload() + [
            'photo' => UploadedFile::fake()->image('leak.jpg', 800, 600)->size(400),
        ])->assertRedirect();

        $report = EmergencyReport::latest('id')->first();
        $this->assertSame(11, EmergencyReport::count());
        $this->assertTrue($report->flood_flagged);
        $this->assertNull($report->photo_path, 'a flood does not get to fill the disk');
        $this->assertSame(0, NotificationMessage::where('idempotency_key', "emergency.reported:{$report->id}")->count());
    }

    public function test_a_flood_raises_one_summary_alert_per_site_hour(): void
    {
        $token = $this->qrToken();
        for ($i = 0; $i < 30; $i++) {
            $this->existingReport(now()->subHours(3));
        }

        foreach (['0511111111', '0522222222', '0533333333'] as $i => $phone) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.($i + 1)])
                ->post(route('emergency.store', $token), ['reporter_phone' => $phone] + $this->reportPayload())
                ->assertRedirect();
        }

        $this->assertSame(3, EmergencyReport::where('flood_flagged', true)->count());
        $this->assertSame(1, NotificationMessage::where('idempotency_key', 'like', 'emergency.flood:%')->count());
    }

    public function test_one_reporter_is_refused_after_three_reports_in_an_hour(): void
    {
        $token = $this->qrToken();
        foreach (['0501234567', '+966501234567', '966501234567'] as $i => $sameNumber) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.'.($i + 1)])
                ->post(route('emergency.store', $token), ['reporter_phone' => $sameNumber] + $this->reportPayload())
                ->assertSessionHasNoErrors();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.9'])
            ->post(route('emergency.store', $token), $this->reportPayload())
            ->assertSessionHasErrors('description');
        $this->assertSame(3, EmergencyReport::count());
        $this->assertSame(['0501234567'], EmergencyReport::distinct()->pluck('reporter_phone')->all());
    }

    public function test_reports_below_the_cap_are_accepted_and_photos_are_limited_to_5mb(): void
    {
        Storage::fake('local');
        $token = $this->qrToken();
        $this->existingReport(now()->subMinutes(5));

        $this->post(route('emergency.store', $token), $this->reportPayload() + [
            'photo' => UploadedFile::fake()->image('big.jpg')->size(6000),
        ])->assertSessionHasErrors('photo');

        $this->post(route('emergency.store', $token), $this->reportPayload() + [
            'photo' => UploadedFile::fake()->image('ok.jpg', 800, 600)->size(900),
        ])->assertRedirect();
        $this->assertSame(2, EmergencyReport::count());
    }

    // --- item 12: service worker scope ----------------------------------------

    public function test_service_worker_scope_is_limited_to_each_app(): void
    {
        $this->get('/client/service-worker.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/client');
        $this->get('/sales/service-worker.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/sales/');
        $this->get('/client/login')->assertHeaderMissing('Service-Worker-Allowed');
        $this->get(route('emergency.create', $this->qrToken()))->assertHeaderMissing('Service-Worker-Allowed');
    }

    private function configureVapid(): void
    {
        // Correctly sized keys; nothing is signed because nothing is sent.
        $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        config([
            'sales.vapid.public_key' => $encode("\x04".str_repeat("\x11", 64)),
            'sales.vapid.private_key' => $encode(str_repeat("\x22", 32)),
            'sales.vapid.subject' => 'mailto:security@test.local',
        ]);
    }

    /** @return array<string, mixed> */
    private function subscription(string $endpoint): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
                'auth' => 'tBHItJI5svbpez7KI4CCXg',
            ],
            'contentEncoding' => 'aes128gcm',
        ];
    }

    private function qrToken(): string
    {
        $token = $this->site->rotateEmergencyQr();
        $this->site->save();

        return $token;
    }

    private function existingReport(\DateTimeInterface $at): void
    {
        $report = EmergencyReport::create([
            'public_reference' => (string) Str::uuid(),
            'site_id' => $this->site->id,
            'reporter_name' => 'Worker',
            // Many different people: the per-reporter cap must not be what trips.
            'reporter_phone' => '05'.random_int(10000000, 99999999),
            'category' => 'ac',
            'severity' => 'urgent',
            'description' => 'The air conditioning stopped in the main dining room.',
            'status' => EmergencyReport::STATUS_NEW,
            'source' => 'site_qr',
        ]);
        $report->forceFill(['created_at' => $at])->save();
    }

    /** @return array<string, string> */
    private function reportPayload(): array
    {
        return [
            'reporter_name' => 'عامل المطبخ',
            'reporter_phone' => '0501234567',
            'category' => 'refrigeration',
            'severity' => 'critical',
            'description' => 'الفريزر توقف بالكامل ودرجة الحرارة ترتفع بسرعة.',
        ];
    }
}
