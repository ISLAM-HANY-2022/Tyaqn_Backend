<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'profile_image', // أضفنا هذا العمود بناءً على الهيكلة 
        'job_title',     // أضفنا هذا العمود 
        'bio',           // أضفنا هذا العمود 
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function articles() { return $this->hasMany(Article::class); }
    public function reports() { return $this->hasMany(Report::class); } 
    public function feedbacks() { return $this->hasMany(Feedback::class); }
    public function verifications() { return $this->hasMany(Verification::class); } 
    public function notifications() { return $this->hasMany(Notification::class); }
    public function bookmarks(){ return $this->belongsToMany(Article::class, 'bookmarks')->withTimestamps();}
}
