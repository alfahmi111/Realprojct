<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Support\Database\Archivable;
use Illuminate\Database\Eloquent\Model;

/** Regional regulatory rule with region and effective dates (REQ-0196, REQ-0200, REQ-0204). Platform-level. */
class Policy extends Model
{
    use Archivable, Auditable;

    protected $fillable = ['kind', 'region', 'city', 'effective_from', 'effective_to', 'code', 'title_ar', 'title_en', 'payload', 'source_url', 'needs_official_check'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'payload' => 'array', 'needs_official_check' => 'boolean', 'archived_at' => 'datetime'];
    }
}
