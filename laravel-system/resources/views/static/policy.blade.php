@extends('layouts.app')
@php
    $titles = [
        'terms' => 'Terms & Conditions',
        'privacy' => 'Privacy Policy',
        'refund' => 'Refund & Cancellation Policy',
    ];
    $pageTitle = $titles[$type] ?? 'Platform Policy';
@endphp
@section('title', $pageTitle . ' — ' . $brand['company_name'])
@section('content')
<div class="max-w-4xl mx-auto px-4 py-12">
    <div class="bg-white rounded-2xl border border-slate-200 p-8 space-y-5">
        <p class="text-xs font-bold uppercase text-indigo-600">{{ $brand['company_name'] }}</p>
        <h1 class="text-3xl font-extrabold text-slate-900">{{ $pageTitle }}</h1>

        @if($type === 'terms')
            <p class="text-sm text-slate-700 leading-relaxed">By accessing {{ $brand['company_name'] }}, enrolling in programs, or taking online assessments, learners agree to maintain academic integrity and keep account credentials confidential.</p>
            <p class="text-sm text-slate-700 leading-relaxed">Course materials, lecture sheets, e-books, and examination content are licensed exclusively for enrolled students and may not be redistributed.</p>
        @elseif($type === 'privacy')
            <p class="text-sm text-slate-700 leading-relaxed">{{ $brand['company_name'] }} collects only necessary academic profile information (name, email, phone, branch assignment, and assessment records) to deliver learning services and issue verified certificates.</p>
            <p class="text-sm text-slate-700 leading-relaxed">All sessions and password resets are protected with cryptographic hashing and signed webhooks. For privacy requests, contact {{ $brand['support_email'] }}.</p>
        @else
            <p class="text-sm text-slate-700 leading-relaxed">Refund requests for course enrollments or store items must be submitted within 7 days of payment verification with the original invoice number and transaction reference.</p>
            <p class="text-sm text-slate-700 leading-relaxed">Approved refunds are processed to the original payment channel or campus accounts desk. Contact {{ $brand['support_email'] }} for assistance.</p>
        @endif
    </div>
</div>
@endsection
