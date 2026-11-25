<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClaimedEbook extends Model
{
    protected $fillable = ['user_id', 'ebook_id', 'trx_id', 'claimed_at'];
    protected $casts = ['claimed_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
    public function ebook() { return $this->belongsTo(Ebook::class); }
}
