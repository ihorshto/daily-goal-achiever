<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;

class DataDeletionRequest extends Model
{
    use StoresUtc;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'requested_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
