<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'subject', 'message', 'branch_id', 'status', 'admin_note'];

    public function branch() { return $this->belongsTo(Branch::class); }
}
