<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;

/** A failure reported by one company; a company-less row is visible only to platform admins. */
class PartFailureReport extends Model
{
    use ScopedToOperatingBranch;

    protected $fillable = ['operating_company_id', 'part_id', 'inventory_lot_id', 'supplier_id', 'visit_id', 'asset_id', 'failure_kind', 'note', 'reported_by'];
}
