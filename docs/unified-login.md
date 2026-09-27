# Unified Login + Guest Dashboard — Spec

**To:** FregateLab Eng
**From:** Ifeanyi Amadi
**Date:** 2026-07-05
**Re:** Central login (keverna.com/login) routing operators to their tenant dashboard and guests to a cross-tenant reservation dashboard

---

## Executive Summary

Introduce a central `identity_accounts` table on the marketing domain that owns credentials and role linkage. A single login at `keverna.com/login` accepts email + password, resolves the identity's role, and either (a) SSO's operators into their tenant subdomain or (b) serves guests a central dashboard aggregating reservations across every tenant they've booked with. `Operator` and `Customer` stay per-tenant; identity accounts sit above them and hold the login credential.

## 1. Situation

Keverna has three parallel auth surfaces today:

- **Operator** — logs in on their tenant subdomain (`their-name.keverna.com/login`). Model: `App\Models\Operator`, `Authenticatable`, uses `BelongsToTenant`.
- **Admin** — separate staff portal. Model: `App\Models\Admin`.
- **Corporate member** — `/portal/corporate/auth/login`. Model: `App\Models\CorporateMember` under `App\Models\Corporate`.

Guests have no credentials at all. `App\Models\Customer` is a per-tenant CRM row created at booking time — a guest who has booked at three different Keverna properties has three disjoint `Customer` rows and no way to see them together.

The central marketing domain has no `login` route. Users landing on `keverna.com/register` who already have an account are shown a fallback string telling them to "sign in at your property URL," which they usually can't recall.

## 2. Complication

Four constraints make this non-trivial:

