<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'visit_id', 'checklist_instance_id', 'asset_id', 'client_media_id', 'kind', 'mime',
        'original_path', 'original_hash', 'derived_path',
        'total_bytes', 'uploaded_bytes', 'upload_state', 'attempts', 'last_error',
        'captured_at', 'lat', 'lng', 'declared_source',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function checklistInstance(): BelongsTo
    {
        return $this->belongsTo(ChecklistInstance::class);
    }

    public function isComplete(): bool
    {
        return $this->upload_state === 'complete';
    }
}
