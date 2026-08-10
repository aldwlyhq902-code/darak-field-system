<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistInstance extends Model
{
    use HasFactory;

    protected $fillable = [
        'visit_id', 'asset_id', 'checklist_template_id', 'client_generated_uuid',
        'status', 'items', 'note', 'no_parts_used', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'no_parts_used' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class);
    }
}
