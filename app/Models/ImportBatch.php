<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Staged AI import awaiting the manager's confirmation (REQ-0122). */
class ImportBatch extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $attributes = ['status' => 'staged', 'extracted' => '[]'];

    protected $fillable = ['uploaded_by', 'kind', 'file_path', 'extracted'];

    protected function casts(): array
    {
        return ['extracted' => 'array', 'reviewed' => 'array', 'created_refs' => 'array'];
    }
}
