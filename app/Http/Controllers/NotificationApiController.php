<?php

namespace App\Http\Controllers;

use App\Models\TaskNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $databaseNotifications = $user->notifications()->latest()->limit(12)->get();
        $taskNotifications = TaskNotification::query()
            ->where('recipient_id', $user->id)
            ->latest()
            ->limit(12)
            ->get();

        $notifications = $databaseNotifications
            ->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'message' => $notification->data['message'] ?? 'Bạn có thông báo mới.',
                'type' => $notification->data['type'] ?? 'info',
                'url' => $notification->data['url'] ?? route('home'),
                'read_at' => $notification->read_at?->toISOString(),
                'time_ago' => $notification->created_at?->diffForHumans(),
                'created_at' => $notification->created_at,
            ])
            ->concat($taskNotifications->map(fn (TaskNotification $notification): array => [
                'id' => 'task-'.$notification->id,
                'message' => $notification->message,
                'type' => 'info',
                'url' => route('notification_read', $notification),
                'read_at' => $notification->is_read ? $notification->updated_at?->toISOString() : null,
                'time_ago' => $notification->created_at?->diffForHumans(),
                'created_at' => $notification->created_at,
            ]))
            ->sortByDesc('created_at')
            ->take(12)
            ->values()
            ->map(function (array $notification): array {
                unset($notification['created_at']);

                return $notification;
            });

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count()
                + TaskNotification::query()->where('recipient_id', $user->id)->where('is_read', false)->count(),
            'notifications' => $notifications,
        ]);
    }

    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        if (str_starts_with($notification, 'task-')) {
            $legacyId = (int) str($notification)->after('task-')->value();
            $taskNotification = TaskNotification::query()
                ->where('recipient_id', $request->user()->id)
                ->findOrFail($legacyId);
            $taskNotification->update(['is_read' => true]);
        } else {
            $databaseNotification = $request->user()
                ->notifications()
                ->whereKey($notification)
                ->firstOrFail();
            $databaseNotification->markAsRead();
        }

        return response()->json(['message' => 'Đã đánh dấu thông báo là đã đọc.']);
    }
}
