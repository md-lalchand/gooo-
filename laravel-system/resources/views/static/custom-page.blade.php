@extends('layouts.app')
@section('title', $page->title . ' — ' . $brand['company_name'])
@section('content')
<div class="max-w-4xl mx-auto px-4 py-12">
    <article class="bg-white rounded-2xl border border-slate-200 p-8 space-y-4">
        <p class="text-xs font-bold uppercase text-indigo-600">{{ $brand['company_name'] }}</p>
        <h1 class="text-3xl font-extrabold text-slate-900">{{ $page->title }}</h1>
        @if($page->subtitle)
            <p class="text-base text-slate-600">{{ $page->subtitle }}</p>
        @endif
        <div class="pt-4 border-t border-slate-100 text-sm text-slate-700 leading-relaxed whitespace-pre-line">{{ $page->content }}</div>
    </article>
</div>
@endsection
