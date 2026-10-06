<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\DarakTestCase;

class DeploymentHardeningTest extends DarakTestCase
{
    /** @return array<string, array{0: array<string, string|false>, 1: string}> */
    public static function refusedVercelEnvironments(): array
    {
        return [
            'no demo opt-in' => [['DARAK_VERCEL_EPHEMERAL_DEMO' => false, 'APP_ENV' => 'demo', 'APP_DEBUG' => 'false'], 'EPHEMERAL_RUNTIME_DISABLED'],
            'production' => [['DARAK_VERCEL_EPHEMERAL_DEMO' => 'true', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false'], 'EPHEMERAL_RUNTIME_DISABLED'],
            'debug true' => [['DARAK_VERCEL_EPHEMERAL_DEMO' => 'true', 'APP_ENV' => 'demo', 'APP_DEBUG' => 'true'], 'DEBUG_NOT_ALLOWED'],
            'debug 1' => [['DARAK_VERCEL_EPHEMERAL_DEMO' => 'true', 'APP_ENV' => 'demo', 'APP_DEBUG' => '1'], 'DEBUG_NOT_ALLOWED'],
            'debug on' => [['DARAK_VERCEL_EPHEMERAL_DEMO' => 'true', 'APP_ENV' => 'demo', 'APP_DEBUG' => 'on'], 'DEBUG_NOT_ALLOWED'],
        ];
    }

    /** @param array<string, string|false> $environment */
    #[DataProvider('refusedVercelEnvironments')]
    public function test_vercel_entry_point_fails_closed(array $environment, string $code): void
    {
        $process = new Process([PHP_BINARY, base_path('api/index.php')], base_path(), $environment + ['VERCEL' => '1']);
        $process->run();

        $this->assertSame($code, json_decode($process->getOutput(), true)['code'] ?? null, $process->getOutput().$process->getErrorOutput());
    }

    public function test_forwarded_headers_from_the_local_proxy_are_trusted(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('/login')
            ->assertHeader('Strict-Transport-Security');
    }

    // Separate test: after an HTTPS request the test client keeps an https root URL.
    public function test_forwarded_headers_from_other_addresses_are_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('/login')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_vercelignore_keeps_secrets_evidence_and_backups_out_of_the_bundle(): void
    {
        $ignored = array_map('trim', file(base_path('.vercelignore'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        foreach (['.env*', 'storage/app/backups', 'storage/app/private', 'storage/logs', 'database/*.sqlite', 'tests', '_tools'] as $pattern) {
            $this->assertContains($pattern, $ignored, $pattern);
        }
    }
}
