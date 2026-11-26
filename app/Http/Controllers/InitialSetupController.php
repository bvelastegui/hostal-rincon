<?php

namespace App\Http\Controllers;

use App\Actions\ConvertUsdToCents;
use App\Actions\CreateBankAccount;
use App\Http\Requests\InitialSetupRequest;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\CashOpening;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InitialSetupController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware(
                static function (Request $request, Closure $next): HttpResponse {
                    abort_unless(self::available(), 404);

                    return $next($request);
                },
                only: ['create', 'store'],
            ),
        ];
    }

    public function home(Request $request): RedirectResponse
    {
        if ($request->user()) {
            return to_route('dashboard');
        }

        return to_route(self::available() ? 'initial-setup.create' : 'login');
    }

    public function create(): Response
    {
        return Inertia::render('auth/InitialSetup', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(InitialSetupRequest $request, CreateBankAccount $createAccount): RedirectResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data, $createAccount): User {
            abort_unless(self::available(), 404);
            $opening = CashOpening::query()->lockForUpdate()->sole();
            abort_if($opening->opening_balance_cents !== null || BankAccount::query()->exists(), 409);

            $opening->opening_balance_cents = ConvertUsdToCents::fromDecimal((string) $data['opening_balance']);
            $opening->save();

            foreach ($data['accounts'] ?? [] as $index => $account) {
                $bankName = Str::squish($account['bank']);
                $bank = Bank::query()->createOrFirst(['name_key' => Str::lower($bankName)], ['name' => $bankName]);

                if (! $bank->is_active && ! $bank->wasRecentlyCreated) {
                    throw ValidationException::withMessages(["accounts.$index.bank" => 'Selecciona un banco activo.']);
                }

                $createAccount(
                    $bank,
                    $account['name'],
                    ConvertUsdToCents::fromDecimal((string) $account['opening_balance']),
                    "accounts.$index.name",
                );
            }

            $user = new User([
                'name' => $data['name'],
                'username' => $data['username'],
                'password' => $data['password'],
            ]);
            $user->is_admin = true;
            $user->save();

            return $user;
        }, attempts: 3);

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('dashboard');
    }

    private static function available(): bool
    {
        return config('app.desktop') && DB::connection()->getDriverName() === 'sqlite'
            && User::query()->doesntExist();
    }
}
