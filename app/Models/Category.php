<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;
    
    // الحقول القابلة للتعبئة بناءً على الهيكلة 
    protected $fillable = ['name'];

    // القسم الواحد يحتوي على مقالات متعددة
    public function articles() { return $this->hasMany(Article::class); }
}
