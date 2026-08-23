<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLeadAttachment extends Model
{
    use ScopedToOperatingBranch;

    protected $fillable = ['sales_lead_id', 'uploaded_by', 'kind', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class, 'sales_lead_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
