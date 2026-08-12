<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasUuids;

    public const DEFAULT_SCOPE = [
        'core_updates' => true,
        'plugin_updates' => true,
        'theme_updates' => true,
        'security_scans' => true,
        'failed_login_monitoring' => true,
        'uptime' => true,
        'backup_verify' => false,
        'hours_included' => 2,
        'auto_apply_safe_updates' => false,
    ];

    protected $fillable = [
        'organization_id', 'client_id', 'name', 'scope',
        'monthly_budget_cents', 'maintenance_tier', 'currency', 'active',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function allows(string $key): bool
    {
        $scope = array_merge(self::DEFAULT_SCOPE, $this->scope ?? []);

        return (bool) ($scope[$key] ?? false);
    }
}
