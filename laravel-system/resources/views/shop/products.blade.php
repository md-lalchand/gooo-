@extends('layouts.app')
@section('title', 'Store — ' . $brand['company_name'])
@section('content')
@php $currency = $brand['currency_symbol'] ?? '$'; @endphp
<div class="max-w-6xl mx-auto px-4 py-10 space-y-6">
    <h1 class="text-3xl font-extrabold text-slate-900">{{ $brand['company_name'] }} Store</h1>

    @if($products->count() > 0)
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @foreach($products as $p)
                <div class="bg-white rounded-2xl border border-slate-200 p-5 flex flex-col justify-between">
                    <div>
                        <span class="text-[11px] font-bold uppercase text-indigo-600">{{ $p->category }}</span>
                        <h2 class="font-extrabold text-slate-900 mt-1">{{ $p->title }}</h2>
                        <p class="text-sm text-slate-600 mt-1">{{ $p->description }}</p>
                        <p class="text-lg font-extrabold text-indigo-700 mt-3">{{ $currency . number_format($p->price) }} <span class="text-xs font-normal text-slate-500">({{ $p->stock }} in stock)</span></p>
                    </div>
                    @auth
                        <form method="POST" action="{{ route('shop.order') }}" class="mt-4 pt-4 border-t border-slate-100 space-y-2">
                            @csrf
                            <input type="hidden" name="product_id" value="{{ $p->id }}">
                            <div class="grid grid-cols-2 gap-2">
                                <input type="number" name="quantity" value="1" min="1" max="{{ max(1, $p->stock) }}" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                                <input type="text" name="phone" required placeholder="Phone" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                            </div>
                            <input type="text" name="delivery_address" required placeholder="Delivery Address" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                            @if($branches->count() > 0)
                                <select name="branch_pickup_id" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs bg-white">
                                    <option value="">Home Delivery</option>
                                    @foreach($branches as $b)
                                        <option value="{{ $b->id }}">Campus Pickup: {{ $b->name }}</option>
                                    @endforeach
                                </select>
                            @endif
                            <div class="grid grid-cols-2 gap-2">
                                <input type="text" name="coupon_code" placeholder="Coupon (optional)" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                                <input type="text" name="trx_id" placeholder="TrxID (optional)" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                            </div>
                            <button type="submit" class="w-full rounded-lg text-white font-bold py-2 text-xs" style="background-color: var(--brand-primary)">Order Now</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="mt-4 block text-center rounded-lg border border-slate-300 py-2 text-xs font-bold text-slate-700">Sign In to Order</a>
                    @endauth
                </div>
            @endforeach
        </div>
    @else
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-600">
            No store items listed yet.
        </div>
    @endif

    @auth
        @if(($myOrders ?? collect())->count() > 0)
            <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-3">
                <h2 class="text-lg font-extrabold text-slate-900">My Recent Store Orders</h2>
                @foreach($myOrders as $ord)
                    <div class="flex flex-wrap items-center justify-between border border-slate-200 rounded-xl px-4 py-2.5 text-xs">
                        <span><strong>{{ $ord->invoice_no ?? ('Order #' . $ord->id) }}</strong> · {{ $currency . number_format($ord->total_amount) }} @if($ord->coupon_used) (Coupon: {{ $ord->coupon_used }}) @endif</span>
                        <span class="uppercase font-bold text-indigo-700">{{ $ord->status }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    @endauth
</div>
@endsection
