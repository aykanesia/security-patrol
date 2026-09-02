<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;

/**
 * In-app notification creator (supervisor alerts etc).
 */
class NotificationService
{
    public static function send(
        User|int $user,
        string $type,
        string $title,
        string $message,
        ?array $data = null,
    ): Notification {
        return Notification::create([
            'user_id' => $user instanceof User ? $user->id : $user,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }
}
