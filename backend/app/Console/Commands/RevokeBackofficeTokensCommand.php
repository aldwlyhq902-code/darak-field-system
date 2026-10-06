<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One-off, run right after deploying the API-MFA fix.
 *
 * Owner and admin bearer tokens issued before the API demanded a second factor
 * were minted from a password alone and stay valid for up to 30 days. Revoking
 * them forces every back-office API client through the MFA login once.
 * Technician device tokens are untouched. Deliberately NOT scheduled.
 */
class RevokeBackofficeTokensCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'darak:revoke-backoffice-tokens
        {--dry-run : Report how many tokens would be revoked without deleting them}
        {--force : Run without confirmation in production}';

    protected $description = 'Revoke every API token held by owner/admin accounts (post-deploy, after the API MFA fix)';

    public function handle(AuditLogger $audit): int
    {
        $query = PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', User::query()->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])->select('id'));

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("{$count} back-office API token(s) would be revoked.");

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $revoked = DB::transaction(fn (): int => $query->delete());

        $audit->record('auth.backoffice_tokens_revoked', null, null, ['tokens' => $revoked]);
        $this->info("Revoked {$revoked} back-office API token(s). Technician device tokens were not touched.");

        return self::SUCCESS;
    }
}
