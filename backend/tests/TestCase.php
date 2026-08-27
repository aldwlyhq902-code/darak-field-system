<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(UserContract $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        if (($guard === null || $guard === 'web') && $user instanceof User && $user->hasConfirmedTwoFactor()) {
            $this->withSession([
                'panel_mfa_user_id' => $user->getAuthIdentifier(),
                'panel_auth_version' => (int) $user->auth_version,
            ]);
        }

        return $this;
    }
}
