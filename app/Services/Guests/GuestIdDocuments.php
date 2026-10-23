<?php

declare(strict_types=1);

namespace App\Services\Guests;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\GuestIdDocument;
use App\Models\Operator;
use finfo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Guest ID documents seen at check-in (Keverna Desk phase 2, NDPA).
 *
 * What is kept: type, number (encrypted), nationality, date of birth
 * (encrypted), expiry and an optional scan. The scan goes to the media
 * disk the property photos use, under a random path, and its bytes are
 * encrypted with the app key before upload: that disk is a public CDN
 * zone in production, so the file is unreadable even if the path leaks.
 * The API streams it back to host roles only.
 *
 * Retention: `retain_until` = check-out + keverna.guest_id_retention_months.
 * Set from the planned check-out at capture, moved to the real one at
 * check-out. {@see purge()} wipes everything but type + "seen" after it.
 */
class GuestIdDocuments
{
    public const MAX_IMAGE_BYTES = 3 * 1024 * 1024;

    private const IMAGE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * Validation rules for an `id_document` object under `$prefix`.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = 'id_document'): array
    {
        $nested = $prefix !== '';
        $p = $nested ? $prefix.'.' : '';
        $required = $nested ? 'required_with:'.$prefix : 'required';

        $rules = [
            $p.'type' => [$required, Rule::in(array_keys(GuestIdDocument::TYPES))],
            $p.'number' => [$required, 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9\- \/]+$/'],
            $p.'nationality' => ['nullable', 'string', 'max:80'],
            $p.'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            $p.'expires_on' => ['nullable', 'date_format:Y-m-d'],
            $p.'image_base64' => ['nullable', 'string', 'max:'.(int) ceil(self::MAX_IMAGE_BYTES * 4 / 3 + 64)],
        ];

        return $nested ? [$prefix => ['nullable', 'array']] + $rules : $rules;
    }

    public static function retentionMonths(): int
    {
        return max(1, (int) config('keverna.guest_id_retention_months', 12));
    }

    public static function retainUntilFor(Carbon $checkout): Carbon
    {
        return $checkout->copy()->startOfDay()->addMonthsNoOverflow(self::retentionMonths());
    }

    /**
     * Save the ID seen for this stay. A second capture for the same
     * booking replaces the first (and its scan).
     *
     * @param  array{type: string, number: string, nationality?: ?string, date_of_birth?: ?string, expires_on?: ?string, image_base64?: ?string}  $data
     *
     * @throws ValidationException when the scan is not a JPEG/PNG/WebP under 3 MB
     */
    public function record(Booking $booking, Operator $operator, array $data, string $imageField = 'id_document.image_base64'): GuestIdDocument
    {
        $image = null;
        if (! empty($data['image_base64'])) {
            $image = $this->decodeImage((string) $data['image_base64'], $imageField);
        }

        $existing = GuestIdDocument::query()->where('booking_id', $booking->id)->whereNull('purged_at')->get();
        foreach ($existing as $old) {
            $this->deleteImage($old);
            $old->delete();
        }

        $number = trim((string) $data['number']);
        $doc = new GuestIdDocument([
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'type' => (string) $data['type'],
            'number' => $number,
            'nationality' => self::nullIfBlank($data['nationality'] ?? null),
            'date_of_birth' => self::nullIfBlank($data['date_of_birth'] ?? null),
            'expires_on' => self::nullIfBlank($data['expires_on'] ?? null),
            'captured_by_operator_id' => $operator->id,
            'retain_until' => self::retainUntilFor($booking->checkout_date)->toDateString(),
        ]);

        if ($image !== null) {
            [$bytes, $ext] = $image;
            $disk = (string) config('media-library.disk_name', 'public');
            $path = 'guest-ids/'.$booking->tenant_id.'/'.Str::random(40).'.'.$ext.'.enc';
            Storage::disk($disk)->put($path, Crypt::encryptString(base64_encode($bytes)));
            $doc->image_disk = $disk;
            $doc->image_path = $path;
        }

        $doc->save();

        activity('booking')
            ->causedBy($operator)
            ->performedOn($booking)
            ->event('id_document_recorded')
            ->withProperties(['type' => $doc->type, 'with_scan' => $image !== null])
            ->log('ID recorded: '.$doc->typeLabel().'.');

        return $doc;
    }

    /**
     * Check a scan before anything else happens (so a bad image never
     * leaves a half-done check-in behind).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function assertImage(array $data, string $field = 'id_document.image_base64'): void
    {
        if (! empty($data['image_base64'])) {
            $this->decodeImage((string) $data['image_base64'], $field);
        }
    }

    /** Move retention to the real check-out. */
    public function stampCheckout(Booking $booking, ?Carbon $at = null): void
    {
        GuestIdDocument::query()
            ->where('booking_id', $booking->id)
            ->whereNull('purged_at')
            ->update(['retain_until' => self::retainUntilFor($at ?? Carbon::today())->toDateString()]);
    }

    /**
     * The ID for this stay, else the guest's latest one still held
     * from an earlier stay (flagged `from_previous_stay`).
     */
    public function forBooking(Booking $booking): ?GuestIdDocument
    {
        $own = GuestIdDocument::query()->where('booking_id', $booking->id)->latest('id')->first();
        if ($own !== null || $booking->customer_id === null) {
            return $own;
        }

        return GuestIdDocument::query()
            ->where('customer_id', $booking->customer_id)
            ->whereNull('purged_at')
            ->latest('id')
            ->first();
    }

    /**
     * Full shape for host roles (booking detail). `number` is the full
     * number; lists and prints use `number_masked`.
     *
     * @return array<string, mixed>
     */
    public static function present(GuestIdDocument $doc, Booking $booking): array
    {
        $purged = $doc->isPurged();

        return [
            'id' => (int) $doc->id,
            'type' => $doc->type,
            'type_label' => $doc->typeLabel(),
            'number' => $purged ? null : $doc->number,
            'number_masked' => $doc->maskedNumber(),
            'nationality' => $purged ? null : $doc->nationality,
            'date_of_birth' => $purged ? null : $doc->date_of_birth,
            'expires_on' => $purged ? null : $doc->expires_on?->toDateString(),
            'expired' => ! $purged && $doc->expires_on !== null && $doc->expires_on->lt(Carbon::today()),
            'has_image' => ! $purged && $doc->image_path !== null,
            'captured_at' => $doc->created_at?->toIso8601String(),
            'captured_by' => $doc->capturedBy?->name,
            'retain_until' => $doc->retain_until->toDateString(),
            'purged' => $purged,
            'from_previous_stay' => (int) $doc->booking_id !== (int) $booking->id,
        ];
    }

    /**
     * Decrypted scan bytes + mime, or null.
     *
     * @return array{0: string, 1: string}|null
     */
    public function readImage(GuestIdDocument $doc): ?array
    {
        if ($doc->isPurged() || $doc->image_path === null) {
            return null;
        }
        try {
            $raw = Storage::disk((string) ($doc->image_disk ?: config('media-library.disk_name', 'public')))->get($doc->image_path);
            if (! is_string($raw) || $raw === '') {
                return null;
            }
            $bytes = base64_decode(Crypt::decryptString($raw), true);
        } catch (Throwable) {
            return null;
        }
        if ($bytes === false) {
            return null;
        }

        return [$bytes, (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream'];
    }

    /**
     * Wipe ID data whose retention has passed: number, nationality, date
     * of birth, expiry and the scan go; the row stays with its type and
     * `purged_at` as the record that an ID was seen. Stays still in
     * house are skipped. Runs inside the current tenant scope.
     */
    public function purge(?Carbon $today = null): int
    {
        $today = ($today ?? Carbon::today())->toDateString();
        $count = 0;

        GuestIdDocument::query()
            ->whereNull('purged_at')
            ->whereDate('retain_until', '<', $today)
            ->whereDoesntHave('booking', fn ($q) => $q->where('booking_status', BookingStatus::CheckedIn->value))
            ->orderBy('id')
            ->chunkById(200, function ($docs) use (&$count): void {
                foreach ($docs as $doc) {
                    /** @var GuestIdDocument $doc */
                    $this->deleteImage($doc);
                    $doc->forceFill([
                        'number' => null,
                        'nationality' => null,
                        'date_of_birth' => null,
                        'expires_on' => null,
                        'image_disk' => null,
                        'image_path' => null,
                        'purged_at' => Carbon::now(),
                    ])->save();
                    $count++;
                }
            });

        return $count;
    }

    private function deleteImage(GuestIdDocument $doc): void
    {
        if ($doc->image_path === null) {
            return;
        }
        try {
            Storage::disk((string) ($doc->image_disk ?: config('media-library.disk_name', 'public')))->delete($doc->image_path);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array{0: string, 1: string} bytes + extension
     */
    private function decodeImage(string $b64, string $field = 'id_document.image_base64'): array
    {
        if (str_starts_with($b64, 'data:')) {
            $b64 = (string) substr($b64, (int) strpos($b64, ',') + 1);
        }
        $bytes = base64_decode($b64, true);
        $mime = $bytes !== false && $bytes !== '' ? (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) : null;
        if ($bytes === false || $bytes === '' || ! isset(self::IMAGE_MIMES[$mime])) {
            throw ValidationException::withMessages([$field => 'The ID scan must be a JPEG, PNG or WebP image.']);
        }
        if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw ValidationException::withMessages([$field => 'The ID scan must be 3 MB or smaller.']);
        }

        return [$bytes, self::IMAGE_MIMES[$mime]];
    }

    private static function nullIfBlank(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }
}
