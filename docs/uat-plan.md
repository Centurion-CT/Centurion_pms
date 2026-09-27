# Keverna — User Acceptance Test Plan

**For:** the QA team and anyone helping test Keverna before it ships.
**Owner:** _fill in_
**Sign-off:** Ifeanyi Amadi
**Target run date:** _fill in_
**Build under test:** _fill in on day one_

---

## 1. What this document is

This is a step-by-step guide for testing the whole Keverna platform,
written for people who are not engineers. Every check is something you
can do by clicking around in a normal web browser and reading your
email. Where a step needs an engineer (for example, checking a server
log), it is clearly marked "engineering only" and moved to section 17.

You do **not** need to know any code, run commands, or open a database
to complete the main sections (sections 4–16).

**How to use this guide**

1. Read section 2 and section 3 first — they tell you what accounts to have ready.
2. Work through the journeys in sections 4–16 in order. Each journey is a
   short story about one type of user — a would-be property owner, a
   guest booking a room, and so on. Follow the numbered steps.
3. For every numbered check, fill in **Pass** or **Fail** in the table
   and note anything odd in the **Notes** column. Screenshots help.
4. When you're done, hand the completed sheet to the release owner.

---

## 2. Before you start

You need the following ready. Ask engineering if anything is missing.

- **Two working laptops or one laptop with two browsers.** You will
  need to be logged in as different users at the same time.
- **A phone.** Some tests need you to receive an SMS or WhatsApp code.
- **Access to a test email inbox.** Something like Mailtrap or a
  Sendpipe staging inbox. Engineering will give you the URL.
- **Six test email addresses.** They can be aliases on the test inbox
  (e.g. `alice+solo@example.com`, `alice+growth@example.com`).
- **A test debit card.** Paystack's shared test card:
  - Number: `4084 0840 8408 4081`
  - Expiry: any future date
  - CVV: `408`
  - OTP when prompted: `123456`
- **Website addresses.** Given by engineering on day one:
  - Central site: `https://keverna.com`
  - Admin panel: `https://admin.keverna.com`
  - Tenant sites: `https://{slug}.keverna.com` (each property
    the tester creates gets its own address)
- **A test domain you control** — only for section 11 (custom domain). If you
  don't have one, ask engineering to lend you a scratch domain in
  Namecheap or similar.

---

## 3. The cast of characters (personas)

You'll pretend to be seven different people during this test. Each has
their own goal.

| Persona | Who they are | What they're doing |
| --- | --- | --- |
| **Amara** | A short-let owner in Abuja who just heard about Keverna | Signing up her first property |
| **Chidi** | Runs a small boutique hotel with 12 rooms | Setting up his hotel, taking bookings, running the business day-to-day |
| **Grace** | Runs a coworking space | Selling monthly memberships + day-pass desks |
| **Musa** | Rents out 8 apartments long-term | Managing tenants and rent |
| **Dayo** | A guest travelling to Lagos | Booking a room from Chidi |
| **Kemi** | A finance manager at a company | Running the corporate account that pays for staff stays |
| **Ada** | Keverna staff | Helping operators when they get stuck |

Each journey (sections 4–16) tells you which persona you are for that part.

---

## 4. Test accounts to have set up

Ask engineering to set the following up in staging **before day one**.
If any are missing, the corresponding journey cannot run.

| What | Detail | Used in |
| --- | --- | --- |
| Six seeded properties (tenants) | See section 17.1 for the technical seeder list — engineering will run this | Various journeys below |
| One corporate account "UAT Test Corp" | Wallet balance ₦100,000, one admin member `kemi@uat.test`, one regular member `member@uat.test` | Journey J (Corporate) |
| One admin login | For Ada — engineering will hand you the email + password | Journey L (Admin) |
| Test emails you can receive | Six addresses on the mail sink | All journeys with signup or email |

At the top of every table below, we say **who** you are and **which
tenant/account** you should sign into.

---

## 5. Journey A — Amara becomes a Keverna operator

**You are:** Amara, brand-new visitor to Keverna, signing up for the
first time.

**What you'll do:** Land on the site → look at pricing → sign up →
verify your email + phone → land on your empty dashboard.

**Why it matters:** This is the single most important flow — no
signups means no business. Every step must work.

