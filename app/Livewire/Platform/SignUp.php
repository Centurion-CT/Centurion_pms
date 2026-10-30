<?php

declare(strict_types=1);

namespace App\Livewire\Platform;

use App\Actions\Platform\CreateBusiness;
use App\Enums\PropertyType;
use App\Mail\Operators\OperatorWelcomeMail;
use App\Mail\Platform\SignUpCodeMail;
use App\Platform\BusinessAddress;
use App\Platform\SignUpCode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Self sign-up on the platform domain (docs/MULTITENANCY.md, step 3).
 *
 *   business → account → code → done
 *
 * Nothing is created until the emailed code is confirmed. The password is
 * hashed into the session as soon as step 2 is submitted, so it never
 * travels back to the browser in Livewire's component state.
 * Design: design/platform/superdesign/04–06.
 */
#[Layout('platform.layouts.app')]
class SignUp extends Component
{
    /** What the "What do you run?" choices create. */
    public const KINDS = [
        'serviced' => ['Serviced apartments', PropertyType::ServicedApartment],
        'hotel' => ['Hotel', PropertyType::Hotel],
        'guest_house' => ['Guest house', PropertyType::Hotel],
        'short_let' => ['Short-let', PropertyType::ShortLet],
    ];

    /** "Rooms, roughly" → the number stored as the business's expected size. */
    public const SIZES = ['1–10' => 10, '11–30' => 30, '31–80' => 80, 'More than 80' => 120];

    public string $step = 'business';

    public string $name = '';

    #[Url(as: 'slug', history: false)]
    public string $slug = '';

    public bool $slugTouched = false;

    public string $kind = 'serviced';

    public string $city = '';

    public string $size = '11–30';

    public string $managerName = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    public string $code = '';

    public string $codeError = '';

    public string $host = '';

    public function mount(): void
    {
        $this->slug = strtolower(substr(preg_replace('/[^a-z0-9-]/i', '', $this->slug) ?? '', 0, BusinessAddress::MAX));
        $this->slugTouched = $this->slug !== '';
    }

    public function updatedName(): void
    {
        if (! $this->slugTouched) {
            $this->slug = BusinessAddress::suggestFromName($this->name);
        }
    }

    public function updatedSlug(): void
    {
        $this->slug = strtolower(preg_replace('/[^a-z0-9-]/i', '', $this->slug) ?? '');
        $this->slugTouched = $this->slug !== '';
    }

    public function submitBusiness(BusinessAddress $address): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string'],
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'city' => ['required', 'string', 'max:80'],
            'size' => ['required', Rule::in(array_keys(self::SIZES))],
        ], [], ['name' => 'business name', 'slug' => 'address']);

        $check = $address->check($this->slug);
        if (! $check['available']) {
            $this->addError('slug', $check['message']);

            return;
        }

        $this->step = 'account';
    }

    public function submitAccount(SignUpCode $codes): void
    {
        $this->validate([
            'managerName' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9 +()-]{7,}$/'],
            'password' => ['required', 'string', 'min:10'],
        ], [], ['managerName' => 'your name', 'phone' => 'phone number']);

        session()->put('signup.password_hash', Hash::make($this->password));
        $this->password = '';

        if ($this->sendCode($codes)) {
            $this->step = 'code';
        }
    }

    public function resend(SignUpCode $codes): void
    {
        $this->code = '';
        $this->sendCode($codes);
    }

    public function confirm(SignUpCode $codes, BusinessAddress $address, CreateBusiness $create): void
    {
        $this->codeError = '';
        $code = preg_replace('/\D/', '', $this->code) ?? '';

        $result = strlen($code) === 6 ? $codes->verify($this->email, $code) : 'wrong';
        if ($result !== 'ok') {
            $this->codeError = match ($result) {
                'wrong' => "That code doesn't match. Check the email and try again.",
                'expired' => 'That code has expired. Send a new one.',
                'locked' => 'Too many wrong codes. Send a new one.',
            };

            return;
        }

        $passwordHash = session()->get('signup.password_hash');
        if (! is_string($passwordHash)) {
            $this->step = 'account';
            $this->addError('password', 'Enter your password again.');

            return;
        }

        // The address may have gone while they read their email.
        if (! $address->check($this->slug)['available']) {
            $this->step = 'business';
            $this->addError('slug', BusinessAddress::host($this->slug).' was just taken. Choose another address.');

            return;
        }

        try {
            $operator = $create->handle([
                'name' => $this->name,
                'slug' => $this->slug,
                'city' => $this->city,
                'type' => self::KINDS[$this->kind][1]->value,
                'expected_units' => self::SIZES[$this->size],
                'manager_name' => $this->managerName,
                'manager_email' => $this->email,
                'manager_phone' => $this->phone,
                'manager_password_hash' => $passwordHash,
            ]);
        } catch (QueryException) {
            // Unique slug: someone took it between the check and the insert.
            $this->step = 'business';
            $this->addError('slug', BusinessAddress::host($this->slug).' was just taken. Choose another address.');

            return;
        }

        session()->forget('signup.password_hash');
        session()->put('signup.operator_id', $operator->id);
        Mail::to($operator->email)->send(new OperatorWelcomeMail($operator, $operator->tenant));

        $this->host = BusinessAddress::host($this->slug);
        $this->step = 'done';
    }

    public function back(string $to): void
    {
        if (in_array($to, ['business', 'account'], true)) {
            $this->step = $to;
        }
    }

    public function render(): View
    {
        $check = $this->step === 'business' && $this->slug !== '' ? app(BusinessAddress::class)->check($this->slug) : null;

        return view('livewire.platform.sign-up', [
            'addressCheck' => $check,
            'previewHost' => BusinessAddress::host($this->slug !== '' ? $this->slug : 'your-business'),
            'kindLabel' => self::KINDS[$this->kind][0] ?? '',
        ])->title('Create your '.config('brand.name').' account');
    }

    private function sendCode(SignUpCode $codes): bool
    {
        $wait = $codes->waitBeforeResend($this->email);
        if ($wait > 0) {
            $this->codeError = "We just sent a code. You can ask for another in {$wait} seconds.";

            return $this->step === 'code' || $this->step === 'account';
        }

        $key = 'signup-codes:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('email', 'Too many codes sent from here. Try again in an hour.');

            return false;
        }
        RateLimiter::hit($key, 3600);

        Mail::to($this->email)->send(new SignUpCodeMail($this->managerName, $codes->issue($this->email), $this->name));
        $this->codeError = '';

        return true;
    }
}
