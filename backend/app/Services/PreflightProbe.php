<?php

namespace App\Services;

use App\Console\Commands\HeartbeatCommand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * The parts of darak:preflight that ask the machine rather than the config:
 * binaries on PATH, the database role, the scheduler heartbeat. Isolated so
 * the checks can be exercised without a production server.
 */
class PreflightProbe
{
    public function binaryAvailable(string $binary): bool
    {
        $binary = trim($binary);

        if ($binary === '') {
            return false;
        }

        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return is_file($binary) && is_executable($binary);
        }

        return (new ExecutableFinder)->find($binary) !== null;
    }

    /** True/false for PostgreSQL; null when it cannot be determined. */
    public function databaseRoleIsSuperuser(): ?bool
    {
        try {
            if (DB::getDriverName() !== 'pgsql') {
                return null;
            }

            $row = DB::selectOne('select rolsuper from pg_roles where rolname = current_user');

            return $row === null ? null : (bool) $row->rolsuper;
        } catch (Throwable) {
            return null;
        }
    }

    public function schedulerHeartbeatAge(): ?int
    {
        try {
            $timestamp = Cache::get(HeartbeatCommand::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_numeric($timestamp) ? max(0, now()->timestamp - (int) $timestamp) : null;
    }
}
