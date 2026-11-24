<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\OpeningBalanceRequest;
use App\Http\Requests\Settings\StoreBankAccountRequest;
use App\Http\Requests\Settings\UpdateBankAccountRequest;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\CashOpening;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CashSettingsController extends Controller
{
    private const string DUPLICATE_ACCOUNT_NAME = 'Ya existe una cuenta con este nombre en el banco seleccionado.';

    public function edit(): Response
    {
        $cashOpening = CashOpening::query()->sole();

        return Inertia::render('settings/Cash', [
            'cashOpening' => [
                'balanceCents' => $cashOpening->opening_balance_cents,
                'locked' => $cashOpening->opening_locked_at !== null,
            ],
        ]);
    }

    public function editBanks(): Response
    {
        return Inertia::render('settings/Banks', [
            'banks' => Bank::query()->orderBy('name')->get(['id', 'name', 'is_active'])
                ->map(fn (Bank $bank): array => [
                    'id' => $bank->id,
                    'name' => $bank->name,
                    'active' => $bank->is_active,
                ]),
            'accounts' => BankAccount::query()->with('bank:id,name,is_active')->orderBy('name')->get()
                ->map(fn (BankAccount $account): array => $this->accountData($account)),
        ]);
    }

    public function updateOpening(OpeningBalanceRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $opening = CashOpening::query()->lockForUpdate()->sole();
            $this->setOpeningBalance($opening, $request->balanceCents());
            $opening->save();
        }, attempts: 3);

        return $this->saved('cash-settings.edit', 'Saldo inicial de caja guardado.');
    }

    public function storeBank(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $name = Str::squish($data['name']);
        $bank = Bank::query()->createOrFirst(['name_key' => Str::lower($name)], ['name' => $name]);

        if (! $bank->wasRecentlyCreated) {
            throw ValidationException::withMessages(['name' => 'Este banco ya está registrado.']);
        }

        return $this->saved('bank-settings.edit', 'Banco agregado al catálogo.');
    }

    public function updateBankStatus(Request $request, Bank $bank): RedirectResponse
    {
        $request->validate(['is_active' => ['required', 'boolean']]);
        $active = $request->boolean('is_active');
        Bank::query()->whereKey($bank->id)->update(['is_active' => $active]);

        return $this->saved('bank-settings.edit', $active ? 'Banco activado.' : 'Banco desactivado.');
    }

    public function createAccount(): Response
    {
        return Inertia::render('settings/BankAccountForm', [
            'account' => null,
            'banks' => Bank::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function editAccount(BankAccount $account): Response
    {
        return Inertia::render('settings/BankAccountForm', [
            'account' => $this->accountData($account->load('bank:id,name,is_active')),
            'banks' => [],
        ]);
    }

    public function storeAccount(StoreBankAccountRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $name = Str::squish($data['name']);
        DB::transaction(function () use ($data, $name, $request): void {
            $bank = Bank::query()->whereKey($data['bank_id'])->lockForUpdate()->firstOrFail();

            if (! $bank->is_active) {
                throw ValidationException::withMessages(['bank_id' => 'Selecciona un banco activo.']);
            }

            $account = BankAccount::query()->createOrFirst([
                'bank_id' => $bank->id,
                'name_key' => Str::lower($name),
            ], [
                'name' => $name,
                'opening_balance_cents' => $request->balanceCents(),
            ]);

            if (! $account->wasRecentlyCreated) {
                throw ValidationException::withMessages(['name' => self::DUPLICATE_ACCOUNT_NAME]);
            }
        }, attempts: 3);

        return $this->saved('bank-settings.edit', 'Cuenta bancaria agregada.');
    }

    public function updateAccount(UpdateBankAccountRequest $request, BankAccount $account): RedirectResponse
    {
        $data = $request->validated();
        $balanceCents = isset($data['opening_balance']) ? $request->balanceCents() : null;

        try {
            DB::transaction(function () use ($account, $data, $balanceCents): void {
                $account = BankAccount::query()->lockForUpdate()->findOrFail($account->id);

                if (isset($data['name'])) {
                    $name = Str::squish($data['name']);
                    $account->fill(['name' => $name, 'name_key' => Str::lower($name)]);
                }

                if ($balanceCents !== null && $balanceCents !== $account->opening_balance_cents) {
                    $this->setOpeningBalance($account, $balanceCents);
                }

                $account->save();
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => self::DUPLICATE_ACCOUNT_NAME]);
        }

        return $this->saved('bank-settings.edit', 'Cuenta bancaria actualizada.');
    }

    /** @return array{id: int, name: string, bankName: string, bankActive: bool, balanceCents: int, locked: bool} */
    private function accountData(BankAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'bankName' => $account->bank->name,
            'bankActive' => $account->bank->is_active,
            'balanceCents' => $account->opening_balance_cents,
            'locked' => $account->opening_locked_at !== null,
        ];
    }

    private function setOpeningBalance(CashOpening|BankAccount $opening, int $balanceCents): void
    {
        if ($opening->opening_locked_at !== null) {
            throw ValidationException::withMessages([
                'opening_balance' => 'El saldo inicial ya se usó en un movimiento.',
            ]);
        }

        $opening->opening_balance_cents = $balanceCents;
    }

    private function saved(string $route, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route($route);
    }
}
