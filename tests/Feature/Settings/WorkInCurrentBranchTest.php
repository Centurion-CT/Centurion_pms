<?php

declare(strict_types=1);

use App\Enums\OperatorRole;
use App\Enums\StockModel;
use App\Http\Middleware\EnsureCentralStoreMode;
use App\Livewire\Dashboard\Inventory\OutletForm;
use App\Livewire\Dashboard\Rates\Index as RatesIndex;
use App\Livewire\Dashboard\Reviews\Editor as ReviewEditor;
use App\Livewire\Dashboard\RoomCategories\Form as CategoryForm;
use App\Livewire\Dashboard\Rooms\Form as RoomForm;
use App\Livewire\Dashboard\Units\Form as UnitForm;
use App\Livewire\Dashboard\Workspaces\Form as WorkspaceForm;
use App\Models\Operator;
use App\Models\Outlet;
use App\Models\Property;
use App\Models\RateRule;
use App\Models\Review;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a manager adds goes into the branch they're working in, and editing
 * something never moves it to another branch. `Tenant->property` is always
 * the first branch, so reading it here would file a second branch's rooms
 * under the first.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'two-branches']);
    $this->tenant->makeCurrent();

    $this->first = Property::factory()->for($this->tenant)->create(['name' => 'First']);
    $this->second = Property::factory()->for($this->tenant)->create(['name' => 'Second']);

    $this->firstCategory = RoomCategory::factory()->for($this->tenant)->for($this->first)->create(['name' => 'First Studio']);
    $this->secondCategory = RoomCategory::factory()->for($this->tenant)->for($this->second)->create(['name' => 'Second Studio']);

    $this->manager = Operator::factory()->for($this->tenant)->create([
        'role' => OperatorRole::Manager,
        'current_property_id' => $this->second->id,
    ]);
    $this->actingAs($this->manager, 'web');
});

afterEach(fn () => Tenant::forgetCurrent());

function workIn(Operator $operator, Property $branch): void
{
    $operator->forceFill(['current_property_id' => $branch->id])->save();
}

it('adds a new category to the branch being worked in', function (): void {
    Livewire::test(CategoryForm::class)
        ->set('name', 'Penthouse')
        ->set('type', 'Suite')
        ->set('base_rate', 90_000)
        ->call('save')
        ->assertHasNoErrors();

    expect(RoomCategory::query()->where('name', 'Penthouse')->sole()->property_id)->toBe($this->second->id);
});

it('keeps an edited category in its own branch', function (): void {
    workIn($this->manager, $this->first);

    Livewire::test(CategoryForm::class, ['roomCategory' => $this->secondCategory])
        ->set('name', 'Second Studio Plus')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->secondCategory->fresh())
        ->name->toBe('Second Studio Plus')
        ->property_id->toBe($this->second->id);
});

it('adds new rooms to the branch being worked in', function (): void {
    Livewire::test(RoomForm::class)
        ->assertViewHas('categories', fn ($categories) => $categories->pluck('id')->all() === [$this->secondCategory->id])
        ->set('room_category_id', $this->secondCategory->id)
        ->call('addRoomNumber', '201')
        ->call('save')
        ->assertHasNoErrors();

    expect(Room::query()->where('room_number', '201')->sole()->property_id)->toBe($this->second->id);
});

it('lets two branches use the same room number', function (): void {
    Room::factory()->for($this->tenant)->for($this->first)->for($this->firstCategory, 'category')->create(['room_number' => '101']);

    Livewire::test(RoomForm::class)
        ->set('room_category_id', $this->secondCategory->id)
        ->call('addRoomNumber', '101')
        ->call('save')
        ->assertHasNoErrors();

    expect(Room::query()->where('room_number', '101')->pluck('property_id')->sort()->values()->all())
        ->toBe([$this->first->id, $this->second->id]);
});

it('edits a room from another branch without moving or orphaning it', function (): void {
    $room = Room::factory()->for($this->tenant)->for($this->second)->for($this->secondCategory, 'category')->create(['room_number' => '101']);
    workIn($this->manager, $this->first);

    Livewire::test(RoomForm::class, ['room' => $room])
        ->assertViewHas('categories', fn ($categories) => $categories->pluck('id')->all() === [$this->secondCategory->id])
        ->set('room_number', '102')
        ->call('save')
        ->assertHasNoErrors();

    expect($room->fresh())
        ->room_number->toBe('102')
        ->property_id->toBe($this->second->id)
        ->room_category_id->toBe($this->secondCategory->id);
});

it('adds rate rules to the branch being worked in and keeps edited ones there', function (): void {
    $page = Livewire::test(RatesIndex::class)
        ->assertViewHas('categories', fn ($categories) => $categories->pluck('id')->all() === [$this->secondCategory->id])
        ->set('categoryId', (string) $this->secondCategory->id)
        ->set('rate_ngn', 55_000)
        ->set('starts_on', Carbon::today()->addDay()->toDateString())
        ->set('ends_on', Carbon::today()->addDays(3)->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    $rule = RateRule::query()->sole();
    expect($rule->property_id)->toBe($this->second->id);

    workIn($this->manager, $this->first);

    $page->call('editRule', $rule->id)->set('rate_ngn', 60_000)->call('save')->assertHasNoErrors();

    expect($rule->fresh())
        ->rate_ngn->toBe(60_000)
        ->property_id->toBe($this->second->id);
});

it('adds units, workspaces and outlets to the branch being worked in', function (): void {
    Livewire::test(UnitForm::class)->set('name', 'Flat 2')->set('address', '1 Road')->set('city', 'Lagos')->set('state', 'Lagos')->call('save')->assertHasNoErrors();
    Livewire::test(WorkspaceForm::class)->set('name', 'Desk 2')->call('save')->assertHasNoErrors();
    Livewire::test(OutletForm::class)->set('name', 'Pool bar')->call('save')->assertHasNoErrors();

    expect(Unit::query()->sole()->property_id)->toBe($this->second->id)
        ->and(Workspace::query()->sole()->property_id)->toBe($this->second->id)
        ->and(Outlet::query()->where('name', 'Pool bar')->sole()->property_id)->toBe($this->second->id);
});

it('keeps an edited outlet in its own branch', function (): void {
    $outlet = Outlet::query()->create(['tenant_id' => $this->tenant->id, 'property_id' => $this->second->id, 'name' => 'Bar', 'type' => 'bar', 'active' => true, 'tax_rate' => 0]);
    workIn($this->manager, $this->first);

    Livewire::test(OutletForm::class, ['outlet' => $outlet])->set('name', 'Lobby bar')->call('save')->assertHasNoErrors();

    expect($outlet->fresh())
        ->name->toBe('Lobby bar')
        ->property_id->toBe($this->second->id);
});

it('keeps an edited review in its own branch', function (): void {
    $review = Review::factory()->for($this->tenant)->for($this->second)->create();
    workIn($this->manager, $this->first);

    Livewire::test(ReviewEditor::class, ['reviewId' => $review->id])->set('body', 'Lovely stay, thank you.')->call('save')->assertHasNoErrors();

    expect($review->fresh())
        ->body->toBe('Lovely stay, thank you.')
        ->property_id->toBe($this->second->id);
});

it('reads the stock mode from the branch being worked in', function (): void {
    $this->first->forceFill(['stock_model' => StockModel::PerDepartment])->save();
    $this->second->forceFill(['stock_model' => StockModel::StoreAndRequisition])->save();

    $response = app(EnsureCentralStoreMode::class)->handle(Request::create('/'), fn () => new Response('store'));

    expect($response->getContent())->toBe('store');
});
