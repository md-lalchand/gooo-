@extends('layouts.app')
@section('title', 'Contact Us — ' . $brand['company_name'])
@section('content')
<div class="max-w-5xl mx-auto px-4 py-12 grid grid-cols-1 lg:grid-cols-12 gap-8">
    <div class="lg:col-span-5 space-y-5">
        <h1 class="text-3xl font-extrabold text-slate-900">Contact {{ $brand['company_name'] }}</h1>
        <p class="text-sm text-slate-600">Have questions about admissions, batches, online exams, or branch support? Reach out directly.</p>
        <div class="rounded-2xl bg-white border border-slate-200 p-5 space-y-3 text-sm">
            @if(!empty($brand['support_email']))
                <div>
                    <p class="text-xs font-bold uppercase text-slate-500">Email</p>
                    <p class="font-semibold text-slate-900">{{ $brand['support_email'] }}</p>
                </div>
            @endif
            @if(!empty($brand['support_phone']))
                <div>
                    <p class="text-xs font-bold uppercase text-slate-500">Phone</p>
                    <p class="font-semibold text-slate-900">{{ $brand['support_phone'] }}</p>
                </div>
            @endif
            @if(!empty($brand['office_address']))
                <div>
                    <p class="text-xs font-bold uppercase text-slate-500">Head Office</p>
                    <p class="font-semibold text-slate-900">{{ $brand['office_address'] }}</p>
                </div>
            @endif
        </div>
    </div>

    <div class="lg:col-span-7 bg-white rounded-2xl border border-slate-200 p-6">
        <h2 class="text-lg font-extrabold text-slate-900 mb-4">Send an Inquiry</h2>
        <form method="POST" action="{{ route('contact.submit') }}" class="space-y-3">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <input type="text" name="name" required placeholder="Your Full Name" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                <input type="email" name="email" required placeholder="Email Address" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <input type="text" name="phone" placeholder="Phone Number (optional)" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
                <select name="branch_id" class="rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm">
                    <option value="">General / Online Support</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->city }})</option>
                    @endforeach
                </select>
            </div>
            <input type="text" name="subject" required placeholder="Subject" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm">
            <textarea name="message" required rows="4" placeholder="How can our academic team assist you?" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm"></textarea>
            <button type="submit" class="rounded-xl text-white font-bold px-6 py-2.5 text-sm" style="background-color: var(--brand-accent)">Submit Message</button>
        </form>
    </div>
</div>
@endsection
