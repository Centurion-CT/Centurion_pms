<?php

declare(strict_types=1);

namespace App\Mail\Onboarding;

use App\Models\Operator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailOtpMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Operator $operator,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('app.name').' verification code');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.onboarding.email-otp',
            with: [
                'operator' => $this->operator,
                'code' => $this->code,
            ],
        );
    }
}
