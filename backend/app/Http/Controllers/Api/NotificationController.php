<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Notification;
use Illuminate\Http\Request;

/**
 * In-app notifications for the current user (web + android).
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Notification::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at');

        // `unread_only` query string tiba sebagai string "true"/"false", bukan bool;
        // aturan 'boolean' menolaknya (422). $request->boolean() menormalkan.
        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $paginated = $query->paginate($validated['per_page'] ?? 15);

        return ApiResponse::success(
            collect($paginated->items())->map(fn (Notification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'message' => $n->message,
                'data' => $n->data,
                'read_at' => $n->read_at?->format('Y-m-d H:i:s'),
                'created_at' => $n->created_at?->format('Y-m-d H:i:s'),
            ])->all(),
            'Sukses',
            200,
            [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
                'unread_count' => Notification::where('user_id', $request->user()->id)->whereNull('read_at')->count(),
            ],
        );
    }

    public function markRead(Request $request, int $id)
    {
        $notification = Notification::where('user_id', $request->user()->id)->findOrFail($id);
        $notification->update(['read_at' => now()]);

        return ApiResponse::success(['id' => $notification->id, 'read_at' => $notification->read_at?->format('Y-m-d H:i:s')], 'Ditandai sudah dibaca');
    }

    public function markAllRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return ApiResponse::success(null, 'Semua notifikasi ditandai sudah dibaca');
    }
}
