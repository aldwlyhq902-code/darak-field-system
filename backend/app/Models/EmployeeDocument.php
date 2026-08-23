<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use ScopedToOperatingBranch;

    protected $hidden = ['file_path'];

    protected $fillable = ['user_id', 'operating_branch_id', 'type', 'document_number', 'issued_on', 'expires_on', 'issuer', 'file_path', 'file_name', 'mime_type', 'file_size', 'status', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
