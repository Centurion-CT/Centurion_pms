# Host app, phase 2: front desk + shared payment layer (contract)

Read first: docs/host-app/DECISIONS.md, PHASE-1.md (auth, host group, branch binding, errors, idempotency).
Designs: design/host-app/a/07..16 (+ Today quick actions on 05).

## A. Server: one payment layer for web AND app (fix the money bugs)
Create `app/Services/Payments/BookingPayments.php` (or a small set of actions under app/Actions/Payments) that BOTH the web
Livewire flows and the host API call. It wraps the existing actions; it does not invent new money rules.

Canonical methods (single source, e.g. `App\Enums\PaymentMethod` or a config list with labels):
`cash` Cash, `transfer` Bank transfer, `card` Card, `pos` POS terminal, `other` Other. Plus `wallet` Wallet only when the
guest's customer wallet balance > 0. (`paystack` exists only as a REFUND method; never for taking money.)

Operations:
1. `take(Booking, Operator, int amount, string method, ?note)`: amount < outstanding -> RecordPartPayment; amount >= outstanding
   -> settle everything wallet-first (SettleChargesWalletFirst with method). method=wallet -> spend wallet up to amount,
   reducing balance_due then charges (fix: partial wallet payments must reduce what is owed). Returns the new outstanding.
2. `confirmAndPay(Booking, Operator, int amount, string method, ?note)`: for Pending bookings (port Reservations\Show::confirmAndPay):
   status Confirmed (OnHold if partial) + record payment + fix balance_due.
3. `collectForExtension(Booking, Operator, mode full|extension|wallet|none, ?method)`: replaces the web extend tail
   (fix: RecordManualPayment running-total bug). Use (1) for the amount.
4. `settleOnCreate(Booking, Operator, status confirmed|on_hold|pending|wallet|corp_invoice, ?amount, ?method)`: port of
   HasQuickReserveForm::submitReserve post-create logic so web quick reserve, manual create, calendar sheet and mobile all share it.
   Calendar sheet stops silently recording `cash`: it gets the method picker value (default cash).
All: reference_note trimmed to 120 chars; DashboardSnapshot::forget(); activity log as today.
Switch the web callers to this layer: Reservations\Show (settle all, part pay, confirm&pay), Home outstanding panel (now wallet-first
like Show), HasExtendStayModal collect, HasQuickReserveForm, Bookings\ManualCreate, Calendar\PropertyView sheet.
Regression tests for each bug: extend after the stay is already paid records the extension payment and settles the Extension
charge; partial wallet payment lowers outstanding; second mobile payment records fully; pending booking confirmed by payment;
long reference note doesn't crash; Home settle drains wallet first.
Fix the existing staff-app endpoint POST reservations/{ref}/payment to call `take()` (same response shape).

## B. Host API (all under /api/mobile/v1/host/, branch-scoped, idempotent writes)
Common booking card `BookingCard`: {ref, guest_name, guest_phone, room_number|null, category, checkin, checkout, nights,
  status, status_label, balance_due, total_amount, chip:{label,tone}, overdue:bool, corporate:{name}|null, needs_room:bool}

- `GET host/bookings?segment=arriving|in_house|leaving|upcoming&q=` -> {data:[BookingCard], counts:{arriving,in_house,leaving,upcoming}}
  q matches guest name, ref, phone (digits), email, room number. upcoming = next 14 days. Overdue in-house first.
- `GET host/bookings/{ref}` -> {data: BookingCard + {
    adults, children, special_requests, source, source_label, guest_email,
    customer:{id,name,is_vip,stays_count,wallet_balance}|null,
    room_ready: {state: ready|dirty|inspecting|out_of_order|null, label},
    charges:[{id,type,type_label,description,amount,settled,settled_method,created_at}],
    payments:[{id,amount,method,method_label,type,status,paid_at,reference_note}],
    paid_total, outstanding,
    can:{check_in, check_out, take_payment, add_charge, extend, no_show, cancel},
    payment_options:{methods:[{value,label}], wallet_available:int}   // wallet only listed when > 0
  }}
