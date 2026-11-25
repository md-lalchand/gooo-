<?php

namespace App\Http\Controllers;

use App\Models\ClaimedEbook;
use App\Models\InAppNotification;
use App\Support\AuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user()->load('branch');
        $notifications = InAppNotification::where('user_id', $user->id)
            ->latest()
            ->take(20)
            ->get();
        $claimedEbooks = ClaimedEbook::where('user_id', $user->id)
            ->with('ebook')
            ->latest()
            ->get();

        return view('profile', compact('user', 'notifications', 'claimedEbooks'));
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:40',
            'education' => 'nullable|string|max:180',
            'subject_specialty' => 'nullable|string|max:120',
            'bio' => 'nullable|string|max:1500',
            'avatar_file' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        $payload = [
            'name' => trim($data['name']),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'education' => trim((string) ($data['education'] ?? '')),
            'subject_specialty' => trim((string) ($data['subject_specialty'] ?? '')),
            'bio' => trim((string) ($data['bio'] ?? '')),
        ];

        if ($request->hasFile('avatar_file')) {
            $file = $request->file('avatar_file');
            $ext = strtolower($file->extension() ?: 'png');
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $ext = 'png';
            }
            $dir = public_path('uploads/avatars');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $filename = 'avatar-' . $user->id . '-' . time() . '.' . $ext;
            $file->move($dir, $filename);
            $payload['avatar_path'] = 'uploads/avatars/' . $filename;
        }

        $user->update($payload);
        AuditRecorder::log($request, 'profile.updated', 'user', (string) $user->id);

        return back()->with('status', 'Profile updated successfully.');
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Current password does not match our records.']);
        }

        $user->update(['password' => Hash::make($data['password'])]);
        AuditRecorder::log($request, 'profile.password_changed', 'user', (string) $user->id);

        return back()->with('status', 'Password updated securely.');
    }

    public function markNotificationRead(Request $request, InAppNotification $notification)
    {
        if ((int) $notification->user_id !== (int) $request->user()->id) {
            abort(403);
        }
        $notification->update(['is_read' => true]);
        return back()->with('status', 'Notification marked as read.');
    }

    public function markAllNotificationsRead(Request $request)
    {
        InAppNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return back()->with('status', 'All notifications marked as read.');
    }
}
