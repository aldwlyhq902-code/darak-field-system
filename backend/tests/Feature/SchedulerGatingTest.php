<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Tests\DarakTestCase;

class SchedulerGatingTest extends DarakTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(Kernel::class)->bootstrap();
    }

    public function test_experimental_analytics_jobs_follow_the_opt_in_flag(): void
    {
        config(['darak.experimental_analytics' => false]);
        $this->assertFalse($this->runs('darak:fault-model-train'));

        config(['darak.experimental_analytics' => true]);
        $this->assertTrue($this->runs('darak:fault-model-train'));
    }

    public function test_module_flags_freeze_their_scheduled_jobs_and_default_to_on(): void
    {
        $jobs = [
            'sales' => 'darak:sales-alerts',
            'commissions' => 'darak:commissions-generate',
            'procurement_automation' => 'darak:replenishment-scan',
            'auto_reassign' => 'darak:reassign-unavailable',
            'fleet_hr_alerts' => 'darak:compliance-alerts',
        ];

        config(['darak.modules' => []]);
        foreach ($jobs as $command) {
            $this->assertTrue($this->runs($command), "{$command} must default to on");
        }

        foreach ($jobs as $module => $command) {
            config(["darak.modules.{$module}" => false]);
            $this->assertFalse($this->runs($command), "{$command} must stop when {$module} is frozen");
        }

        // Core operations never depend on module flags.
        foreach (['darak:sla-sweep', 'darak:notifications-run', 'darak:backup', 'darak:heartbeat'] as $command) {
            $this->assertTrue($this->runs($command), $command);
        }
    }

    public function test_retention_purge_is_scheduled_only_when_enabled(): void
    {
        config(['darak.retention_purge_enabled' => false]);
        $this->assertFalse($this->runs('darak:retention-purge'));

        config(['darak.retention_purge_enabled' => true]);
        $this->assertTrue($this->runs('darak:retention-purge'));
        $this->assertStringContainsString('--execute', $this->scheduled('darak:retention-purge')->command);
    }

    public function test_heartbeat_backup_and_model_pruning_are_scheduled(): void
    {
        $this->assertSame('* * * * *', $this->scheduled('darak:heartbeat')->expression);
        $this->assertSame('30 2 * * *', $this->scheduled('darak:backup')->expression);
        $this->assertSame('45 4 * * *', $this->scheduled('model:prune')->expression);
    }

    private function runs(string $command): bool
    {
        return $this->scheduled($command)->filtersPass($this->app);
    }

    private function scheduled(string $command): Event
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, $command));

        $this->assertNotNull($event, "{$command} is not scheduled");

        return $event;
    }
}
