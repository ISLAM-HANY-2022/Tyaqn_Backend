<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    // تعريف المتغير ليتم استخدامه في الـ Blade
    public $code;

    public function __construct($code)
    {
        $this->code = $code;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject:'Password Reset Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reset_password', // تأكد أن الملف موجود في هذا المسار
        );
    }
}