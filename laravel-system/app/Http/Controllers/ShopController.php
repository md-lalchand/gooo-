<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\ClaimedEbook;
use App\Models\Coupon;
use App\Models\Ebook;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function products(Request $request)
    {
        $products = Product::latest()->get();
        $branches = Branch::where('status', 'active')->get();
        $myOrders = $request->user()
            ? Order::where('user_id', $request->user()->id)->latest()->take(10)->get()
            : collect();
        return view('shop.products', compact('products', 'branches', 'myOrders'));
    }

    public function ebooks(Request $request)
    {
        $ebooks = Ebook::latest()->get();
        $claimedEbookIds = $request->user()
            ? ClaimedEbook::where('user_id', $request->user()->id)->pluck('ebook_id')->all()
            : [];
        return view('shop.ebooks', compact('ebooks', 'claimedEbookIds'));
    }

    public function placeOrder(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1|max:20',
            'phone' => 'required|string|max:40',
            'delivery_address' => 'required|string|max:300',
            'branch_pickup_id' => 'nullable|exists:branches,id',
            'coupon_code' => 'nullable|string|max:40',
            'payment_method' => 'nullable|string|max:80',
            'trx_id' => 'nullable|string|max:80',
        ]);

        $product = Product::findOrFail($data['product_id']);
        if ($product->stock < $data['quantity']) {
            return back()->withErrors(['order' => "Insufficient stock for {$product->title}."]);
        }

        $subtotal = $product->price * $data['quantity'];
        $discountAmount = 0;
        $couponUsed = null;
        if (!empty($data['coupon_code'])) {
            $coupon = Coupon::where('code', strtoupper(trim($data['coupon_code'])))
                ->where('active', true)
                ->whereIn('applies_to', ['all', 'products'])
                ->first();
            if ($coupon) {
                $discountAmount = (int) round($subtotal * ($coupon->discount_percent / 100));
                $couponUsed = $coupon->code;
            }
        }

        $deliveryFee = empty($data['branch_pickup_id']) ? 50 : 0;
        $total = max(0, ($subtotal - $discountAmount) + $deliveryFee);
        $invoiceNo = 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        Order::create([
            'user_id' => $request->user()->id,
            'phone' => $data['phone'],
            'delivery_address' => $data['delivery_address'],
            'branch_pickup_id' => $data['branch_pickup_id'] ?? null,
            'items' => [['title' => $product->title, 'qty' => $data['quantity'], 'price' => $product->price]],
            'delivery_fee' => $deliveryFee,
            'discount_amount' => $discountAmount,
            'coupon_used' => $couponUsed,
            'payment_method' => trim((string) ($data['payment_method'] ?? 'Campus / Online')),
            'trx_id' => !empty($data['trx_id']) ? strtoupper(trim((string) $data['trx_id'])) : null,
            'invoice_no' => $invoiceNo,
            'total_amount' => $total,
        ]);

        $product->decrement('stock', $data['quantity']);

        return back()->with('status', "Order {$invoiceNo} placed successfully!");
    }

    public function claimEbook(Request $request, Ebook $ebook)
    {
        $trxId = trim((string) $request->input('trx_id', ''));
        if (!$ebook->is_free && $ebook->price > 0 && $trxId === '') {
            return back()->withErrors(['ebook' => "Paid E-Book '{$ebook->title}' requires verified payment checkout."]);
        }

        ClaimedEbook::firstOrCreate(
            ['user_id' => $request->user()->id, 'ebook_id' => $ebook->id],
            ['trx_id' => $trxId !== '' ? strtoupper($trxId) : null, 'claimed_at' => now()]
        );

        return back()->with('status', "E-Book '{$ebook->title}' unlocked in your library!");
    }
}
