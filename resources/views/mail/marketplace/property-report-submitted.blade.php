@component('mail::message')
# A guest reported a problem with your page

Someone used "Report" on the {{ $propertyName }} page of your booking site.

**Property:** {{ $propertyName }} ([open the page]({{ $propertyUrl }}))<br>
**Slug:** `{{ $propertySlug }}`<br>
**Reason:** {{ ucfirst($reason) }}<br>
**Reporter email:** {{ $reporterEmail }}

---

**Message from guest:**

> {{ $message }}

---

Reply-to on this email is the reporter, so hitting reply talks
to them directly.

Thanks,<br>
{{ config('brand.name') }}
@endcomponent
