<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use HasFactory;

    protected $fillable = ['category_id', 'verification_id', 'file_hash', 
    'title', 'summary', 'content', 'image_url']; 

    public function verification() { return $this->belongsTo(Verification::class);}
    public function category() { return $this->belongsTo(Category::class); }
    public function favoritedBy(){ return $this->belongsToMany(User::class, 'bookmarks')->withTimestamps();}
}
