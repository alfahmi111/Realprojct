<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Tenancy\BelongsToSubscriber;
use Illuminate\Database\Eloquent\Model;

/** A document in the vault (REQ-0265…REQ-0270): one version of one document of a property, building, unit or party. */
class Document extends Model
{
    use Auditable, BelongsToSubscriber;

    protected $fillable = ['documentable_type', 'documentable_id', 'property_id', 'category', 'kind', 'title_ar', 'file_path', 'mime', 'expires_on', 'version', 'replaces_id', 'uploaded_by'];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'superseded_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function uploader(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function property(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function views(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DocumentView::class);
    }

    public function scopeCurrent($q)
    {
        return $q->whereNull('superseded_at')->whereNull('archived_at');
    }
}
