<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScormAttempt extends Model
{
    protected $fillable = ['scorm_package_id', 'user_id', 'lesson_status', 'score', 'xp_awarded', 'completed_at'];
    protected $casts = ['completed_at' => 'datetime'];

    public function package() { return $this->belongsTo(ScormPackage::class, 'scorm_package_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
