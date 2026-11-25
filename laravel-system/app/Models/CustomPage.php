<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomPage extends Model
{
    protected $fillable = ['slug', 'title', 'subtitle', 'content', 'published', 'show_in_footer'];

    protected $casts = [
        'published' => 'boolean',
        'show_in_footer' => 'boolean',
    ];
}