- **Email is not globally unique today.** Two `Operator` rows on different tenants can share an email (`hello@sameagency.com`). A central login must resolve which tenant to route to, and the backfill must reconcile collisions.
- **Customers have no password.** Introducing credentials means either forcing existing guests through a claim/verify step, or issuing passwordless magic links.
- **`BelongsToTenant` is a global scope.** Any cross-tenant read (the guest's reservation list) must intentionally bypass or reshape it without leaking data.
- **Corporate has its own portal already.** Folding it into unified login is possible but is Phase-3 scope; Phase 1 must not break it.

## 3. The Story

We considered three shapes:

1. **Do nothing.** Keep tenant-URL sign-in and shorten the register-page hint. Rejected: it does not enable the guest dashboard the memo pricing story implicitly assumes (multi-property operators need multi-property guests; guests need a place to see them all).
2. **Merge everything into one `users` table.** Simpler on paper — one identity, one password, one row. Rejected: `Operator` and `Customer` are already deeply integrated with `BelongsToTenant` scopes, per-tenant CRM enrichment (`total_bookings`, `lifetime_value`), tenant-scoped invitations, and per-tenant role enums. Centralising these blows up every read path and destroys per-tenant CRM ownership.
3. **Federated identity with per-role linkage.** A thin central `identity_accounts` table owns the credential and the routing rules. `Operator`, `Customer`, `CorporateMember` stay per-tenant and unchanged. Chosen — smallest blast radius, keeps CRM per-tenant, cleanest phasing.

### Identity model (proposed)

```
identity_accounts
  id                         bigint pk
  email                      string, unique, citext-normalised
  password                   string, nullable  -- nullable for magic-link-only guests
  email_verified_at          timestamp, nullable
  name                       string
  phone                      string, nullable
  last_login_at              timestamp, nullable
  primary_role               enum('operator','guest','both')  -- computed at link creation
  remember_token, timestamps

identity_operator_links
  id                         bigint pk
  identity_account_id        fk → identity_accounts
  tenant_id                  fk → tenants
  operator_id                fk → operators (tenant-scoped)
  role                       enum (mirrors OperatorRole)
  unique (identity_account_id, tenant_id)
  unique (tenant_id, operator_id)

identity_guest_links
  id                         bigint pk
  identity_account_id        fk → identity_accounts
  tenant_id                  fk → tenants
  customer_id                fk → customers (tenant-scoped)
  claimed_at                 timestamp -- when the guest verified this link
  unique (tenant_id, customer_id)
```

An identity can carry both operator_links and guest_links (an operator can also be a guest at another property). `primary_role` is a denormalised routing hint — the source of truth is the link tables.

### Routing rules on successful login

| Links found                                       | Where we send them                                                    |
| ------------------------------------------------- | --------------------------------------------------------------------- |
| exactly 1 operator_link, 0 guest_links            | SSO handoff directly to `{tenant}.keverna.com/dashboard`              |
| 0 operator_links, ≥1 guest_link                   | Central guest dashboard at `keverna.com/reservations`                 |
| Any other combination (multi-tenant or dual-role) | Account chooser at `keverna.com/choose` — see below                   |
| 0 links                                           | Verified identity with no linkage — offer to register a property      |

### Account chooser (`keverna.com/choose`)

The chooser is a single screen that lists every place this identity can go, as a flat set of cards. Both "operator at multiple tenants" and "operator here + guest there" collapse into the same UI — the user does not distinguish between "roles" and "tenants," they just pick where to continue.

- One card per `identity_operator_link`, showing tenant name, operator role, and the tenant logo/booking-page thumbnail. Selecting it triggers the SSO handoff to that subdomain.
- If any `identity_guest_links` exist, one additional card labelled "My reservations" (with the count of properties booked). Selecting it opens the central guest dashboard.
- Cards are ordered by `last_login_at` on the link, so the account the user opened most recently is first.
- Chooser skipped entirely when the routing rule above resolves without ambiguity (single operator, or guest-only).
- Remembering last choice: an optional "Take me straight here next time" checkbox on each card writes a `preferred_link_id` back to the identity. Next login skips the chooser and goes straight to that link. Any subsequent visit to `keverna.com/choose` (linkable from every dashboard header) clears the preference.

### SSO handoff

Central login sets a session cookie on `.keverna.com` (parent domain). For operator routing we do NOT rely on the parent cookie alone — the tenant subdomain gets its own short-lived signed token in the URL (`?_sso=<jwt>`), which the tenant middleware exchanges for a subdomain-scoped session and then strips from the URL. This keeps subdomain session isolation intact (an operator on tenant A cannot ride the parent cookie into tenant B's dashboard without a fresh SSO handoff).

The guest dashboard reads directly from the parent cookie — no per-tenant handoff needed because the dashboard is central.

### Backfill

**Operators (one-shot at Phase 1 cutover):**

1. For every `Operator` row, `upsert` an `identity_accounts` row keyed on normalised email. Password copied verbatim (already Bcrypt).
2. Create the matching `identity_operator_links` row.
3. Email collisions across tenants (two Operators sharing an email) get written to `identity_backfill_conflicts` and are **not** auto-linked; ops resolves each manually before flip. Expected volume: < 20 based on current operator count.

**Guests (lazy, Phase 2):**

1. On the next confirmed booking after Phase-2 flip, we email the guest a magic link. The link creates an `identity_accounts` row (email pre-verified via the click) and calls a merge routine that scans **all** tenants for `Customer` rows sharing the verified email and creates `identity_guest_links` for each.
2. Guests who never re-book do not get an account — this is fine; they still receive booking confirmations by email as they do today.
3. There is no password-set step in Phase 2; guest auth is magic-link only. Password support is Phase 2.5 if data shows demand.

### Guest dashboard scope (v1)

Reads across every linked tenant via a dedicated query layer that whitelists `identity_guest_links` before dropping into per-tenant scope.

Includes:

- Reservation list (upcoming, past) with property name, dates, status, amount paid
- Payment history (receipts, refunds)
- "Request change" / "Request cancellation" — routes through the existing tenant-side change-request pipeline; the guest does not act on inventory directly
- Update contact info **per tenant** — updating the guest's phone on Tenant A does not silently rewrite Tenant B's CRM row. Guest sees a per-tenant list of contact rows and can push a shared update to all if they want.

Explicitly excluded from v1:

- Cross-tenant loyalty / points
- Cross-tenant messaging inbox
- Payment method vaulting across tenants (Paystack tokens are tenant-scoped)

## 4. Recommendation

Ship in three phases. Each phase is independently useful and independently reversible.

- **Phase 1 — Operator federated login.** `identity_accounts` + `identity_operator_links`, backfill operators, central `keverna.com/login` with SSO handoff, tenant picker for multi-tenant operators. Kills the "sign in at your property URL" copy. **Ship in Q3.**
- **Phase 2 — Guest dashboard.** `identity_guest_links`, magic-link claim on next booking, `keverna.com/reservations` central page. **Ship in Q4.**
- **Phase 3 — Fold corporate into unified login (optional).** Only if identity model proves out with no data-leak incidents in Phase 1 + 2. **Revisit end of Q4.**

Do **not** attempt Phase 1 and Phase 2 together — the operator backfill and the guest magic-link infra are independent concerns and mixing them doubles the surface at cutover.

## 5. Next Actions

| Action                                                                                       | Owner    | Target Date |
| -------------------------------------------------------------------------------------------- | -------- | ----------- |
| Design account-chooser screen (card layout, ordering, "remember choice" affordance)          | Ifeanyi  | 2026-07-25  |
| Audit current `Operator` table for cross-tenant email collisions; report count               | Eng team | 2026-08-01  |
| Design SSO handoff token format (signed JWT vs. Laravel signed URL); document trade-off      | Eng team | 2026-08-08  |
| Ship `identity_accounts` + `identity_operator_links` migrations + backfill command (Phase 1) | Eng team | 2026-08-22  |
| Ship central `/login` + tenant picker + SSO handoff (Phase 1)                                | Eng team | 2026-09-05  |
| Flip register-page fallback to link to `keverna.com/login` when Phase 1 ships                | Eng team | 2026-09-05  |
| Draft magic-link claim UX + merge preview screen (Phase 2 design)                            | Ifeanyi  | 2026-09-19  |
| Ship guest dashboard v1 (Phase 2)                                                            | Eng team | 2026-10-31  |

## Open questions

- **Password reset on identity.** Does reset invalidate all linked operator/guest sessions, or only the parent? Recommend: invalidate parent + all subdomain sessions on password change; tenant middleware already supports session invalidation broadcasts.
- **2FA scope.** If an operator enables 2FA on their identity, does it also gate the guest dashboard for the same identity? Yes — 2FA lives on the identity, not the link.
- **Impersonation.** `AdminImpersonation` currently impersonates an `Operator` directly. After Phase 1, does admin impersonate the identity or the operator link? Recommend: impersonate the link (preserves current per-tenant scoping in audit logs).
- **Data-residency.** If we ever host tenants on separate databases, the guest dashboard's cross-tenant read becomes a cross-DB query. Not a Q3 problem, but the identity model should not assume single-DB going in.
