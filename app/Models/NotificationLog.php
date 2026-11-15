<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationStatus;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    use StoresUtc;

    protected $table = 'notification_log';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => NotificationStatus::class,
            'sent_at' => 'immutable_datetime',
        ];
    }
}
