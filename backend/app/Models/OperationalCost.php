<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalCost extends Model
{
    protected $fillable = ['client_id', 'contract_id', 'visit_id', 'user_id', 'category', 'description', 'amount', 'incurred_on', 'recorded_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'incurred_on' => 'date'];
    }
}
