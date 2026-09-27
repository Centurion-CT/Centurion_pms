{{--
    Generic wrapper for operator-customisable templated emails.

    Receives `$subject` and `$body` already substituted by the
    {@see EmailTemplateRenderer}. The body is plain text with
    paragraph breaks; we preserve newlines via `nl2br` so the layout
    inside the markdown shell still feels structured.

    A portal-button shortcut renders only if `$portalUrl` is set -
    keeps the manage-booking CTA prominent in branded mail while
    operators can still strip it out by editing the body if they want
    nothing but their own copy.
--}}
<x-mail::message>
{!! nl2br(e($body)) !!}

@if (! empty($portalUrl))
<x-mail::button :url="$portalUrl">
Manage your booking
</x-mail::button>
@endif

</x-mail::message>
