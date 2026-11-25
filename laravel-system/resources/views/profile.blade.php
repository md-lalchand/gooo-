@extends('layouts.app')
@section('title', 'My Profile — ' . $brand['company_name'])
@section('content')
<div class="max-w-5xl mx-auto px-4 py-10 space-y-6">
    <div class="rounded-2xl bg-white border border-slate-200 p-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            @if(!empty($user->avatar_path))
                <img src="{{ asset($user->avatar_path) }}" alt="{{ $user->name }}" class="h-14 w-14 rounded-2xl object-cover border border-slate-200">
            @else
                <div class="h-14 w-14 rounded-2xl flex items-center justify-center text-white font-extrabold text-lg" style="background-color: var(--brand-primary)">
                    {{ strtoupper(substr($user->name, 0, 1)) }}
                </div>
            @endif
            <div>
                <h1 class="text-2xl font-extrabold text-slate-900">{{ $user->name }}</h1>
                <p class="text-xs text-slate-500">{{ strtoupper($user->role) }} · {{ $user->email }} @if($user->roll_number) · ID: {{ $user->roll_number }} @endif</p>
            </div>
        </div>
        <a href="{{ route('portal.index') }}" class="rounded-xl text-white px-4 py-2 text-xs font-bold" style="background-color: var(--brand-primary)">Back to Portal</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div class="lg:col-span-7 bg-white rounded-2xl border border-slate-200 p-6 space-y-4">
            <h2 class="text-lg font-extrabold text-slate-900">Edit Account Details</h2>
            <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf @method('PATCH')
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Full Name *</label>
                        <input type="text" name="name" required value="{{ $user->name }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Phone</label>
                        <input type="text" name="phone" value="{{ $user->phone }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Education / Qualification</label>
                        <input type="text" name="education" value="{{ $user->education }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Subject Specialty</label>
                        <input type="text" name="subject_specialty" value="{{ $user->subject_specialty }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Bio</label>
                    <textarea name="bio" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">{{ $user->bio }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Avatar Photo (optional)</label>
                    <input type="file" name="avatar_file" accept="image/png,image/jpeg,image/webp" class="w-full rounded-xl border border-slate-300 px-3 py-1.5 text-xs">
                </div>
                <button type="submit" class="rounded-xl text-white font-bold px-5 py-2.5 text-xs" style="background-color: var(--brand-accent)">Save Profile</button>
            </form>
        </div>

        <div class="lg:col-span-5 space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-3">
                <h2 class="text-lg font-extrabold text-slate-900">Change Password</h2>
                <form method="POST" action="{{ route('profile.password') }}" class="space-y-3">
                    @csrf @method('PUT')
                    <input type="password" name="current_password" required placeholder="Current Password" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <input type="password" name="password" required minlength="12" placeholder="New Password (12+ chars)" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <input type="password" name="password_confirmation" required minlength="12" placeholder="Confirm New Password" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <button type="submit" class="rounded-xl text-white font-bold px-5 py-2 text-xs" style="background-color: var(--brand-primary)">Update Password</button>
                </form>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-extrabold text-slate-900">Notifications ({{ $notifications->where('is_read', false)->count() }} unread)</h2>
                    @if($notifications->where('is_read', false)->count() > 0)
                        <form method="POST" action="{{ route('notifications.read_all') }}">
                            @csrf
                            <button type="submit" class="text-xs font-bold text-indigo-700">Mark all read</button>
                        </form>
                    @endif
                </div>
                @forelse($notifications as $notif)
                    <div class="rounded-xl border {{ $notif->is_read ? 'border-slate-200' : 'border-indigo-200 bg-indigo-50/40' }} p-3 text-xs flex items-start justify-between gap-2">
                        <div>
                            <p class="font-bold text-slate-900">{{ $notif->title }}</p>
                            <p class="text-slate-600 mt-0.5">{{ $notif->body }}</p>
                        </div>
                        @if(!$notif->is_read)
                            <form method="POST" action="{{ route('notifications.read', $notif) }}">
                                @csrf
                                <button type="submit" class="text-[11px] font-bold text-indigo-700">Read</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-xs text-slate-500">No notifications yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
