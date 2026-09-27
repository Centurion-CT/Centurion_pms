@component('mail::message')
# New listing report on Keverna Stays

A guest reported a Stays listing.

**Property:** {{ $propertyName }} ([open on marketplace]({{ $propertyUrl }}))<br>
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
Keverna Stays
@endcomponent
