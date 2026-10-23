# Host app, phase 4: owner screens (contract)

Read first: DECISIONS.md, PHASE-1..3.md. Designs: design/host-app/a/06-today-owner, 22-financials, 23-analytics,
24-daily-summary, 25-occupancy, 26-payouts (PNGs + a_owner.py). All owner endpoints: 403 {error:"owner_only"} unless
capabilities.owner (manager, or finance.view). Numbers come ONLY from the services the web Finance / Reports / Summary /
Portfolio / Calendar heatmap pages use (FinanceSnapshot, ReportSnapshot, OperatorAnalytics, OccupancyCalculator,
CalendarHeatmap, RevenueForecast, SummaryWatchouts, Portfolio). If a figure needs new maths, add it to the shared service
(with a test) and use it on the web too only if the web shows that figure. Money in NGN integers.

## Scope
Every owner endpoint accepts `scope=branch|all` (default branch). `all` = every branch the operator can access in this
tenant (Portfolio rules). Responses echo {scope, branches_count}.

## Endpoints (/api/mobile/v1/host/owner/...)
- `GET owner/home?scope=` -> {data:{scope, revenue_week, revenue_week_change_pct|null, series_7d:[{date,amount}],
   collected_today, owed_total, owed_stays, occupancy_tonight_pct, rooms_sold_tonight, rooms_total, adr_week,
   next_payout:{amount, date, bank_label}|null, approvals_waiting,
   branches:[{id, name, initials, revenue_today, occupancy_pct, is_current}],
   for_you:[{kind: summary|approvals|unsold|overdue|review, title, subtitle, target}] }}
   (replaces the phase-1 owner block on Today when available; keep host/today.owner working)
- `GET owner/financials?period=today|week|month|year&scope=` -> {data:{period, from, to, label ("October 2025"),
   revenue, revenue_change_pct|null, billed, collected, outstanding, outstanding_stays, outstanding_companies, refunds,
   refunds_count, expenses, net,
   billed_vs_collected:[{label, billed, collected}],          // 4-12 buckets depending on period
   sources:[{key, label, amount, pct}],                        // rooms, pos (restaurant and bar), extras, overstay...
   methods:[{value, label, amount}],
   owed:[{kind: guest|company, name, subtitle, amount, booking_ref|null, corporate_id|null}] (top 5),
   pdf_url|null }}  // pdf_url = one-time handoff URL (same mechanism as web-handoff) to the web PDF/statement if one exists
- `GET owner/analytics?days=7|30|90|365&scope=` -> {data:{days, occupancy_pct, occupancy_change_pts, adr, adr_change,
   revpar, revpar_change_pct, occupancy_series:[{date, pct}], occupancy_last_year:[{date, pct}]|null,
   channels:[{key, label, pct, bookings}], room_types:[{name, adr, nights_sold, occupancy_pct}],
   avg_stay_nights, avg_stay_change, lead_time_days, returning_guests_pct, cancellation_pct, cancellations, bookings}}
- `GET owner/summary?date=YYYY-MM-DD&scope=` (default yesterday) -> {data:{date, headline, occupancy_pct, occupancy_change_pts,
   revenue, revenue_change_pct, adr, adr_change,
   guests:{checked_in, checked_out, no_shows, new_bookings, new_bookings_amount, cancellations},
   money:{collected, pos, refunds, expenses, owed},
   watchouts:[{kind, title, subtitle}],   // SummaryWatchouts (complaints, discounts, reviews...)
   team:[{name, role, stat}], share_text, pdf_url|null}}
   share_text = plain-text WhatsApp summary (no em-dashes).
- `GET owner/occupancy?month=YYYY-MM&scope=` -> {data:{month, label, tonight:{pct, sold, total, free},
   days:[{date, pct, is_today, is_future}], forecast_14:[{date, pct}], room_types_tonight:[{name, sold, total, pct}]}}
- `GET owner/payouts?scope=branch` -> {data:{configured:bool, reason|null, next:{amount, date, bank_label}|null,
   recent:[{id, amount, date, status: paid|scheduled|processing|failed, payments_count|null, fee|null}],
   month_total, month_fees, bank:{name, account_last4, account_name}|null, cached_at}}
   From Paystack's settlements API for the tenant's (or branch's) subaccount via app/Services/Paystack/PaystackClient,
   cached 15 min; if the tenant has no own subaccount (hasOwnPaystackPayouts false) -> configured:false with a plain reason.
   Network/API failure -> last cached value with a stale flag, else configured:true + error:"payouts_unavailable".

## App (mobile-host/)
- Today: owners use GET owner/home (scope chip "All branches" when properties_count > 1) for the owner header, branch list
  with revenue today + occupancy, next payout tile, For you list (tap -> target screen).
- More > Owner links open the new native screens instead of the web: Financials (22), Analytics (23), Daily summary (24),
  Occupancy (25), Payouts (26). Each has period/range segments per the PNGs, scope chip when multi-branch, pull to refresh,
  SWR cache per (endpoint, params), offline banner.
- Charts drawn natively (bars, line with last-year dashed line, stacked channel bar, heat calendar) matching the PNGs; no
  heavy chart dependency unless needed (CustomPainter is fine).
- Daily summary: date arrows (prev/next day, not future), Share on WhatsApp uses share_text (share_plus or wa.me intent),
  PDF button opens pdf_url when present (hidden otherwise).
- Occupancy: "Set a same-day deal" opens the web discounts page via POST host/web-handoff {next:"/dashboard/discounts"}.
- Copy: sentence case, no em-dashes.
