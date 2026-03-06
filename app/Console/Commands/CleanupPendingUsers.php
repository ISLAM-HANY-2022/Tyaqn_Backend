<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Carbon\Carbon;

class CleanupPendingUsers extends Command
{
    // الاسم اللي بننادي بيه الأمر
    protected $signature = 'users:cleanup-pending';
    protected $description = 'Delete accounts that were not activated within 24 hours';

    public function handle()
    {
        // امسح أي يوزر الـ email_verified_at بتاعه null 
        // وتاريخ إنشائه أقدم من 24 ساعة
        $deletedCount = User::whereNull('email_verified_at')
            ->where('created_at', '<', Carbon::now()->subDay())
            ->delete();

        $this->info("Inactive accounts have been deleted successfully: $deletedCount");
    }
}