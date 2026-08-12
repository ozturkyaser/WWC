<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceSequence extends Model
{
    protected $fillable = ['organization_id', 'year', 'last_number'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
