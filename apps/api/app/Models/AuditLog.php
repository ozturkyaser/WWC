<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'user_id', 'site_id', 'action', 'meta', 'ip',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }
}
