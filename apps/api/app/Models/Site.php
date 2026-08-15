<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class Site extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'client_id', 'project_id', 'name', 'url', 'status',
        'wp_version', 'php_version', 'agent_version', 'hmac_secret_encrypted',
        'hmac_secret_previous_encrypted', 'key_id', 'ip_allowlist', 'health',
        'inventory', 'last_seen_at', 'paired_at',
        'staging_slug', 'staging_url', 'staging_admin_url',
        'staging_access_encrypted', 'staging_ready_at',
        'onboarding_status', 'onboarding_meta',
        'maintenance_agent_enabled', 'maintenance_cadence', 'maintenance_auto_apply',
        'maintenance_last_run_at', 'maintenance_next_run_at', 'maintenance_agent_meta',
        'backup_schedule', 'backup_last_scheduled_at',
    ];

    protected $hidden = [
        'hmac_secret_encrypted',
        'hmac_secret_previous_encrypted',
        'staging_access_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'ip_allowlist' => 'array',
            'health' => 'array',
            'inventory' => 'array',
            'onboarding_meta' => 'array',
            'maintenance_agent_meta' => 'array',
            'maintenance_agent_enabled' => 'boolean',
            'maintenance_auto_apply' => 'boolean',
            'last_seen_at' => 'datetime',
            'paired_at' => 'datetime',
            'staging_ready_at' => 'datetime',
            'maintenance_last_run_at' => 'datetime',
            'maintenance_next_run_at' => 'datetime',
            'backup_schedule' => 'array',
            'backup_last_scheduled_at' => 'datetime',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SiteEvent::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(AgentJob::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(VulnerabilityFinding::class);
    }

    public function maintenanceRuns(): HasMany
    {
        return $this->hasMany(MaintenanceRun::class);
    }

    public function serverBackups(): HasMany
    {
        return $this->hasMany(SiteBackup::class);
    }

    public function setHmacSecret(string $secret): void
    {
        $this->hmac_secret_encrypted = Crypt::encryptString($secret);
    }

    public function getHmacSecret(): ?string
    {
        return $this->hmac_secret_encrypted
            ? Crypt::decryptString($this->hmac_secret_encrypted)
            : null;
    }

    public function getPreviousHmacSecret(): ?string
    {
        return $this->hmac_secret_previous_encrypted
            ? Crypt::decryptString($this->hmac_secret_previous_encrypted)
            : null;
    }

    public function rotateHmacSecret(string $newSecret): void
    {
        $this->hmac_secret_previous_encrypted = $this->hmac_secret_encrypted;
        $this->setHmacSecret($newSecret);
        $this->key_id = bin2hex(random_bytes(8));
    }
}
