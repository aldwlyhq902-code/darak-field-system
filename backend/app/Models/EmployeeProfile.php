<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeProfile extends Model
{
    protected $fillable = ['user_id', 'employee_no', 'nationality', 'hired_on', 'annual_leave_days', 'emergency_contact_name', 'emergency_contact_phone', 'notes'];

    protected function casts(): array
    {
        return ['hired_on' => 'date', 'annual_leave_days' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
