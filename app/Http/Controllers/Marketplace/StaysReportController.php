<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Models\Tenant;
use App\Mail\Marketplace\PropertyReportSubmitted;
use App\Models\Property;
use App\Multitenancy\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Guest-submitted "something's wrong with this listing" reports on
 * /stays/{slug}. XHR-only endpoint - the show page's report modal
 * POSTs FormData + reads the JSON payload on the way back to render
 * a toast + close the modal.
 *
 * v1 is email-only: report gets queued to Keverna support with the
 * reason + free-text + guest's email. No DB persistence yet - once
 * volume warrants a moderator queue we'll add a PropertyReport
 * model + a /admin/reports view; for now the inbox is the queue.
 */
final class StaysReportController
{
    public function __invoke(Request $request, string $slug): JsonResponse
    {
        // Only accept genuine XHR / fetch POSTs so a stray form
        // that bounces through here doesn't get a JSON response
        // it can't parse. The show page's script sets
        // X-Requested-With + Accept: application/json.
        if (! $request->expectsJson() && $request->header('X-Requested-With') !== 'XMLHttpRequest') {
            abort(406);
        }

        $property = Property::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('public_slug', $slug)
            ->where('tenant_id', Tenant::current()?->getKey()) // this business only (docs/MULTITENANCY.md)
            ->where('is_listed_publicly', true)
            ->first();
        if ($property === null) {
            throw new NotFoundHttpException();
        }

        try {
            $data = $request->validate([
                'reason'  => ['required', 'string', 'in:inaccurate,inappropriate,scam,duplicate,other'],
                'message' => ['required', 'string', 'min:6', 'max:2000'],
                'email'   => ['required', 'email', 'max:180'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->validator->errors()->first() ?: 'Please fill every field.',
            ], 422);
        }

        // Emails go to whatever address the platform configured as
        // "from" - support@keverna.com in prod, mail-log in local.
        // Falls back to hello@keverna.com when config has no default.
        $to = (string) (config('mail.from.address') ?: 'hello@keverna.com');

        Mail::to($to)->queue(new PropertyReportSubmitted(
            propertyName: (string) $property->name,
            propertySlug: (string) $property->public_slug,
            propertyUrl:  route('marketplace.stays.show', ['slug' => $property->public_slug]),
            reason:       (string) $data['reason'],
            message:      (string) $data['message'],
            reporterEmail: (string) $data['email'],
        ));

        Log::info('Stays: property report submitted', [
            'property_slug' => $property->public_slug,
            'reason'        => $data['reason'],
            'reporter'      => $data['email'],
        ]);

        return response()->json(['ok' => true]);
    }
}
