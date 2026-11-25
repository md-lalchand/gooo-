<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'actor_id', 'actor_name', 'actor_role', 'action',
        'target_type', 'target_id', 'details_json', 'ip_address',
    ];

    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
