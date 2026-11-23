<?php

namespace App\Models;

use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** Who opened a vault document and when (REQ-0270). Append-only. */
class DocumentView extends Model
{
    use BelongsToSubscriber;

    public $timestamps = false;

    protected $fillable = ['document_id', 'user_id', 'action', 'viewed_at'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