### 5.1 Reading the marketing site

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `A-01` | P0 | In a fresh browser, visit `https://keverna.com`. | The homepage loads with a big headline "Every unit, one page." and a "Get started" button in the top right. |  |  |
| `A-02` | P0 | Scroll down the homepage slowly. | You see sections in this order: story ("By hand"), how it works step by step, use cases, pricing, and a big closing "Ready when you are" panel. Nothing looks broken or overlaps. |  |  |
| `A-03` | P0 | Click "Pricing" in the top nav. | You jump to the pricing section on the same page. You see four tiers side by side: Starter, Growth (highlighted), Pro, Enterprise. |  |  |
| `A-04` | P0 | Under the pricing tiles, click "See full pricing details →". | The dedicated pricing page opens. It shows the same four tiers with fuller descriptions and an "Included in every tier" list. |  |  |
| `A-05` | P1 | Read the page. Every tier should say what it is for and what it costs per year. | Nothing says "₦300,000" (that was the old price). Starter is ₦180,000, Growth ₦420,000, Pro ₦900,000, Enterprise from ₦1,800,000. |  |  |
| `A-06` | P1 | Click "Talk to us" on the Enterprise tile. | Your email app opens with `hello@keverna.com` in the To field and "Enterprise pricing" in the subject. Close it without sending. |  |  |
| `A-07` | P2 | Shrink your browser window until it's the width of a phone (roughly your thumb + palm — 400 pixels). | The page reflows: the menu turns into a hamburger icon, the tier tiles stack top to bottom, nothing overflows sideways. |  |  |

### 5.2 Signing up under a specific tier

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `A-08` | P0 | On the pricing page, click "Start free trial" on the **Growth** tile. | You land on the sign-up page. Near the top, a highlighted box tells you: "You're signing up for Growth · 6 – 20 units · ₦420,000 / year after trial." |  |  |
| `A-09` | P0 | Fill in the form: your name = Amara Test, email = one of your test addresses, property name = "Amara Suites", 8 units, a password with letters + numbers (at least 8 characters). Submit. | You're taken to an onboarding wizard. |  |  |
| `A-10` | P0 | Go back to `https://keverna.com/register` in a new tab. Change the "How many units will you list?" field from 8 to 25 and watch the box at the top. | The box updates as you type: after a moment it says "Pro · 21 – 50 units · ₦900,000 / year after trial." |  |  |
| `A-11` | P0 | In the same form, change 25 to 100. | The "Start free trial" button disappears; in its place is a "Talk to us" button. |  |  |
| `A-12` | P0 | Change back to a small number (say 8), clear the password field, and hit Submit. | The form doesn't submit — a red error appears under the password field. |  |  |
| `A-13` | P0 | Enter the same email address you used in step A-09 and try to submit. | You see a red error under the email field saying it's already used. |  |  |

### 5.3 Verifying your email and phone

Continue from A-09. You're on the onboarding wizard.

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `A-14` | P0 | The wizard asks you to fill in property basics (address, city, state, timezone). Fill them and continue. | Your details save; you move to email verification. |  |  |
| `A-15` | P0 | Check your test inbox. | You have a new email from Keverna with a 6-digit code, valid for a short window. |  |  |
| `A-16` | P0 | Type the code into the wizard. | The step marks complete; you move to phone verification. |  |  |
| `A-17` | P0 | Enter your phone number and request a code. Check your phone for the SMS or WhatsApp code. | You receive the code within a minute. |  |  |
| `A-18` | P0 | Type the phone code. | Onboarding completes. You land on your dashboard. Your property name (Amara Suites) is in the top-left. |  |  |
| `A-19` | P1 | Sign out (top-right menu → Sign out). Open a new browser window and visit `https://keverna.com/login`. Sign in with the email + password you set. | You are taken straight to Amara Suites' dashboard (because Amara has only one property). |  |  |
| `A-20` | P2 | Look at the URL bar. | You are on `amara-suites.keverna.com` (or similar slug). |  |  |

---

## 6. Journey B — Chidi sets up his boutique hotel

**You are:** Chidi. You already signed up (use the `uat-growth` account
engineering seeded for you — email + password in section 4). Sign into it
now: visit `https://keverna.com/login` and enter Chidi's
details. You land on Chidi's empty dashboard.

**What you'll do:** Turn an empty dashboard into a live booking page —
create room types, create rooms, upload photos, set prices, publish.

### 6.1 Creating room categories

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `B-01` | P0 | From the dashboard sidebar, open Room Categories. Click "Create". | A form opens asking for name, description, sleeps how many adults + children, base rate per night, monthly rate (optional), minimum nights, deposit, booking type. |  |  |
| `B-02` | P0 | Fill in: name = "Deluxe Suite", description = "Sea view", sleeps 2 adults + 1 child, base rate ₦40,000, minimum nights 1, booking type = short_let. Save. | The category is created and appears in the list. |  |  |
| `B-03` | P0 | Create a second category: "Studio", sleeps 2, base rate ₦25,000. |  Second category appears in the list. |  |  |
| `B-04` | P1 | Open the Deluxe Suite category. Upload two photos. | Photos upload without error and show as thumbnails. |  |  |
| `B-05` | P1 | Edit the description of Deluxe Suite. Save. | The change is visible in the list and the detail view. |  |  |

