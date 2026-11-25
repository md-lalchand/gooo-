<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeInvoice extends Model
{
    protected $fillable = [
        'invoice_no', 'user_id', 'course_id', 'branch_id', 'title',
        'installment_label', 'amount_due', 'amount_paid', 'due_date',
        'status', 'payment_method', 'trx_id', 'paid_at', 'recorded_by',
    ];

    protected $casts = ['paid_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
    public function course() { return $this->belongsTo(Course::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
}
