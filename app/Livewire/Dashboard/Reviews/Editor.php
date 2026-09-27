<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Reviews;

use App\Enums\ReviewChannel;
use App\Models\Review;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Operator-side create/edit form for guest reviews. Operators are
 * adding reviews manually (transcribing from emails, WhatsApp messages,
 * etc.) in v1; guest self-service review submission ships in v2.
 *
 * `room_id = null` means a property-wide review (shown on the landing
 * carousel, counts toward the property aggregate). Setting a room
 * scopes the review to that room (counts toward per-room rating + only
 * appears on that room's detail page).
 */
#[Layout('layouts.tenant-auth')]
class Editor extends Component
{
    use WithFileUploads;

    public ?int $reviewId = null;

    #[Validate('required|string|max:120')]
    public string $guest_name = '';

    #[Validate('nullable|string|max:80')]
    public string $guest_country = '';

    #[Validate('required|string|max:2000')]
    public string $body = '';

    #[Validate('required|integer|min:1|max:5')]
    public int $rating = 5;

    /** Origin platform for the review — Direct, Google, Booking, Airbnb, etc. */
    #[Validate('required|string')]
    public string $channel = 'direct';

    /**
     * Date the review is shown as published on the public page.
     * Defaults to today on new rows; operator can back-date when
     * transcribing an older review.
     */
    #[Validate('nullable|date')]
    public string $published_at = '';

    #[Validate('nullable|integer|min:1')]
    public ?int $room_id = null;

    public bool $is_displayed = true;

    /**
     * Fresh upload from the operator, temporary file that's persisted
     * on save. Cleared after save. Kept alongside the persisted
     * `existing_photo_path` so the form can render a preview of what's
     * already on the review row.
     */
    #[Validate('nullable|image|max:5120')] // 5MB
    public $photo = null;

    public ?string $existing_photo_path = null;

    /**
     * Operator reply visible under the review on the public listing +
     * property landing. Nullable, saved only when non-empty.
     */
    #[Validate('nullable|string|max:2000')]
    public string $response_body = '';

    /**
     * Snapshot fields for the "responded by / responded at" chrome on
     * the reply block. Not user-editable; populated on save + on load.
     */
    public ?string $responded_at_iso = null;
    public ?string $responded_by_name = null;

    public function mount(?int $reviewId = null): void
    {
        // Default published_at to today for new reviews; operator can
        // back-date when transcribing an older one.
        $this->published_at = Carbon::today()->toDateString();

        if ($reviewId !== null) {
            $review = Review::query()->with('respondedBy:id,name')->find($reviewId);
            if ($review === null) {
                throw new NotFoundHttpException;
            }
            $this->reviewId = $review->id;
            $this->guest_name = $review->guest_name;
            $this->guest_country = (string) ($review->guest_country ?? '');
            $this->body = $review->body;
            $this->rating = $review->rating;
            $this->channel = $review->channel->value;
            $this->published_at = $review->published_at?->toDateString()
                ?? $review->created_at?->toDateString()
                ?? Carbon::today()->toDateString();
            $this->room_id = $review->room_id;
            $this->is_displayed = $review->is_displayed;
            $this->existing_photo_path = $review->photo_path;
            $this->response_body = (string) $review->response_body;
            $this->responded_at_iso = $review->responded_at?->toIso8601String();
            $this->responded_by_name = $review->respondedBy?->name;
        }
    }

    public function clearPhoto(): void
    {
        $this->photo = null;
        $this->existing_photo_path = null;
    }

    public function save(): void
    {
        $this->validate();

        /** @var Tenant $tenant */
        $tenant = Tenant::current();
        $property = $tenant->property;
        if ($property === null) {
            $this->addError('body', __('Property is missing, finish onboarding first.'));

            return;
        }

        $existing = $this->reviewId !== null
            ? Review::query()->find($this->reviewId)
            : null;

        $trimmedResponse = trim($this->response_body);

        // Resolve the incoming channel string to the enum (Validate
        // uses in:... via a rule below; falling back to Direct if the
        // client posted an unknown value).
        $channelEnum = ReviewChannel::tryFrom($this->channel) ?? ReviewChannel::Direct;

        // Photo persistence:
        //   - Fresh upload → store on the MEDIA_DISK (dokwe in prod,
        //     public locally) under reviews/{tenant} and delete the
        //     previous file.
        //   - Cleared via clearPhoto() → remove any existing file.
        //   - Untouched → keep existing_photo_path unchanged.
        $disk = config('media-library.disk_name', 'public');
        $photoPath = $existing?->photo_path;
        if ($this->photo instanceof TemporaryUploadedFile) {
            $stored = $this->photo->store('reviews/'.$tenant->id, $disk);
            $photoPath = $stored ?: null;
            if ($existing?->photo_path && $existing->photo_path !== $photoPath) {
                @\Illuminate\Support\Facades\Storage::disk($disk)->delete($existing->photo_path);
            }
        } elseif ($this->existing_photo_path === null && $existing?->photo_path) {
            @\Illuminate\Support\Facades\Storage::disk($disk)->delete($existing->photo_path);
            $photoPath = null;
        }

        $attrs = [
            'tenant_id'     => $tenant->id,
            'property_id'   => $property->id,
            'room_id'       => $this->room_id,
            'guest_name'    => $this->guest_name,
            'guest_country' => trim($this->guest_country) !== '' ? trim($this->guest_country) : null,
            'body'          => $this->body,
            'rating'        => $this->rating,
            'channel'       => $channelEnum,
            'published_at'  => trim($this->published_at) !== ''
                ? Carbon::parse($this->published_at)
                : Carbon::now(),
            'photo_path'    => $photoPath,
            'is_displayed'  => $this->is_displayed,
        ];

        // Response block. Stamp responded_at + responded_by_operator_id
        // only when the response is being ADDED (new non-empty text) or
        // when the operator EDITS the response (body changed). Deletion
        // (empty on save) clears everything so the block disappears from
        // the public page. Idempotent: repeated saves without editing
        // the reply keep the original timestamp.
        if ($trimmedResponse === '') {
            $attrs['response_body'] = null;
            $attrs['responded_at'] = null;
            $attrs['responded_by_operator_id'] = null;
        } else {
            $previousResponse = $existing?->response_body;
            $isNewOrEdited = $previousResponse === null || $previousResponse === ''
                || trim((string) $previousResponse) !== $trimmedResponse;
            $attrs['response_body'] = $trimmedResponse;
            if ($isNewOrEdited) {
                $attrs['responded_at'] = Carbon::now();
                $attrs['responded_by_operator_id'] = Auth::guard('web')->id();
            }
        }

        if ($existing !== null) {
            $existing->forceFill($attrs)->save();
            $review = $existing;
        } else {
            $review = Review::query()->create($attrs);
            $this->reviewId = $review->id;
        }

        // Refresh the snapshot fields so the "responded X ago by Y"
        // chrome updates without a page reload.
        $review->loadMissing('respondedBy:id,name');
        $this->responded_at_iso = $review->responded_at?->toIso8601String();
        $this->responded_by_name = $review->respondedBy?->name;

        $this->dispatch('notify', title: 'Review saved', message: 'Your live landing page reflects the change.', kind: 'ok');

        // After creating a brand-new review, bounce back to the index
        // so the operator sees it in the list they came from. Edits
        // stay on the editor (they may keep adjusting).
        if ($existing === null) {
            $this->redirectRoute('tenant.reviews.index', navigate: true);
        }
    }

    public function render(): View
    {
        return view('livewire.dashboard.reviews.editor', [
            'rooms'     => Room::query()->orderByDisplayName()->get(),
            'channels'  => ReviewChannel::cases(),
            'countries' => \App\Services\Locale\Countries::all(),
        ]);
    }
}
