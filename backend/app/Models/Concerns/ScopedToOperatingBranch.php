<?php

namespace App\Models\Concerns;

use App\Models\Scopes\OperatingBranchScope;

trait ScopedToOperatingBranch
{
    public static function bootScopedToOperatingBranch(): void
    {
        static::addGlobalScope(new OperatingBranchScope);
    }
}
