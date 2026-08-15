<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'name', 'email', 'company', 'address', 'vat_id',
        'phone', 'notes', 'contract_until', 'sla_response_hours',
    ];

    protected function casts(): array
    {
        return [
            'contract_until' => 'date',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
