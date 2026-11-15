<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    use StoresUtc;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['estimated_cost_usd' => 'float'];
    }

    public function toolExecutions(): HasMany
    {
        return $this->hasMany(ToolExecution::class);
    }
}