- `POST host/bookings/{ref}/payments` {amount, method, reference_note?} -> {status:"ok", booking:<detail>}
  (uses take(); 422 {error:"payment_failed", detail})
- `POST host/bookings/{ref}/check-in` {payment?:{amount, method, reference_note?}} -> {status, booking}
  Pending booking + payment -> confirmAndPay then check in. Pending without payment -> 422 {error:"payment_required", detail}.
  Room not ready -> 422 {error:"room_not_ready", detail} (same rule as web MarkCheckedIn / housekeeping guard).
- `POST host/bookings/{ref}/check-out` {early_mode?: keep|wallet|cash} -> {status, booking}; outstanding > 0 (non-corporate)
  -> 422 {error:"balance_outstanding", detail, outstanding}.
- `POST host/bookings/{ref}/charges` {type, amount, description?, occupant_id?, waive?:bool} -> {status, booking}
  types = BookingChargeType minus Extension and DepositForfeit; `GET host/meta` lists them with labels.
- `GET host/bookings/{ref}/extend-quote?checkout=YYYY-MM-DD` -> {data:{available:bool, max_checkout, extra_nights,
  extension_amount, new_total, outstanding_after, reason?}}
- `POST host/bookings/{ref}/extend` {checkout, collect: full|extension|wallet|none, method?} -> {status, booking}
- `POST host/bookings/{ref}/no-show` {forfeit_deposit:bool}, `POST host/bookings/{ref}/cancel` {refund_requested:bool}
- `GET host/rooms/available?checkin&checkout&adults&children` -> {data:[{room_id, room_number, category, nightly_rate,
  total, breakfast_available, breakfast_rate}]}
- `GET host/customers/lookup?phone=` -> {data: null | {id, name, email, phone, stays_count, is_vip, wallet_balance,
  corporate:{id,name}|null}}
- `POST host/bookings` {room_id, checkin, checkout, guest_name, guest_phone, guest_email?, adults, children, breakfast?,
  special_requests?, status: confirmed|on_hold|pending|wallet|corp_invoice, amount?, method?, customer_id?}
  -> {status, booking:<detail>}. guest_email optional. Uses CreateBooking + settleOnCreate. on_hold needs amount >= min deposit
  (422 {error:"deposit_too_low", detail, min_deposit}); wallet needs a customer with balance; corp_invoice needs a corporate member.
- `GET host/meta` -> {data:{payment_methods:[{value,label}], charge_types:[{value,label}], booking_statuses:[{value,label,hint}],
  early_checkout_modes:[...]}} (labels the app shows; single source).
- `POST host/web-handoff` {next:"/dashboard/finance"} -> {url} : a ONE-TIME code (60s, single use) URL that signs the operator
  into the web dashboard WITHOUT the desktop-shell mode (normal sidebar). Replace the app's current token-in-URL approach.

## C. App screens (mobile-host/, match the PNGs)
07 Bookings tab (search, 4 segments with counts, overdue banner, rows, FAB new booking) · 08/09 booking detail (arriving and
in-house variants from the same screen; call/WhatsApp via url_launcher; actions from `can`) · 10 check-in (confirm details,
room ready state, payment step when Pending or balance due) · 11 take payment sheet (amount prefilled with outstanding,
methods from payment_options, reference) · 12 add charge sheet · 13 extend stay sheet (quote as you change the date, collect
chips Full / Extension only / Wallet (only if available) / Don't collect now) · 14 check-out (folio, settle first, early mode
when the server asks) · 15/16 new booking (phone lookup first, dates, rooms, then guest + payment status choice cards from
meta.booking_statuses) · Today quick actions wired (Book -> 15, Check in/Check out -> Bookings segment, Charge -> pick an
in-house guest then 12). All writes go through the outbox (queued offline, idempotent). ID scan (design 10) is deferred: show
nothing for it in phase 2. Copy: sentence case, no em-dashes.
