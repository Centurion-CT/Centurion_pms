<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sendpipe\SendpipeClient;
use App\Services\Sendpipe\SendpipeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * End-to-end smoke test for the Sendpipe communications transport.
 *
 *   php artisan sendpipe:ping you@example.com
 *   php artisan sendpipe:ping you@example.com --via=mail
 *
 * Two paths to test:
 *   --via=client (default) sends through SendpipeClient directly — the
 *     same path WhatsApp OTPs use. Confirms the API key + base URL
 *     reach Sendpipe.
 *   --via=mail sends through Laravel's Mail facade using the
 *     `sendpipe` transport registered in AppServiceProvider. Confirms
 *     the full Mailable → SendpipeTransport → Sendpipe API chain that
 *     every queued mail in the app uses.
 *
 * Either way the recipient gets a short plain-text test email; either
 * path failing surfaces the underlying error message in-line.
 */
class SendpipePingCommand extends Command
{
    /** @var string */
    protected $signature = 'sendpipe:ping
                            {to : Recipient email address}
                            {--via=client : Which path to exercise — `client` or `mail`}';

    /** @var string */
    protected $description = 'Smoke-test Sendpipe email delivery';

    public function handle(SendpipeClient $client): int
    {
        $to = (string) $this->argument('to');
        $via = (string) $this->option('via');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("'{$to}' is not a valid email address.");

            return self::FAILURE;
        }

        $this->line('');
        $this->info('Sendpipe config');
        $this->table(['Setting', 'Value'], [
            ['api_url',      config('services.sendpipe.api_url') ?? '(unset)'],
            ['api_key',      $this->mask((string) (config('services.sendpipe.api_key') ?? ''))],
            ['default_from', config('services.sendpipe.default_from') ?? '(unset)'],
            ['MAIL_MAILER',  config('mail.default')],
        ]);

        if (empty(config('services.sendpipe.api_key'))) {
            $this->error('SENDPIPE_API_KEY is blank — set it in .env then `php artisan config:clear`.');

            return self::FAILURE;
        }

        $subject = 'Keverna · sendpipe:ping · '.now()->format('Y-m-d H:i:s');
        $bodyText = "Hi,\n\nThis is a smoke-test message from Keverna's `sendpipe:ping` command.\n"
            ."If you're reading this in your inbox, Sendpipe email delivery is wired up correctly.\n\n"
            .'— Keverna platform';
        $bodyHtml = '<p>Hi,</p>'
            .'<p>This is a smoke-test message from Keverna&rsquo;s <code>sendpipe:ping</code> command.</p>'
            .'<p>If you&rsquo;re reading this in your inbox, Sendpipe email delivery is wired up correctly.</p>'
            .'<p>&mdash; Keverna platform</p>';

        $this->line('');
        $this->line('→ Sending via '.$via.' to '.$to);

        try {
            if ($via === 'mail') {
                Mail::mailer('sendpipe')->raw($bodyText, function ($message) use ($to, $subject): void {
                    $message->to($to)->subject($subject);
                });
                $this->info('  ✓ Mail::mailer(sendpipe) returned without error');
            } elseif ($via === 'client') {
                $response = $client->sendEmail([
                    'to' => [$to],
                    'subject' => $subject,
                    'text' => $bodyText,
                    'html' => $bodyHtml,
                    'tags' => ['sendpipe-ping'],
                ]);
                $this->info('  ✓ Sendpipe API accepted the request');
                $this->line('    response: '.json_encode($response, JSON_UNESCAPED_SLASHES));
            } else {
                $this->error("Unknown --via='{$via}'. Use 'client' or 'mail'.");

                return self::FAILURE;
            }
        } catch (SendpipeException $e) {
            $this->error('  ✗ Sendpipe rejected the request');
            $this->line('    status: '.$e->statusCode);
            $this->line('    code: '.$e->errorCode);
            $this->line('    message: '.$e->getMessage());
            if ($e->details !== []) {
                $this->line('    details: '.json_encode($e->details));
            }

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('  ✗ Send failed: '.$e->getMessage());
            $this->line('    type: '.$e::class);

            return self::FAILURE;
        }

        $this->line('');
        $this->info('Email handed off to Sendpipe. Check '.$to.' (and the Sendpipe dashboard for delivery status).');

        return self::SUCCESS;
    }

    private function mask(string $value, int $tail = 6): string
    {
        if ($value === '') {
            return '(unset)';
        }
        if (strlen($value) <= $tail) {
            return str_repeat('•', strlen($value));
        }

        return str_repeat('•', max(0, strlen($value) - $tail)).substr($value, -$tail);
    }
}
