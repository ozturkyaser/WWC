<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteBackup extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'site_id', 'backup_id', 'type', 'label',
        'status', 'size_bytes', 'sha256', 'storage_path', 'wp_version',
        'file_count', 'parent_backup_id', 'backup_created_at', 'uploaded_at', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'backup_created_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
