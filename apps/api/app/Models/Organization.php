<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'slug', 'billing_profile', 'hour_packages', 'maintenance_tiers',
        'patchstack_api_key', 'billing_day', 'alert_settings', 'hardening_templates',
    ];

    protected function casts(): array
    {
        return [
            'billing_profile' => 'array',
            'hour_packages' => 'array',
            'maintenance_tiers' => 'array',
            'alert_settings' => 'array',
            'hardening_templates' => 'array',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
