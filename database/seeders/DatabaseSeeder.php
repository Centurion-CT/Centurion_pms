<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Admin;
use App\Models\Operator;
use App\Models\Property;
use App\Models\Review;
use App\Models\Room;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Platform admin for local dev (PRD §8).
        Admin::factory()->super()->create([
            'name' => 'Platform Admin',
            'email' => 'admin@keverna.test',
        ]);

        // Sample tenant + operator + live property + rooms so the tenant
        // landing renders the full branded page out of the box (PRD §6.4).
        $tenant = Tenant::factory()->create([
            'name' => 'Grand Palace Abuja',
            'slug' => 'grand-palace-abuja',
            'email' => 'gm@grandpalace.test',
            'phone' => '+2348012345678',
            'status' => TenantStatus::Active,
            'subscription_expires_at' => Carbon::now()->addYear(),
        ]);

        $tenant->execute(function () use ($tenant): void {
            Operator::factory()->for($tenant)->create([
                'name' => 'Demo Operator',
                'email' => 'demo@grandpalace.test',
            ]);

            Subscription::factory()->for($tenant)->create([
                'plan' => SubscriptionPlan::Annual,
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addYear(),
                'paid_at' => Carbon::now(),
            ]);

            $property = Property::factory()->live()->for($tenant)->create([
                'name' => 'Grand Palace Abuja',
                'tagline' => 'Boutique stays in the heart of Maitama',
                'description' => 'A quiet five-room boutique stay tucked behind the embassies in Maitama. Twelve minutes from the airport, walking distance to Wuse Market. Power 24/7, fibre Wi-Fi, on-site parking, breakfast on request.',
                'city' => 'Abuja',
                'state' => 'FCT',
                'primary_color' => '#1f6feb',
                'amenities' => [
                    'Free Wi-Fi (300 Mbps)',
                    '24/7 backup power',
                    'Hot water (24/7)',
                    'Daily housekeeping',
                    'Secure parking',
                    'Airport pickup on request',
                    'Smart TV with Netflix',
                    'In-room safe',
                    'Air-conditioned rooms',
                    'Breakfast available',
                ],
            ]);

            // Rooms first so we can attach per-room reviews.
            $roomSeed = [
                ['Maitama Suite', 'Suite', 45_000, 'Our largest room. King bed, sitting area, full-height windows over the embassy gardens.'],
                ['Asokoro Studio', 'Studio', 30_000, 'Compact but well-appointed studio with a kitchenette, perfect for solo travellers on short stays.'],
                ['Wuse Loft', 'Loft', 25_000, 'Upper-floor loft with a private balcony and city skyline view. Queen bed, work desk, fast Wi-Fi.'],
            ];
            $rooms = [];
            foreach ($roomSeed as [$name, $type, $rate, $desc]) {
                $rooms[$name] = Room::factory()->for($tenant)->for($property)->create([
                    'name' => $name,
                    'type' => $type,
                    'description' => $desc,
                    'base_rate' => $rate,
                    'capacity_adults' => 2,
                    'capacity_children' => 1,
                    'min_nights' => 1,
                ]);
            }

            // Two property-wide reviews + one or two per-room reviews each.
            $reviewSeed = [
                // [guest, rating, body, room_name (null = property-wide)]
                ['Adaeze Nwosu', 5, 'Spotless, quiet, and the host responded to my WhatsApp in under 2 minutes. Already booked for next month.', null],
                ['Tunde Ogun', 5, 'Stayed three nights for a work trip. Wi-Fi was solid for video calls, power never dropped, breakfast was a nice touch.', null],
                ['Maryam Bello', 4, 'The suite was airy and the bedding was top-notch. Loved the view over the gardens.', 'Maitama Suite'],
                ['Chuka Eze', 5, 'Suite felt more like a small apartment. The sitting area is perfect for working evenings.', 'Maitama Suite'],
                ['Aisha Lawal', 5, 'Studio fit my solo trip perfectly. Kitchenette had everything I needed for breakfast.', 'Asokoro Studio'],
                ['Femi Adekunle', 4, 'Great value studio for a short stay. The morning light is lovely.', 'Asokoro Studio'],
                ['Ngozi Okafor', 5, 'The loft balcony view at sunset is unbeatable. Work desk had perfect lighting too.', 'Wuse Loft'],
            ];
            foreach ($reviewSeed as $i => [$name, $rating, $body, $roomName]) {
                $factory = Review::factory()->for($tenant)->for($property);
                if ($roomName !== null && isset($rooms[$roomName])) {
                    $factory = $factory->forRoom($rooms[$roomName]);
                }
                $factory->create([
                    'guest_name' => $name,
                    'rating' => $rating,
                    'body' => $body,
                    'is_displayed' => true,
                    'order_column' => $i,
                ]);
            }
        });

        // Central login is backed by the `identity` guard on the
        // IdentityAccount table (see App\Livewire\Central\Login), which
        // OperatorFactory does not touch. Without a matching identity row
        // the seeded demo operator can log in on the tenant subdomain but
        // NOT on keverna.test/login. Run the backfill after every seed so
        // migrate:fresh --seed leaves central login usable out of the box.
        Artisan::call('identity:backfill-operators');

        // Prime the platform_stats singleton so the marketing kobo
        // counter reads a real (even if tiny) baseline out of the box
        // instead of ₦0 until the scheduler's first tick. The command
        // itself is O(2 SUM queries) so this stays cheap on a fresh
        // install with a handful of seeded payments.
        Artisan::call('keverna:recompute-platform-stats');
    }
}
