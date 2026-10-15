<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'payload', 'updated_by'])]
class StoreOperationState extends Model
{
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
