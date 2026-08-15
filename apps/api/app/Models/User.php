<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'current_organization_id'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_enabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_enabled_at !== null && $this->two_factor_secret !== null;
    }

    public function getTwoFactorSecret(): ?string
    {
        return $this->two_factor_secret
            ? \Illuminate\Support\Facades\Crypt::decryptString($this->two_factor_secret)
            : null;
    }

    public function setTwoFactorSecret(?string $secret): void
    {
        $this->two_factor_secret = $secret === null
            ? null
            : \Illuminate\Support\Facades\Crypt::encryptString($secret);
    }

    /** @return string[] */
    public function getRecoveryCodes(): array
    {
        if (! $this->two_factor_recovery_codes) {
            return [];
        }

        return json_decode(\Illuminate\Support\Facades\Crypt::decryptString($this->two_factor_recovery_codes), true) ?: [];
    }

    /** @param string[] $codes */
    public function setRecoveryCodes(array $codes): void
    {
        $this->two_factor_recovery_codes = \Illuminate\Support\Facades\Crypt::encryptString(json_encode(array_values($codes)));
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function belongsToOrganization(string $organizationId): bool
    {
        return $this->memberships()->where('organization_id', $organizationId)->exists();
    }
}
