<?php

namespace App\Models;

use App\Models\Concerns\ScopedToOperatingBranch;
use Illuminate\Database\Eloquent\Model;

/** A company's own knowledge base entry; a company-less row is visible only to platform admins. */
class KnowledgeArticle extends Model
{
    use ScopedToOperatingBranch;

    protected $fillable = ['operating_company_id', 'fault_code', 'asset_type', 'title', 'symptoms', 'diagnosis', 'solution', 'suggested_part_ids', 'image_paths', 'is_published', 'created_by'];

    protected function casts(): array
    {
        return ['suggested_part_ids' => 'array', 'image_paths' => 'array', 'is_published' => 'boolean'];
    }
}
