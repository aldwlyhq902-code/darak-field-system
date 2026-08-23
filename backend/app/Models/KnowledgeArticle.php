<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeArticle extends Model
{
    protected $fillable = ['fault_code', 'asset_type', 'title', 'symptoms', 'diagnosis', 'solution', 'suggested_part_ids', 'image_paths', 'is_published', 'created_by'];

    protected function casts(): array
    {
        return ['suggested_part_ids' => 'array', 'image_paths' => 'array', 'is_published' => 'boolean'];
    }
}
