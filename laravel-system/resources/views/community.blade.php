@extends('layouts.app')
@section('title', 'Community — ' . $brand['company_name'])
@section('content')
<div class="max-w-5xl mx-auto px-4 py-10 space-y-6">
    <div>
        <h1 class="text-3xl font-extrabold text-slate-900">{{ $brand['company_name'] }} Community &amp; Announcements</h1>
        <p class="text-sm text-slate-600 mt-1">Study tips, announcements, and articles from our faculty and learners.</p>
    </div>

    @auth
        <form method="POST" action="{{ route('community.post') }}" class="bg-white rounded-2xl border border-slate-200 p-5 space-y-3">
            @csrf
            <h2 class="text-sm font-extrabold text-slate-900">Start a Discussion</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <input type="text" name="title" required placeholder="Discussion Title" class="sm:col-span-2 rounded-xl border border-slate-300 px-3 py-2 text-xs">
                <input type="text" name="category" required value="Study Tips" placeholder="Topic Category" class="rounded-xl border border-slate-300 px-3 py-2 text-xs">
            </div>
            <textarea name="content" rows="2" required placeholder="Share a question, insight, or study tip..." class="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs"></textarea>
            <button type="submit" class="rounded-xl text-white font-bold px-4 py-2 text-xs" style="background-color: var(--brand-accent)">Publish Post</button>
        </form>
    @endauth

    @forelse($posts as $post)
        <article class="bg-white rounded-2xl border border-slate-200 p-6 space-y-3">
            <div class="flex items-center justify-between gap-2 text-xs text-slate-500">
                <span class="font-bold text-indigo-700">{{ $post->category }}</span>
                <span>{{ $post->created_at?->format('M d, Y') }} · {{ $post->author?->name ?? 'Admin' }}</span>
            </div>
            <h2 class="text-xl font-extrabold text-slate-900">{{ $post->title }}</h2>
            <p class="text-sm text-slate-700 whitespace-pre-line">{{ $post->content }}</p>
            <form method="POST" action="{{ route('community.like', $post) }}">
                @csrf
                <button type="submit" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50">
                    Helpful ({{ $post->likes }})
                </button>
            </form>
        </article>
    @empty
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-600">
            No community articles published yet.
        </div>
    @endforelse
</div>
@endsection