### 6.2 Creating rooms

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `B-06` | P0 | Sidebar → Rooms → Create. | A form asks for a room number, category, and status. |  |  |
| `B-07` | P0 | Create three rooms: `101`, `102`, `103`, all Deluxe Suites, status Active. | Three rooms appear in the list. |  |  |
| `B-08` | P0 | Create two more rooms: `201` and `202`, both Studios. | Both appear in the list. |  |  |
| `B-09` | P1 | Open room `101` and upload a photo. | Photo saves; visible on the detail view. |  |  |
| `B-10` | P1 | Add internal notes ("door sticks", "TV remote in drawer 2"). Save. | Notes save; only visible to signed-in operators (not to guests). |  |  |

### 6.3 Pricing rules

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `B-11` | P0 | Sidebar → Pricing. Add a special price for the Deluxe Suite category over New Year (say Dec 27 – Jan 3 next year), ₦75,000/night. | The rule is saved and shows in the pricing calendar. |  |  |
| `B-12` | P0 | In a private/incognito browser, visit Chidi's public booking page (top-right of the operator dashboard has a "View public page" link). Try to book a Deluxe Suite for one of those New Year nights. | The price shown is ₦75,000/night, not ₦40,000. |  |  |
| `B-13` | P1 | Book for a date outside the special window. | Back to ₦40,000/night. |  |  |

### 6.4 Discounts and packages

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `B-14` | P1 | Sidebar → Discounts → Create. Set a 15% discount for the Studio category, expiring in 30 days. | Discount saves. |  |  |
| `B-15` | P1 | On the public booking page, price a Studio stay. | You see the base price with 15% off applied at checkout. |  |  |
| `B-16` | P1 | Sidebar → Packages → Create. Add "Airport pickup — ₦10,000". | Package saves. |  |  |
| `B-17` | P1 | On the public booking page, when choosing a Deluxe Suite, you can add "Airport pickup". Selecting it adds ₦10,000 to the total. | Add-on total is correct. |  |  |

### 6.5 Branding the booking page

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `B-18` | P0 | Sidebar → Branding → Editor. Change the primary color to something distinctive (e.g. a strong green). Upload a small logo. Save. | Preview panel updates with the new color + logo. |  |  |
| `B-19` | P0 | Visit Chidi's public booking page (open in a private window). | The public page shows Chidi's new color scheme and his logo — not Keverna's default. |  |  |
| `B-20` | P1 | Sidebar → Branding → Photos. Upload a hero photo for the property. | Hero photo appears on the public landing above the room list. |  |  |

### 6.6 Custom pages (mini-CMS)

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `B-21` | P1 | Sidebar → Pages → Create. Title = "House Rules", body = a short paragraph. Save as draft. | Draft is created but nothing appears yet on the public page. |  |  |
| `B-22` | P1 | Copy the page's URL from the address bar. Open it in a private/incognito browser (not signed in). | You get a "Not found" page. |  |  |
| `B-23` | P1 | Back in the dashboard, publish the page. | Now visiting the URL in a private window shows the House Rules content. |  |  |

### 6.7 Email templates

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `B-24` | P1 | Sidebar → Email Templates → Booking Confirmed. Type a small customised opening line ("Karibu to Chidi's Hotel!"). Save. | Template saves. A preview on the same page shows the customised email as it will appear to guests. |  |  |

