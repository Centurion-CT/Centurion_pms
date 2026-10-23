# Host app: product decisions (2026-10-22)

- New Flutter app in `mobile-host/` (Keverna for Hosts, com.keverna.host). `mobile/` stays as the staff app and will be
  rebuilt for housekeepers and porters only.
- Design: direction A (design/host-app/a). Owner = manager or `finance.view`.
- Payments follow the web's options exactly: methods Cash, Bank transfer, Card, POS terminal, Other, plus Wallet when the
  guest has a balance (no host-side Paystack link; `paystack` only as a refund method). New-booking statuses: Paid in full,
  Deposit only, Not paid yet, Pay from wallet, Bill to company.
- One shared server payment layer used by web AND app; fix the web money bugs found in the survey (extend stay running-total,
  partial wallet payments not reducing what's owed, mobile payment endpoint semantics, reference_note length).
- Inbox approvals (phase 3) = existing items only: guest-portal held actions (GuestActionApproval), held money on
  cancelled/no-show stays (refund or keep as forfeit), corporate requests. A new staff-to-manager approval workflow is later.
- Payouts (phase 4) from Paystack's settlements API per property subaccount, cached.
