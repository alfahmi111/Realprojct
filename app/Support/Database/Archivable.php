<?php

namespace App\Support\Database;

/**
 * Archive instead of delete (REQ-0168). archived_at is never mass-assignable; it changes only through archive().
 */
trait Archivable
{
    public function archive(): static
    {
        $this->forceFill(['archived_at' => now()])->save();
        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull($this->qualifyColumn('archived_at'));
    }
}
