<?php

namespace App\Events;

use App\Models\Verification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MediaVerificationCompleted implements ShouldBroadcastNow 
{
    use Dispatchable, SerializesModels;

    public $verification;
    public $aiPercentage; // أضفنا الخاصية هنا
    public $realPercentage; // أضفنا الخاصية هنا

    public function __construct(Verification $verification, $aiPercentage, $realPercentage)
    {
        $this->verification = $verification;
        $this->aiPercentage = $aiPercentage;
        $this->realPercentage = $realPercentage;
    }

    public function broadcastOn(): array
    {
        return [new Channel('user.' . $this->verification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'verification.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'status' => true,
            'message' => 'Media analyzed successfully',
            'data' => [
                'id' => $this->verification->id,
                'result_status' => $this->verification->result_status,
                'description_result' => $this->verification->description_result,
                'ai_percentage' => $this->aiPercentage,      // النسبة اللي أنت حسبتها
                'real_percentage' => $this->realPercentage,  // النسبة اللي أنت حسبتها
                'updated_at' => $this->verification->updated_at,
            ]
        ];
    }
}