<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\NewsletterSubscription;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the newsletter list as a CSV. Honours the same
 * ?status= filter the admin index page uses so the admin can
 * "filter down + export what I see".
 */
class NewsletterExportController
{
    public function __invoke(Request $request): StreamedResponse
    {
        $status = (string) $request->query('status', 'active');
        $status = in_array($status, ['active', 'unsubscribed', 'bounced'], true) ? $status : '';

        $filename = 'keverna-newsletter-'.($status ?: 'all').'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($status) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'status', 'source', 'subscribed_at', 'unsubscribed_at']);

            $query = NewsletterSubscription::query()->orderBy('id', 'desc');
            if ($status !== '') {
                $query->where('status', $status);
            }

            $query->cursor()->each(function (NewsletterSubscription $s) use ($out): void {
                fputcsv($out, [
                    $s->email,
                    $s->status,
                    $s->source,
                    $s->subscribed_at?->toDateTimeString(),
                    $s->unsubscribed_at?->toDateTimeString(),
                ]);
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
