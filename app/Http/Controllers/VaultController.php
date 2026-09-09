<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Vault;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class VaultController extends Controller
{
    /**
     * GET /vaults/my-vaults
     * Lista todos os cofres de todas as contas do utilizador autenticado.
     */
    public function myVaults(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        // Vai buscar todas as contas do utilizador e carrega os cofres de cada uma
        $accounts = $user->accounts()->with('vaults')->get();

        $allVaults = collect();

        foreach ($accounts as $account) {
            foreach ($account->vaults as $vault) {
                $allVaults->push([
                    'vault_id' => $vault->id,
                    'name' => $vault->name,
                    'currency' => $vault->currency,
                    'balance' => $vault->balance,
                    'formatted_balance' => number_format($vault->balance, 2) . ' ' . $vault->currency,
                    'target_amount' => $vault->target_amount,
                    'spare_change_active' => $vault->spare_change_active,
                    'associated_account' => [
                        'account_id' => $account->id,
                        'account_number' => $account->account_number,
                        'account_currency' => $account->currency ?? 'EUR'
                    ]
                ]);
            }
        }

        return response()->json([
            'message' => 'Cofres recuperados com sucesso.',
            'total_vaults' => $allVaults->count(),
            'owner_name' => $user->name,
            'vaults' => $allVaults
        ], 200);
    }

    /**
     * POST /accounts/{accountId}/vaults
     * Cria um novo cofre (com herança de moeda opcional)
     */
    public function store(Request $request, $accountId)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'target_amount' => 'nullable|numeric|gt:0',
            'currency' => 'nullable|string|size:3' 
        ]);

        $account = Account::with('users')->findOrFail($accountId);
        
        if (!$account->users()->where('user_id', $request->user()->id)->exists()) {
            return response()->json(['error' => 'Access Denied'], 403);
        }

        $vaultCurrency = strtoupper($request->input('currency', $account->currency ?? 'EUR'));

        $vault = Vault::create([
            'account_id' => $account->id,
            'name' => $request->name,
            'currency' => $vaultCurrency,
            'target_amount' => $request->target_amount,
            'spare_change_active' => false,
        ]);

        $owner = $account->users->first();
        $ownerName = $owner ? $owner->name : 'Desconhecido';

        return response()->json([
            'message' => 'Cofre criado com sucesso!', 
            'vault' => $vault,
            'associated_account' => [
                'account_number' => $account->account_number,
                'owner_name' => $ownerName
            ],
            'formatted_info' => 'Cofre "' . $vault->name . '" configurado em ' . $vault->currency
        ], 201);
    }

    /**
     * POST /vaults/{id}/deposit
     */
    public function deposit(Request $request, $id)
    {
        $request->validate(['amount' => 'required|numeric|gt:0']);
        
        $vaultAmount = (float) $request->amount; 
        
        /** @var \App\Models\User $user */
        $user = $request->user();

        return DB::transaction(function () use ($id, $vaultAmount, $user) {
            $vault = Vault::lockForUpdate()->findOrFail($id);
            $account = Account::lockForUpdate()->findOrFail($vault->account_id);

            if (!$account->users()->where('user_id', $user->id)->exists()) {
                return response()->json(['error' => 'Access Denied'], 403);
            }

            $vaultCurrency = $vault->currency;
            $accountCurrency = $account->currency ?? 'EUR';

            $amountToDeductFromAccount = $vaultAmount;
            $appliedRate = 1.00;

            if ($vaultCurrency !== $accountCurrency) {
                $cacheKey = "exchange_rate_{$vaultCurrency}_{$accountCurrency}";
                $appliedRate = Cache::remember($cacheKey, 3600, function () use ($vaultCurrency, $accountCurrency) {
                    $response = Http::get("https://api.frankfurter.app/latest", ['from' => $vaultCurrency, 'to' => $accountCurrency]);
                    if ($response->successful()) return $response->json()['rates'][$accountCurrency];
                    abort(503, 'Serviço de câmbios indisponível.');
                });
                $amountToDeductFromAccount = $vaultAmount * $appliedRate;
            }

            if ($account->balance < $amountToDeductFromAccount) {
                return response()->json(['error' => 'Saldo insuficiente na conta principal.'], 422);
            }

            $account->balance -= $amountToDeductFromAccount;
            $vault->balance += $vaultAmount;
            
            $account->save();
            $vault->save();

            $reference = 'VLT-DEP-' . strtoupper(Str::random(6)) . '-' . time();
            Transaction::create([
                'account_id' => $account->id,
                'user_id' => $user->id,
                'reference' => $reference,
                'type' => 'VAULT_FUNDING',
                'amount' => $amountToDeductFromAccount,
                'original_amount' => $vaultAmount,
                'original_currency' => $vaultCurrency,
                'balance_after' => $account->balance,
            ]);

            return response()->json([
                'message' => 'Dinheiro transferido da Conta Principal para o Cofre de Poupança com sucesso!', 
                'details' => [
                    'from_account' => $account->account_number,
                    'to_vault' => $vault->name,
                ],
                'vault_balance' => number_format($vault->balance, 2) . ' ' . $vaultCurrency,
                'account_debited' => number_format($amountToDeductFromAccount, 2) . ' ' . $accountCurrency
            ], 200);
        });
    }

    /**
     * POST /vaults/{id}/withdraw
     */
    public function withdraw(Request $request, $id)
    {
        $request->validate(['amount' => 'required|numeric|gt:0']);
        $vaultAmountToWithdraw = (float) $request->amount;
        
        /** @var \App\Models\User $user */
        $user = $request->user();

        return DB::transaction(function () use ($id, $vaultAmountToWithdraw, $user) {
            $vault = Vault::lockForUpdate()->findOrFail($id);
            $account = Account::lockForUpdate()->findOrFail($vault->account_id);

            if (!$account->users()->where('user_id', $user->id)->exists()) {
                return response()->json(['error' => 'Access Denied'], 403);
            }

            if ($vault->balance < $vaultAmountToWithdraw) {
                return response()->json(['error' => 'Saldo insuficiente no cofre.'], 422);
            }

            $vaultCurrency = $vault->currency;
            $accountCurrency = $account->currency ?? 'EUR';

            $amountToAddToAccount = $vaultAmountToWithdraw;
            $appliedRate = 1.00;

            if ($vaultCurrency !== $accountCurrency) {
                $cacheKey = "exchange_rate_{$vaultCurrency}_{$accountCurrency}";
                $appliedRate = Cache::remember($cacheKey, 3600, function () use ($vaultCurrency, $accountCurrency) {
                    $response = Http::get("https://api.frankfurter.app/latest", ['from' => $vaultCurrency, 'to' => $accountCurrency]);
                    if ($response->successful()) return $response->json()['rates'][$accountCurrency];
                    abort(503, 'Serviço de câmbios indisponível.');
                });
                $amountToAddToAccount = $vaultAmountToWithdraw * $appliedRate;
            }

            $vault->balance -= $vaultAmountToWithdraw;
            $account->balance += $amountToAddToAccount;
            
            $vault->save();
            $account->save();

            $reference = 'VLT-WTH-' . strtoupper(Str::random(6)) . '-' . time();
            Transaction::create([
                'account_id' => $account->id,
                'user_id' => $user->id,
                'reference' => $reference,
                'type' => 'VAULT_WITHDRAWAL',
                'amount' => $amountToAddToAccount,
                'original_amount' => $vaultAmountToWithdraw,
                'original_currency' => $vaultCurrency,
                'balance_after' => $account->balance,
            ]);

            return response()->json([
                'message' => 'Dinheiro resgatado do Cofre para a Conta Principal com sucesso!', 
                'details' => [
                    'from_vault' => $vault->name,
                    'to_account' => $account->account_number,
                ],
                'vault_balance' => number_format($vault->balance, 2) . ' ' . $vaultCurrency,
                'account_credited' => number_format($amountToAddToAccount, 2) . ' ' . $accountCurrency
            ], 200);
        });
    }

    /**
     * PATCH /vaults/{id}/spare-change
     * Liga ou desliga o arredondamento automático nas compras para este cofre.
     */
    public function toggleSpareChange(Request $request, $id)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $vault = Vault::with('account')->findOrFail($id);
        $account = $vault->account;

        if (!$account->users()->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'Access Denied'], 403);
        }

        // Só podemos ter o Spare Change ativo num cofre de cada vez
        // Se estivermos a ligar este, desligamos nos outros todos que pertencem à  mesma conta.
        if (!$vault->spare_change_active) {
            Vault::where('account_id', $account->id)->update(['spare_change_active' => false]);
        }

        // Inverte o estado atual (se estava true fica false, se estava false fica true)
        $vault->spare_change_active = !$vault->spare_change_active;
        $vault->save();

        $status = $vault->spare_change_active ? 'ATIVADO' : 'DESATIVADO';

        return response()->json([
            'message' => "Arredondamento automático (Spare Change) $status para o cofre '{$vault->name}'.",
            'spare_change_active' => $vault->spare_change_active
        ], 200);
    }
}