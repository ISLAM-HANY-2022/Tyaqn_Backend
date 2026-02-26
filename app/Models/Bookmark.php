<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bookmark extends Model
{
    use HasFactory;
    // السماح بتخزين البيانات في هذه الأعمدة
    protected $fillable = ['user_id', 'article_id'];

    // لو عايز تربطهم مباشرة (اختياري)
    public function user() { return $this->belongsTo(User::class); }
    public function article() { return $this->belongsTo(Article::class); }
}