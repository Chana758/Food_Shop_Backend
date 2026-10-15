<?php

namespace App\Http\Controllers;

use App\Events\ContactSubmitted;
use App\Mail\ContactReplyMail;
use App\Models\Contact;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    // Count messages by status. Register before /{id} in routes.
    public function stats()
    {
        try {
            $counts = Contact::select('status', DB::raw('COUNT(*) as cnt'))
                ->groupBy('status')
                ->pluck('cnt', 'status')
                ->toArray();

            return response()->json([
                'status' => 'success',
                'data'   => [
                    'total'   => array_sum($counts),
                    'unread'  => (int) ($counts[Contact::STATUS_UNREAD]  ?? 0),
                    'read'    => (int) ($counts[Contact::STATUS_READ]    ?? 0),
                    'replied' => (int) ($counts[Contact::STATUS_REPLIED] ?? 0),
                ],
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ContactController@stats: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // List messages, newest first. Optional ?status= filter.
    public function index(Request $request)
    {
        try {
            $contacts = Contact::with(['user:id,name', 'repliedBy:id,name'])
                ->when($request->status, fn($q) => $q->where('status', $request->status))
                ->latest()
                ->paginate($request->per_page ?? 15);

            return response()->json(['status' => 'success', 'data' => $contacts], 200);

        } catch (\Throwable $th) {
            Log::error('ContactController@index: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // Show one message and mark it as read.
    public function show($id)
    {
        try {
            $contact = Contact::with(['user:id,name', 'repliedBy:id,name'])->findOrFail($id);

            if ($contact->status === Contact::STATUS_UNREAD) {
                $contact->update(['status' => Contact::STATUS_READ]);
            }

            return response()->json(['status' => 'success', 'data' => $contact], 200);

        } catch (\Throwable $th) {
            Log::error('ContactController@show: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // Save a contact message (guests allowed).
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|max:255',
            'phone'   => 'nullable|string|max:20',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string',
        ]);

        try {
            $contact = Contact::create([
                'user_id' => Auth::id(), // null for guests
                'name'    => $validated['name'],
                'email'   => $validated['email'],
                'phone'   => $validated['phone'] ?? null,
                'subject' => $validated['subject'] ?? null,
                'message' => $validated['message'],
                'status'  => Contact::STATUS_UNREAD,
            ]);

            // Broadcast to admin (failure must not break submit)
            try {
                event(new ContactSubmitted($contact));
            } catch (\Throwable $broadcastError) {
                Log::warning('ContactController@store broadcast failed: ' . $broadcastError->getMessage());
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Your message has been sent successfully.',
                'data'    => $contact,
            ], 201);

        } catch (\Throwable $th) {
            Log::error('ContactController@store: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // Save the admin reply, then email and notify the sender.
    public function reply(Request $request, $id)
    {
        $validated = $request->validate([
            'reply_message' => 'required|string',
        ]);

        try {
            $contact = Contact::with('user')->findOrFail($id);

            $contact->update([
                'reply_message' => $validated['reply_message'],
                'replied_by'    => Auth::id(),
                'replied_at'    => now(),
                'status'        => Contact::STATUS_REPLIED,
            ]);

            // Always email (guests and users)
            try {
                Mail::to($contact->email)->send(new ContactReplyMail($contact));
            } catch (\Throwable $mailError) {
                // Reply stays saved if mail fails
                Log::error('ContactController@reply mail failed: ' . $mailError->getMessage());
            }

            // In-app notification for logged-in senders
            if ($contact->user_id) {
                Notification::create([
                    'user_id'        => $contact->user_id,
                    'title'          => 'Reply to your message',
                    'message'        => "We've replied to your message" .
                        ($contact->subject ? ": \"{$contact->subject}\"" : '.'),
                    'type'           => Notification::TYPE_GENERAL,
                    'reference_id'   => $contact->id,
                    'reference_type' => 'contact',
                ]);
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Reply sent successfully.',
                'data'    => $contact->fresh(['user:id,name', 'repliedBy:id,name']),
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ContactController@reply: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }

    // Delete a contact message.
    public function destroy($id)
    {
        try {
            Contact::findOrFail($id)->delete();

            return response()->json([
                'status'  => 'success',
                'message' => 'Contact message deleted.',
            ], 200);

        } catch (\Throwable $th) {
            Log::error('ContactController@destroy: ' . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => $th->getMessage()], 500);
        }
    }
}