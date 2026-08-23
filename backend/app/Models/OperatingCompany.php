<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperatingCompany extends Model
{
    protected $fillable = [
        'name', 'legal_name', 'cr_number', 'vat_number', 'currency',
        'logo_path', 'phone', 'whatsapp', 'email', 'website',
        'address', 'city', 'postal_code', 'country', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(OperatingBranch::class);
    }
}
