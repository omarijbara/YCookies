<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    /** @use HasFactory<\Database\Factories\LanguageFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'is_active',
        'is_rtl',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_rtl' => 'boolean',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class);
    }
}
