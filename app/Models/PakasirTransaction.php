<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'order_id',
    'txn_id',
    'order_type',
    'item_id',
    'description',
    'amount',
    'payment_method',
    'status',
    'is_sandbox',
    'customer_data',
    'provider_payload',
    'completed_at',
])]
class PakasirTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'is_sandbox' => 'boolean',
            'customer_data' => 'encrypted:array',
            'provider_payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