### 6.8 Staff invites

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `B-25` | P0 | Sidebar → Settings → Staff. Invite a second person using one of your test email addresses. | Invite email is sent. |  |  |
| `B-26` | P0 | Open the invite email (test inbox). Click the link and set a password. | You are signed in as the second staff member. You see the same dashboard but no owner-only actions (e.g. can't remove other staff). |  |  |
| `B-27` | P1 | Sign back in as Chidi (main owner). Remove the second staff member. Sign out. Try to sign back in as the removed staff member. | Sign-in refused with a clear message. |  |  |

---

## 7. Journey C — Chidi's daily operations

**You are:** still Chidi. Continue from Journey B.

**What you'll do:** Handle bookings, calendar changes, customer
records, payments, reviews, and reports — the routine.

### 7.1 Manual booking (walk-in guest)

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `C-01` | P0 | Sidebar → Bookings → Create manually. Enter a walk-in guest called "Bola Test", email `walkin@uat.test`, phone, dates for the next weekend, room 101. | Booking is created in Pending status. Appears in the bookings list. |  |  |
| `C-02` | P0 | Open the booking. Mark it paid (cash). | The booking flips to Confirmed. |  |  |
| `C-03` | P0 | Try to create a second booking for the same room over the same dates. | The system refuses (or clearly warns) that the room is already booked. |  |  |
| `C-04` | P1 | Cancel the walk-in booking from the operator side. Choose a reason. | Status flips to Cancelled. The guest's email address receives a cancellation email. |  |  |

### 7.2 Calendar view

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `C-05` | P0 | Sidebar → Calendar. View the property calendar. | You see a grid: rooms down the side, dates across the top. Booked rooms show as coloured bands. |  |  |
| `C-06` | P0 | Click a booking band. | A summary card opens with the guest name and dates; you can jump to the booking detail from there. |  |  |
| `C-07` | P1 | Block a room for maintenance for 2 days. | The block appears as a distinctive band in the calendar. |  |  |
| `C-08` | P1 | Try to book that room over the blocked dates from the public site. | The public form refuses (or the dates are hidden). |  |  |

### 7.3 Customers (address book)

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `C-09` | P0 | Sidebar → Customers. | You see a list of customers who have booked at Chidi's hotel, including the manual booking from C-01. |  |  |
| `C-10` | P0 | Open a customer. | You see their contact details, total bookings, lifetime value, and a timeline of interactions. |  |  |
| `C-11` | P1 | Add a tag ("VIP") to the customer. Save. | The tag appears next to their name. |  |  |
| `C-12` | P1 | Back on the list, filter by tag "VIP". | Only tagged customers appear. |  |  |

### 7.4 Payments and reports

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `C-13` | P0 | Sidebar → Payments. | You see every payment (or attempted payment) associated with Chidi's bookings. |  |  |
| `C-14` | P0 | Click a paid payment. | Detail view shows amount, method, timestamp, and reference. |  |  |
| `C-15` | P1 | Sidebar → Reports. | A page loads with revenue chart, occupancy chart, and a breakdown by booking source. |  |  |
| `C-16` | P1 | Change the date range to "Last 30 days". | The charts refresh. |  |  |
| `C-17` | P2 | Try the export button (CSV / PDF). | A file downloads. |  |  |

### 7.5 Reviews

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `C-18` | P1 | Wait for (or ask engineering to trigger) a review prompt on a past-checkout booking. | The guest receives a "How was your stay?" email with a review link. |  |  |
| `C-19` | P1 | Click the review link (in the guest's inbox). Submit a 4-star review. | Chidi's Reviews page shows a new pending review. |  |  |
| `C-20` | P1 | Chidi publishes the review. Look at the public booking page for the property. | The review is now visible to future guests. |  |  |
| `C-21` | P1 | Chidi writes a short response ("Thanks for staying with us!"). Save. | The response appears alongside the review on the public page. |  |  |

### 7.6 Notifications

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `C-22` | P0 | With Chidi signed in, ask a colleague to complete a real booking on Chidi's public page (or run Journey I with a fresh guest). | Chidi sees a new notification in the bell icon. Clicking it takes him to the booking. |  |  |
| `C-23` | P1 | Click "Mark all as read". | Unread count goes to zero. |  |  |

---

## 8. Journey D — Chidi's subscription upgrade

**You are:** still Chidi. His 14-day trial is about to end. Ask
engineering to set his trial expiry to tomorrow so you can test the
upgrade path.

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `D-01` | P0 | Sign in as Chidi. Look at the dashboard. | A banner across the top warns him that his trial ends in 1 day. |  |  |
| `D-02` | P0 | Click "Upgrade" or open Settings → Subscription. | The upgrade page shows the tier Chidi picked at signup (Growth, ₦420,000/yr). |  |  |
| `D-03` | P0 | Click "Pay with Paystack". | You are taken to a Paystack test checkout. The amount shown matches ₦420,000. |  |  |
| `D-04` | P0 | Complete the payment with the Paystack test card (section 2). | You are redirected back to the dashboard. The trial banner is gone. Subscription status shows Active. |  |  |
| `D-05` | P1 | Look at the subscription detail. | It shows the amount paid, the paid-at date, and an expiry roughly one year from today. |  |  |

---

## 9. Journey E — Chidi attaches a custom domain

**You are:** still Chidi. He owns a real test domain — ask engineering
to lend you a scratch domain if you don't have one.

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `E-01` | P0 | Sidebar → Domain. | You see Chidi's current subdomain and instructions for pointing his own domain. The instructions include a target hostname (something like `cname.keverna.com`) and a text verification code. |  |  |
| `E-02` | P0 | Enter Chidi's test domain (e.g. `book.chidi-uat.test`) and click Attach. | Verification instructions appear. |  |  |
| `E-03` | P0 | Log into your DNS provider (Namecheap, GoDaddy, wherever the domain is). Add: a CNAME record for the domain pointing at the target hostname, and a TXT record with the verification code. Save. | Records go live. This may take 5–15 minutes. |  |  |
| `E-04` | P0 | Back in Chidi's dashboard, click "Verify now". Wait a minute or two. | Domain status flips to Verified. |  |  |
| `E-05` | P0 | In a new browser tab, visit `https://book.chidi-uat.test/`. | Chidi's public booking page loads over the custom domain. The address bar shows a valid padlock (secure connection). |  |  |
| `E-06` | P1 | Try to book a room via the custom domain. | Booking flow works exactly the same as on the Keverna subdomain. |  |  |
| `E-07` | P1 | In the dashboard, remove the custom domain. Visit `https://book.chidi-uat.test/` again. | The custom domain no longer serves Chidi's page. His Keverna subdomain still works. |  |  |

---

## 10. Journey F — Grace runs a coworking space

**You are:** Grace, signed into her seeded account (`uat-work`).

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `F-01` | P0 | Sidebar → Workspace Plans → Create. Add "Hot Desk Monthly" at ₦50,000/month. | Plan saves and appears in the list. |  |  |
| `F-02` | P0 | Sidebar → Workspaces → Create. Set up "Main Floor" with 20 desks, associate with the Hot Desk Monthly plan. | Workspace saves. |  |  |
| `F-03` | P0 | Open Grace's public page in a private window. Click through to Workspaces. | Grace's coworking space is listed with the plan and price. |  |  |
| `F-04` | P0 | As a visitor, click "Join monthly plan". Fill in details, pay with the Paystack test card. | Payment succeeds; you land on a "welcome" page. Grace's dashboard shows a new membership. |  |  |
| `F-05` | P0 | As a visitor to a workspace, book a single day pass instead. Complete payment. | Booking is created; Grace sees a new day-pass entry. |  |  |
| `F-06` | P1 | In the dashboard → Memberships, pause the new member's membership. | Status flips to Paused. The paused member receives an email. |  |  |
| `F-07` | P1 | Resume the membership. | Status flips to Active. |  |  |

---

## 11. Journey G — Musa runs residential rentals

**You are:** Musa, signed into `uat-lease`.

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `G-01` | P0 | Sidebar → Units → Create. Add "Unit A1" with monthly rent ₦300,000. | Unit is created. |  |  |
| `G-02` | P0 | Public page → Units. | Unit A1 is listed with rent + an "Apply" link. |  |  |
| `G-03` | P0 | Sidebar → Leases → Create. Attach Unit A1 to a customer (create a new one called Tunde Test), start date today, end date in 12 months, monthly rent ₦300,000. | Lease saves. |  |  |
| `G-04` | P1 | On the lease detail, upload a signed lease document (any PDF). | Document uploads and is downloadable from the same page. |  |  |
| `G-05` | P0 | Sidebar → Rent Schedule. | You see a row for each upcoming rent month for Tunde's lease. |  |  |
| `G-06` | P0 | Mark the first month's rent as paid. | Row flips to Paid; total collected updates. |  |  |
| `G-07` | P1 | Terminate the lease early (say 3 months in). | Confirmation dialog appears. On confirm, future rent rows are cleared and lease end date updates. |  |  |

---

## 12. Journey H — Dayo books a stay (a guest's first visit)

**You are:** Dayo. Sign out first — you should not be logged into
anything for this journey. Use a private/incognito browser to be
safe.

### 12.1 Finding a room

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `H-01` | P0 | Visit Chidi's public booking page (e.g. `chidi-boutique.keverna.com`). | The page loads with Chidi's branding, a hero photo, and a "Book a room" call to action. |  |  |
| `H-02` | P0 | Click through to browse rooms. | The rooms page loads with the categories Chidi set up (Deluxe Suites, Studios) and photos. |  |  |
| `H-03` | P0 | Pick check-in tomorrow, check-out three days later, 2 adults. | The list filters to available rooms and shows the correct nightly rate. |  |  |
| `H-04` | P0 | Click a Deluxe Suite. | Detail page shows photos, description, guest capacity, and a "Book" form. |  |  |

### 12.2 Filling in the booking

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `H-05` | P0 | Fill in: guest name Dayo Test, email `dayo@uat.test`, phone, add the airport pickup package. | The total updates on-screen: room × 3 nights + package + Keverna fee + Paystack fee. |  |  |
| `H-06` | P0 | Click "Continue to payment". | A Paystack test checkout opens with the same total. |  |  |
| `H-07` | P0 | Pay with the test card (section 2). | The page redirects back to Chidi's site. A confirmation page shows Dayo's booking reference, dates, and a link to "manage your booking". |  |  |

### 12.3 Confirmation emails

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `H-08` | P0 | Check Dayo's inbox. | Dayo has a confirmation email with the booking reference, dates, and a "manage your booking" link. The email includes Chidi's customised greeting (from B-24). |  |  |
| `H-09` | P1 | Check the operator's inbox (`chidi@uat.test`). | Chidi also has a copy of the confirmation. |  |  |
| `H-10` | P0 | Sign into Chidi's dashboard. | Chidi has a new bell notification. The new booking is visible on the Bookings page with status Confirmed. |  |  |

### 12.4 Managing the booking from the guest portal

Every confirmation email includes a "manage your booking" link — copy
that link from Dayo's inbox and open it in a private window.

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `H-11` | P0 | Open the "manage your booking" link. | The guest portal opens showing Dayo's booking summary. There are actions to change dates, cancel, pay balance, add packages, and leave a review after checkout. |  |  |
| `H-12` | P0 | Copy the same URL but delete the `token=…` part at the end. Visit it. | You get a "Not authorised" or "Not found" page. |  |  |
| `H-13` | P1 | Back on the working portal, request a date change (say, push checkout back one day). | You see a confirmation that the request was submitted. Chidi's Reservations page shows a new pending change request. |  |  |
| `H-14` | P1 | Return to the portal and cancel the booking instead. | Booking status flips to Cancelled. Dayo receives a cancellation email. |  |  |

---

## 13. Journey I — Dayo becomes a Keverna guest (across many properties)

**You are:** Dayo, but this time a returning guest who has booked at
more than one Keverna property. Engineering has seeded a second
booking for Dayo at `uat-solo`. You'll now book a third at Chidi's,
then Keverna will invite Dayo to see everything in one place.

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `I-01` | P0 | Book a stay at Chidi's using Dayo's email address (the same as H-05). | Dayo receives the usual confirmation email — **and** a second email inviting him to "See all your Keverna reservations in one place". |  |  |
| `I-02` | P0 | Open the second email and click "See all my reservations". | You are signed in and land on a page called "Your reservations" showing every booking Dayo has ever made across every Keverna property — Chidi's + the seeded one on `uat-solo`. |  |  |
| `I-03` | P0 | Look at each reservation row. | Each row shows the property name, the room, the dates, the number of nights, the booking reference, the total amount, and the payment status. Any bookings that still need a balance payment are called out. |  |  |
| `I-04` | P0 | Open a new tab and paste the same "See all my reservations" link. | You get a "Not authorised" message — the link only works once. |  |  |
| `I-05` | P1 | Complete another booking as Dayo on Chidi's site. | Dayo receives the booking confirmation, but does **not** receive a second claim-invite email (because Dayo is already claimed). |  |  |
| `I-06` | P1 | On the reservations page, click "Switch account" (top-right). | You land on a chooser page. Because Dayo has only guest reservations, it shows one card: "My reservations". |  |  |
| `I-07` | P1 | Sign out from the top-right menu. Sign back in on the central `/login` page with Dayo's email and no password (the "magic link only" case). | The system tells you it will email a sign-in link (or shows that magic-link is the only option for guest identities). |  |  |

---

## 14. Journey J — Kemi runs the corporate account

**You are:** Kemi. Engineering seeded a corporate for you called "UAT
Test Corp" with a ₦100,000 wallet balance and your login details in section 4.

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `J-01` | P0 | Visit `https://keverna.com/corporates/login`. Sign in with Kemi's credentials. | You land on the corporate dashboard. Wallet balance ₦100,000 is displayed. |  |  |
| `J-02` | P0 | Sidebar → Members. Invite a colleague (use one of your test emails). | Invite email is sent. |  |  |
| `J-03` | P0 | Open the invite email. Set a password. | You are signed in as the new member. You see a member view (fewer actions than Kemi's admin view). |  |  |
| `J-04` | P0 | Sign back in as Kemi (admin). Go to Wallet → Top up. Pay ₦50,000 with the Paystack test card. | On return, wallet balance shows ₦150,000. You also see a top-up entry in the audit list. Kemi receives a top-up success email. |  |  |
| `J-05` | P0 | Sidebar → Bookings. | You see any bookings made by any member of UAT Test Corp so far. |  |  |
| `J-06` | P1 | Sign out. Sign in as the regular (non-admin) member you invited. | The login is rejected — the corporate portal only allows admins in v1. |  |  |
| `J-07` | P1 | As Kemi again, deactivate the invited member. Try to sign in as that member. | Sign-in refused. |  |  |

---

## 15. Journey K — A corporate member books a stay

**You are:** the corporate member. Ask engineering to reactivate the
member from J-07 first.

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `K-01` | P0 | Visit Chidi's public booking page in a private browser. Book a room. When asked, sign in with your corporate member credentials (or enter your corporate email in the guest form). | The nightly price you see is the corporate rate (should be different from the regular price if engineering set a corporate rate). |  |  |
| `K-02` | P0 | Complete the booking. | Instead of asking for a card, the flow charges Kemi's corporate wallet. Wallet balance drops by the booking amount. |  |  |
| `K-03` | P1 | Back in Kemi's corporate portal → Bookings. | The new booking appears with the member name + total. |  |  |
| `K-04` | P1 | If engineering can drop the wallet balance below a threshold, wait for the low-balance alert email. | Kemi receives a "low balance" alert email. |  |  |

---

## 16. Journey L — Ada from Keverna helps an operator

**You are:** Ada, Keverna staff. Sign into the admin panel with the
admin credentials engineering handed you in section 4.

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `L-01` | P0 | Visit `https://admin.keverna.com/admin`. Sign in. | Admin dashboard loads. |  |  |
| `L-02` | P0 | Try to sign into the admin panel with an operator's credentials (Chidi's, for example). | Sign-in is refused. |  |  |
| `L-03` | P0 | Sidebar → Tenants. Search for "chidi". | Chidi's property appears with slug, name, status, and tier. |  |  |
| `L-04` | P0 | Open Chidi's tenant row. Click "Impersonate operator". | You are redirected to Chidi's dashboard signed in as Chidi. A banner across the top says "You are impersonating Chidi. End impersonation". |  |  |
| `L-05` | P0 | Click "End impersonation" in the banner. | You are signed out of Chidi's account and back on the admin panel. |  |  |
| `L-06` | P0 | Sidebar → Subscriptions. | A list of every operator's subscription with status, plan, and expiry. Chidi's shows Active from Journey D. |  |  |
| `L-07` | P1 | Sidebar → Revenue. | A revenue chart + totals load. |  |  |
| `L-08` | P1 | Sidebar → Corporates. Open UAT Test Corp. | You see wallet balance, member list, and Kemi as admin. |  |  |
| `L-09` | P1 | Sidebar → Activity. | Recent actions across the platform are logged (impersonation start/end, tenant creation, etc.). |  |  |
| `L-10` | P2 | Sidebar → Admins → Create. Add a new admin. Sign out and sign in as the new admin. | New admin can sign in. |  |  |

---

## 17. For engineering — infrastructure sanity checks

These checks require log access, terminal access, or database
inspection. Someone from engineering should complete them once, at
the start of the UAT window, and report the results here.

### 17.1 Seeded data set-up

Before UAT begins, engineering runs the seeder or manually creates:

- Six tenants: `uat-solo` (Amara/Starter, 3 units), `uat-growth`
  (Chidi/Growth, 12 units), `uat-multi` (Pro, 30 units), `uat-work`
  (Grace/Growth, 20 units, workspace mode), `uat-lease` (Musa/Growth,
  8 units, residential mode), `uat-legacy` (Active, `plan_id =
  annual_300k`, 5 units) — this last one is critical to prove
  grandfathered accounts still work.
- One corporate "UAT Test Corp" with wallet balance ₦100,000, one
  admin `kemi@uat.test`, one non-admin `member@uat.test`, corporate
  rate attached to `uat-growth`.
- One admin `ada@uat.test`.
- Two seeded bookings for Dayo (`dayo@uat.test`): one on `uat-solo`
  (past) and one on `uat-growth` — needed for Journey I.

### 17.2 Wildcard DNS + subdomain provisioning

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `X-01` | P0 | On a terminal, run `dig +short a-random-slug.keverna.com`, `another-random.keverna.com`, `third-random.keverna.com`. | All three resolve to the same target (Dokwe ingress). |  |  |
| `X-02` | P0 | Register a fresh operator (Journey A). Within 30 seconds, visit their subdomain in a browser. | Site loads over HTTPS with a valid padlock. |  |  |
| `X-03` | P0 | Inspect the certificate in the browser. | Certificate is a `*.keverna.com` wildcard. |  |  |
| `X-04` | P0 | Grep the queue worker log around the moment of the fresh signup for "ProvisionTenantSubdomain". | Job runs. **No** requests to `api.cloudflare.com`. `AttachTenantDomainToDokwe` is dispatched afterwards. |  |  |
| `X-05` | P0 | `grep CLOUDFLARE_ .env` on the deployed instance. | Zero results — the env vars are gone. |  |  |
| `X-06` | P1 | Visit `does-not-exist.keverna.com`. | 404. |  |  |

### 17.3 Multi-tenant data isolation

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `X-07` | P0 | Sign into Chidi's dashboard. Take one of his booking URLs. Change the ID in the URL to a booking ID from `uat-solo`. | Not found. |  |  |
| `X-08` | P0 | Copy a guest portal URL from Dayo's inbox (for a booking on Chidi's property). Change the booking reference in the URL to a reference from `uat-solo`. Load it. | Not found. |  |  |
| `X-09` | P0 | Export a bookings CSV as Chidi. | The file contains only Chidi's bookings, no `uat-solo` rows. |  |  |
| `X-10` | P0 | Chidi's bell notifications. | Only Chidi's notifications; no `uat-solo` events leak. |  |  |

### 17.4 SSO handoff (unified login)

Ask an engineer to walk through these with browser DevTools open on the
Network tab so they can capture the signed URL when the central login
redirects to a tenant subdomain.

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `X-11` | P0 | Capture the signed URL from a normal SSO handoff. Follow it a second time in an incognito window. | Second visit returns 401. |  |  |
| `X-12` | P0 | Wait 90 seconds, then follow the captured URL. | 401 (expired). |  |  |
| `X-13` | P0 | Edit one character of the `signature=` value in the URL. Visit it. | 401 (bad signature). |  |  |
| `X-14` | P0 | Take a URL signed for tenant A. Change the host in the URL to tenant B's subdomain. | 401 (host is part of the signed material). |  |  |
| `X-15` | P1 | Between issuing the URL and following it, delete the identity → operator link (via the admin panel or DB). | 403 on consume. |  |  |

### 17.5 Payment webhooks (Paystack)

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `X-16` | P0 | Trigger the Paystack subscription webhook with a valid signed payload from the Paystack dashboard test tools. | Subscription flips to Active in the DB; renewal-reminder job resets. |  |  |
| `X-17` | P0 | POST the same webhook payload again. | Second call is a no-op (no double-credit). |  |  |
| `X-18` | P0 | POST a webhook payload with a broken signature. | 401. |  |  |
| `X-19` | P0 | Trigger the tenant-side booking webhook. Booking flips to Confirmed; emails fire (verify via mail sink from Journey H). | Confirmation is sent. |  |  |
| `X-20` | P0 | Trigger the corporate wallet top-up webhook (from Journey J-04). | Wallet balance increases; audit row written; success email sent. |  |  |

---

## 18. Cross-cutting user checks

Any tester can do these — they don't need engineering.

| ID | Priority | What to do | What should happen | Pass/Fail | Notes |
|---|---|---|---|---|---|
| `Y-01` | P0 | Visit any staging URL over plain HTTP (e.g. `http://keverna.com`). | Automatically redirects to HTTPS. |  |  |
| `Y-02` | P0 | On Chrome desktop, Firefox desktop, and Chrome mobile, walk through Journey A (signup) once. | Sign-up completes on all three; layouts adapt to mobile. |  |  |
| `Y-03` | P0 | Any state-changing form (booking, staff invite, wallet top-up). Reload the page after landing on the form, wait an hour, submit. | The form should either still work (session refreshed) or clearly redirect to sign in again. It should never crash the page. |  |  |
| `Y-04` | P0 | Sign in as any operator. Wait until the session should have expired (ask engineering for the expiry window). Try to visit the dashboard. | Redirected to sign in. |  |  |
| `Y-05` | P0 | Visit `https://keverna.com/does-not-exist`. | You see a branded "Not found" page (not a raw error). |  |  |
| `Y-06` | P1 | Try to sign into an operator dashboard with a **wrong** password five times in a row. On the sixth attempt, try the **correct** password. | The sixth attempt is throttled with a "too many attempts" message. Sign-in only succeeds after the cool-down. |  |  |
| `Y-07` | P1 | On any tenant sign-in page, click "Forgot password". | You receive a reset email. Follow the link, set a new password, sign in with the new one. |  |  |
| `Y-08` | P1 | Register with a property name containing HTML-like characters (`<b>My Suite</b>`). Look at the public page + dashboard nav + the confirmation emails. | The characters are shown as plain text everywhere; nothing renders as bold or formatted markup. |  |  |
| `Y-09` | P2 | Run a Lighthouse audit in Chrome DevTools on the landing page, `/pricing`, `/register`, `/login`. | Accessibility score ≥ 90 on each. |  |  |

---

## 19. Regression checklist (must-pass)

Do these last. Any failure blocks the release.

- [ ] The grandfathered legacy operator (`uat-legacy`) can sign into
      their tenant subdomain and reach their dashboard.
- [ ] The legacy operator can pay their renewal via Paystack (do a full
      run through Journey D on `uat-legacy` — the amount should be
      ₦300,000, the old flat price, not one of the new tiers).
- [ ] The corporate portal (Journey J) still works end to end.
- [ ] The admin panel (Journey L) still works end to end.
- [ ] All P0 test cases across sections 5–18 have Pass.
- [ ] All P1 test cases either have Pass, or have an accepted
      workaround recorded in section 21.
- [ ] Engineering confirms the automated test suite (`php artisan
      test`) is green on the same build tag under test.
- [ ] Engineering confirms wildcard DNS + wildcard TLS certificate are
      in place at Namecheap + Dokwe (section 17.2).

---

## 20. Bug reporting

For every failed check:

1. Write down the case ID (e.g. `H-11`).
2. Note which browser + device you used.
3. Take a screenshot of what went wrong.
4. If the failure was about an email, save the raw email or a
   screenshot with the sender + subject visible.
5. Create a ticket in the bug tracker with the case ID, screenshots,
   and a one-line description of what you saw vs. what should have
   happened.
6. Assign a priority: match the case's priority unless you have a
   reason to change it.

---

## 21. Accepted workarounds

Anything that failed but has an agreed workaround (so we can still
ship). Fill in during the run.

| Case ID | What failed | Workaround | Owner | Fix by |
|---|---|---|---|---|
|  |  |  |  |  |

---

## 22. Sign-off

Sign only when section 19 (regression checklist) is fully ticked.

| Role | Name | Signature | Date |
|---|---|---|---|
| QA lead |  |  |  |
| Engineering lead |  |  |  |
| Product / Founder |  |  |  |
