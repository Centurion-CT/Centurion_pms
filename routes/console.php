<?php

declare(strict_types=1);

use App\Console\Commands\Bookings\AutoCancelExpiredHolds;
use App\Console\Commands\Bookings\AutoDeductFromWallet;
use App\Console\Commands\Bookings\ReleaseStalePendingBookings;
use App\Console\Commands\Bookings\SendBalanceReminders;
use App\Console\Commands\Bookings\SendCheckInReminders;
use App\Console\Commands\Bookings\SendExtendPrompts;
use App\Console\Commands\Bookings\SendReviewPrompts;
use App\Console\Commands\Domains\CheckOwnDomains;
use App\Console\Commands\Guests\PurgeGuestIdDocuments;
use App\Console\Commands\Inventory\SendLowStockDigest;
use App\Console\Commands\RenewCorporateMembershipsCommand;
use App\Console\Commands\Reports\SendSummaryReports;
use App\Multitenancy\HostTenantFinder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Booking lifecycle (PRD §11)
|--------------------------------------------------------------------------
| Pending bookings whose Paystack flow never completed (closed tab, etc.)
| get cancelled + their nights released after 15 minutes. Every-minute cron.
*/

Schedule::command(ReleaseStalePendingBookings::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Hold-flow lifecycle (PRD §6.4.3)
|--------------------------------------------------------------------------
| Both run daily - the reminder slot fires before the cancel slot so a
| same-day deadline still gets a heads-up email before the auto-cancel
| sweeps it.
*/

Schedule::command(SendBalanceReminders::class)
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Guest wallet auto-deduction - overstay accrual + balance-due
 * collection. Runs at 07:45 so a wallet-funded OnHold booking is
 * settled BEFORE AutoCancelExpiredHolds sweeps it at 08:15.
 */
Schedule::command(AutoDeductFromWallet::class)
    ->dailyAt('07:45')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(AutoCancelExpiredHolds::class)
    ->dailyAt('08:15')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Pre-arrival reminders (PRD §17)
|--------------------------------------------------------------------------
| Send "see you tomorrow" at a friendlier hour than the 08:00 cohort - the
| message is hospitality, not collections.
*/

Schedule::command(SendCheckInReminders::class)
    ->dailyAt('14:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Day-before extend prompt
|--------------------------------------------------------------------------
| In-house guests checking out tomorrow get a WhatsApp inviting them
| to extend via the room portal. Fires late morning so guests are
| awake but with enough time in the day to think about it. Skipped
| for rooms booked back-to-back - see SendExtendPrompts for details.
*/

Schedule::command(SendExtendPrompts::class)
    ->dailyAt('11:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Post-stay review prompts (PRD post-§19)
|--------------------------------------------------------------------------
| Fires 24h after checkout - picks bookings whose checkout_date was
| yesterday and the guest hasn't been prompted yet.
*/

Schedule::command(SendReviewPrompts::class)
    ->dailyAt('10:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Corporate membership renewals
|--------------------------------------------------------------------------
| Every Active corporate membership whose `next_renewal_at` has arrived
| gets one period's price held + captured from the wallet. Insufficient
| wallet → membership auto-paused (admin tops up to resume).
|
| Daily at 02:00 WAT - quiet hours so the load doesn't compete with
| guest-facing traffic.
*/

Schedule::command(RenewCorporateMembershipsCommand::class)
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Low-stock digest (Inventory / POS)
|--------------------------------------------------------------------------
| Daily at 07:30 WAT - managers get one email listing every stock-tracked
| product at or below its low_stock_threshold. Silent when nothing crosses
| the line; no "everything's fine" spam.
*/

Schedule::command(SendLowStockDigest::class)
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Summary reports (Owner-facing periodic snapshots)
|--------------------------------------------------------------------------
| Weekly fires Monday 06:00 WAT and covers the prior Mon–Sun. Monthly
| fires the 1st of the month at 06:00 and covers the prior calendar
| month. One PDF per tenant, emailed to each subscriber opted in for
| that cadence.
*/

Schedule::command(SendSummaryReports::class, ['weekly'])
    ->mondays()
    ->at('06:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(SendSummaryReports::class, ['monthly'])
    ->monthlyOn(1, '06:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Guest ID retention (NDPA, Centurion Desk check-in)
|--------------------------------------------------------------------------
| Wipes ID numbers, dates of birth and scans once check-out +
| keverna.guest_id_retention_months has passed. Quiet hour, Lagos time.
*/

Schedule::command(PurgeGuestIdDocuments::class)
    ->dailyAt('03:30')
    ->timezone('Africa/Lagos')
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Own domains (docs/MULTITENANCY.md, step 4)
|--------------------------------------------------------------------------
| Each verified own domain is asked over HTTPS which business it serves.
| Guests only move to it while it answers. Not in `single` mode.
*/

Schedule::command(CheckOwnDomains::class)
    ->hourly()
    ->when(fn (): bool => HostTenantFinder::isDomainMode())
    ->withoutOverlapping()
    ->onOneServer();
