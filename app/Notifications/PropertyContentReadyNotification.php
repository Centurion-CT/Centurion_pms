<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Operator;
use App\Models\PropertyContentImport;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired when a super admin approves a property content import.
 *
 * Two channels:
 *   mail     — one-line "your stays page is set up, log in to review"
 *              email to the operator's registered address.
 *   database — in-app row that surfaces via the operator's bell
 *              dropdown + dashboard alert card (already wired to the
 *              existing OperatorAlerts pipeline via the notifications
 *              table).
 *
 * Deliberately thin — the operator's next login lands them on
 * /settings/property where they can review + edit whatever they want.
 */
class PropertyContentReadyNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly PropertyContentImport $import,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(Operator $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(Operator $notifiable): MailMessage
    {
        $propertyName = $this->import->property?->name ?? 'your property';
        $settingsUrl = url('/settings/property');

        return (new MailMessage)
            ->subject("Your Keverna stays page for {$propertyName} is ready")
            ->greeting('Hi '.($notifiable->name ?: 'there').',')
            ->line("We've set up the initial stays page for {$propertyName} based on the listings you shared.")
            ->line("It's live now — log in to review the details and make any edits before publishing.")
            ->action('Review your stays page', $settingsUrl)
            ->line("You can edit anything: description, amenities, photos. What we set up is a starting point.")
            ->salutation('The Keverna team');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(Operator $notifiable): array
    {
        $propertyName = $this->import->property?->name ?? 'your property';

        return [
            'tenant_id'   => $this->import->tenant_id,
            'event'       => 'stays_page_ready',
            'title'       => "Your stays page for {$propertyName} is ready to review.",
            'message'     => 'Keverna set up the initial content from your listings, log in to review and edit.',
            'property_id' => $this->import->property_id,
            'import_id'   => $this->import->id,
            'url'         => '/settings/property',
        ];
    }
}
