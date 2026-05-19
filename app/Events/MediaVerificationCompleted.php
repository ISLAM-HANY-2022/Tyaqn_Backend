<?php

namespace App\Events;

use App\Models\Verification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// استخدام ShouldBroadcastNow للبث الفوري دون الدخول في طوابير إضافية
class MediaVerificationCompleted implements ShouldBroadcastNow 
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $verification;

    public function __construct(Verification $verification)
    {
        $this->verification = $verification;
    }

    // تحديد اسم القناة التي سيستمع إليها فلاتر (قناة خاصة بكل مستخدم)
    public function broadcastOn(): array
    {
        return [
            new Channel('user.' . $this->verification->user_id),
        ];
    }

    // تحديد اسم الحدث
    public function broadcastAs(): string
    {
        return 'verification.completed';
    }

    // البيانات التي ستصل لفلاتر
    public function broadcastWith(): array
    {
        return [
            'id' => $this->verification->id,
            'status' => $this->verification->result_status,
            'description' => $this->verification->description_result,
        ];
    }
}