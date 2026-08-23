<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartFailureReport extends Model
{
    protected $fillable = ['part_id', 'inventory_lot_id', 'supplier_id', 'visit_id', 'asset_id', 'failure_kind', 'note', 'reported_by'];
}
