<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;

class ToolExecution extends Model
{
    use StoresUtc;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['arguments' => 'array', 'result' => 'array'];
    }
}
