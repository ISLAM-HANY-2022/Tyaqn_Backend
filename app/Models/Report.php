<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    use HasFactory;

    protected $fillable = ['user_id','verification_id','title', 'report_type', 'source_link', 'description', 'evidence_file', 'status', 'admin_feedback'];

    public function user() { return $this->belongsTo(User::class); }
    public function verification() { return $this->belongsTo(Verification::class); }
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}
