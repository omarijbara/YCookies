<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RuntimeRevision extends Model
{
    protected $fillable = [
        'domain_id',
        'revision_number',
        'schema_version',
        'status',
        'manifest_json',
        'manifest_hash',
        'manifest_signature',
        'base_artifact_json',
        'base_artifact_hash',
        'route_index_json',
        'route_index_hash',
        'compiled_by',
        'compile_inputs_hash',
        'published_at',
        'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'published_at'    => 'datetime',
            'rolled_back_at'  => 'datetime',
        ];
    }

    // ─── Relationships ─────────────────────────────────────────────

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function overlays(): HasMany
    {
        return $this->hasMany(RuntimeOverlay::class, 'revision_id');
    }

    public function compiler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'compiled_by');
    }

    // ─── Scopes ────────────────────────────────────────────────────

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeForDomain($query, int $domainId)
    {
        return $query->where('domain_id', $domainId);
    }

    // ─── State Checks ──────────────────────────────────────────────

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isRolledBack(): bool
    {
        return $this->status === 'rolled_back';
    }

    // ─── Decoded Accessors ─────────────────────────────────────────

    public function getManifest(): array
    {
        return json_decode($this->manifest_json, true);
    }

    public function getBaseArtifact(): array
    {
        return json_decode($this->base_artifact_json, true);
    }

    public function getRouteIndex(): ?array
    {
        return $this->route_index_json
            ? json_decode($this->route_index_json, true)
            : null;
    }
}
