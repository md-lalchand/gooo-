@extends('layouts.app')
@section('title', 'About Us — ' . $brand['company_name'])
@section('content')
<div class="max-w-6xl mx-auto px-4 py-12 space-y-10">
    <div class="rounded-3xl text-white p-8 sm:p-12" style="background-color: var(--brand-primary)">
        <p class="text-xs font-bold uppercase tracking-wider text-indigo-300">About {{ $brand['company_name'] }}</p>
        <h1 class="text-3xl sm:text-4xl font-extrabold mt-2">{{ $brand['tagline'] ?: ('Empowering Learners at ' . $brand['company_name']) }}</h1>
        <p class="text-slate-200 mt-4 max-w-3xl text-sm sm:text-base leading-relaxed">
            {{ $brand['company_name'] }} delivers structured courses, live interactive cohorts, online assessments, verified certifications, and multi-branch academic support through one unified platform.
        </p>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-8">
            <div class="rounded-2xl bg-white/10 p-4">
                <p class="text-2xl font-extrabold">{{ $stats['courses'] }}</p>
                <p class="text-xs text-slate-200 mt-0.5">Active Courses</p>
            </div>
            <div class="rounded-2xl bg-white/10 p-4">
                <p class="text-2xl font-extrabold">{{ $stats['teachers'] }}</p>
                <p class="text-xs text-slate-200 mt-0.5">Faculty Instructors</p>
            </div>
            <div class="rounded-2xl bg-white/10 p-4">
                <p class="text-2xl font-extrabold">{{ $stats['students'] }}</p>
                <p class="text-xs text-slate-200 mt-0.5">Enrolled Learners</p>
            </div>
            <div class="rounded-2xl bg-white/10 p-4">
                <p class="text-2xl font-extrabold">{{ $stats['branches'] }}</p>
                <p class="text-xs text-slate-200 mt-0.5">Active Campuses</p>
            </div>
        </div>
    </div>

    <div class="space-y-4">
        <h2 class="text-2xl font-extrabold text-slate-900">Our Active Campuses</h2>
        @if($branches->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                @foreach($branches as $b)
                    <div class="rounded-2xl bg-white border border-slate-200 p-5 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="font-mono text-xs font-bold text-indigo-700">{{ $b->code }}</span>
                            <span class="text-xs font-semibold text-emerald-700">{{ $b->city }}</span>
                        </div>
                        <h3 class="text-base font-extrabold text-slate-900">{{ $b->name }}</h3>
                        <p class="text-xs text-slate-600">{{ $b->address }}</p>
                        @if($b->phone)
                            <p class="text-xs font-semibold text-slate-700">Phone: {{ $b->phone }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div class="rounded-2xl bg-white border border-slate-200 p-6 text-sm text-slate-600">
                All programs are currently available online nationwide.
            </div>
        @endif
    </div>
</div>
@endsection
