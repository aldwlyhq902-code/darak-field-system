<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'commercial_name', 'cr_number', 'vat_number', 'category',
        'credit_limit', 'payment_term', 'established_on', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'established_on' => 'date',
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Hard deletion is refused while any site exists.
     *
     * Cascading a client delete used to destroy visit_events, media_files and
     * checklist_instances — the sync log, the photo hashes and the signatures —
     * while the issued invoices survived with a null client. Money records with
     * no supporting evidence is precisely the state an audit cannot accept.
     * Archive the client instead; SoftDeletes already covers that.
     */
    protected static function booted(): void
    {
        static::forceDeleting(function (self $client) {
            if ($client->sites()->withTrashed()->exists()) {
                throw new \RuntimeException(
                    'لا يمكن حذف عميل له مواقع وسجل زيارات. أرشفه بدل حذفه — الأدلة يجب أن تبقى بعد العميل.'
                );
            }
        });
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Restaurants under one year old must pay quarterly in advance.
     * 60% of new restaurants fail in their first year, so this is a credit
     * policy — the system enforces it rather than leaving it to memory.
     */
    public function requiresAdvancePayment(): bool
    {
        if ($this->established_on === null) {
            return true; // unknown age is treated as new
        }

        return $this->established_on->diffInMonths(now()) < 12;
    }
}
