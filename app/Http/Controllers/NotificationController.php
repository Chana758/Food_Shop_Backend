<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     * Logged-in user's own notifications, newest first, with unread count.
     */
    public function index(Request $request)
    {
        try {
            $notifications = Notification::where('user_id', Auth::id())
                ->when($request->type, fn($q) => $q->where('type', $request->type))
                ->when($request->unread_only, fn($q) => $q->where('is_read', false))
                ->latest()
                ->paginate($request->per_page ?? 20);

            $unreadCount = Notification::where('user_id', Auth::id())
                ->where('is_read', false)
                ->count();

            return response()->json([
                'status' => 'success',
                'data'   => $notifications,
                'meta'   => ['unread_count' => $unreadCount],
            ], 200);

        } catch (\Throwable $th) {
            Log::error('NotificationController@index: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * GET /api/notifications/{id}
     * View a single notification and mark it as read automatically.
     */
    public function show($id)
    {
        try {
            $notification = Notification::where('user_id', Auth::id())->findOrFail($id);

            // Use the Model's markAsRead() helper — it guards the update
            // with an is_read check so we don't update read_at repeatedly.
            $notification->markAsRead();

            return response()->json(['status' => 'success', 'data' => $notification->fresh()], 200);

        } catch (\Throwable $th) {
            Log::error('NotificationController@show: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * POST /api/admin/notifications
     * Admin manually sends a notification to a specific user.
     * Also called internally from other controllers (ReservationController,
     * ContactController) to notify users of status changes.
     *
     * FIX: wrapped in try/catch so failed Notification::create() returns a
     * structured error instead of a raw Laravel exception page.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id'        => 'required|exists:users,id',
            'title'          => 'required|string|max:255',
            'message'        => 'required|string',
            'type'           => 'nullable|string|max:50',
            'reference_id'   => 'nullable|integer',
            'reference_type' => 'nullable|string|max:255',
        ]);

        try {
            $notification = Notification::create([
                'user_id'        => $validated['user_id'],
                'title'          => $validated['title'],
                'message'        => $validated['message'],
                'type'           => $validated['type'] ?? Notification::TYPE_GENERAL,
                'reference_id'   => $validated['reference_id'] ?? null,
                'reference_type' => $validated['reference_type'] ?? null,
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Notification sent successfully.',
                'data'    => $notification,
            ], 201);

        } catch (\Throwable $th) {
            Log::error('NotificationController@store: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * PUT /api/notifications/{id}/read
     * Mark a specific notification as read.
     */
    public function markAsRead($id)
    {
        try {
            $notification = Notification::where('user_id', Auth::id())->findOrFail($id);
            $notification->markAsRead();

            return response()->json([
                'status'  => 'success',
                'message' => 'Notification marked as read.',
                'data'    => $notification->fresh(),
            ], 200);

        } catch (\Throwable $th) {
            Log::error('NotificationController@markAsRead: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * POST /api/notifications/read-all
     * Mark all of the logged-in user's notifications as read in one query.
     */
    public function markAllAsRead()
    {
        try {
            $count = Notification::where('user_id', Auth::id())
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);

            return response()->json([
                'status'  => 'success',
                'message' => "Marked {$count} notification(s) as read.",
            ], 200);

        } catch (\Throwable $th) {
            Log::error('NotificationController@markAllAsRead: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/notifications/{id}
     * Owner deletes their own notification.
     */
    public function destroy($id)
    {
        try {
            Notification::where('user_id', Auth::id())->findOrFail($id)->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Notification deleted.',
            ], 200);

        } catch (\Throwable $th) {
            Log::error('NotificationController@destroy: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }
}