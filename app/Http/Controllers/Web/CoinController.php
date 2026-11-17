<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\CompetitionException;
use App\Http\Controllers\Controller;
use App\Models\CoinPurchase;
use App\Models\CoinTransaction;
use App\Models\CoinWithdrawal;
use App\Models\Space;
use App\Models\User;
use App\Services\Coins\CoinService;
use App\Services\Coins\PaymentProvider;
use Illuminate\Http\Request;

/** Portefeuille de coins : solde, achat de packs, historique, cadeaux. */
class CoinController extends Controller
{
    public function __construct(private CoinService $coins)
    {
    }

    public function wallet(Request $request)
    {
        $u = $request->user();
        // Un achat en attente : on relit son état chez SasPay (si le webhook n'est pas encore passé)
        $pending = CoinPurchase::where('user_id', $u->id)->where('status', 'pending')->latest('id')->first();
        if ($pending && $pending->provider === 'saspay' && $pending->created_at?->lt(now()->subSeconds(15))) {
            app(\App\Services\Coins\SasPayPaymentProvider::class)->verify($pending);
        }

        return view('web.pages.wallet', [
            'current' => 'wallet', 'title' => 'Mon portefeuille', 'hideRail' => true,
            'balance' => $this->coins->balance($u),
            'earned' => $this->coins->earned($u),
            'withdrawable' => $this->coins->withdrawable($u),
            'withdrawals' => CoinWithdrawal::where('user_id', $u->id)->latest('id')->limit(5)->get(),
            'cashout' => config('coins.cashout'),
            'operators' => CoinWithdrawal::OPERATORS,
            'section' => in_array($request->query('v'), ['recharger', 'retirer'], true) ? $request->query('v') : 'recharger',
            'packs' => config('coins.packs'),
            'currency' => config('coins.currency'),
            'history' => CoinTransaction::where('user_id', $u->id)->with('counterparty')->latest('id')->limit(50)->get(),
            'pending' => CoinPurchase::where('user_id', $u->id)->where('status', 'pending')->latest('id')->first(),
            'testMode' => config('coins.provider') === 'test' && ! app()->environment('production'),
            'networks' => config('coins.provider') === 'saspay' ? ['mpesa' => 'M-Pesa', 'orange' => 'Orange Money', 'airtel' => 'Airtel Money'] : [],
        ]);
    }

    public function buy(Request $request, PaymentProvider $provider)
    {
        $saspay = config('coins.provider') === 'saspay';
        $data = $request->validate([
            'pack' => 'required|string',
            'phone' => ($saspay ? 'required' : 'nullable').'|string|max:30',
            'operator' => ($saspay ? 'required|in:mpesa,orange,airtel' : 'nullable|string|max:20'),
        ], ['phone.required' => 'Indique ton numéro mobile money.', 'operator.required' => 'Choisis M-Pesa, Orange Money ou Airtel Money.']);
        $pack = collect(config('coins.packs'))->firstWhere('code', $data['pack']);
        abort_unless($pack, 422);
        $purchase = CoinPurchase::create([
            'user_id' => $request->user()->id, 'pack' => $pack['code'], 'coins' => $pack['coins'], 'price' => $pack['price'],
            'currency' => config('coins.currency'), 'provider' => (string) config('coins.provider'), 'status' => 'pending',
            'phone' => $data['phone'] ?? null, 'operator' => $data['operator'] ?? null,
        ]);
        $redirect = $provider->start($purchase);
        if ($redirect) {
            return $request->expectsJson() ? response()->json(['redirect' => $redirect]) : redirect()->away($redirect);
        }
        $paid = $purchase->fresh()->status === 'paid';

        return $this->back($request, $paid ? $pack['coins'].' coins ajoutés à ton portefeuille.' : 'Paiement lancé : valide-le sur ton téléphone avec ton code PIN. Tes coins arrivent dès la confirmation.');
    }

    /** Rappel de SasPay (paiement confirmé ou refusé). */
    public function saspayWebhook(Request $request)
    {
        $ok = app(\App\Services\Coins\SasPayPaymentProvider::class)->webhook($request);

        return response()->json(['received' => $ok], $ok ? 200 : 401);
    }

    /** Retirer ses gains vers mobile money. */
    public function withdraw(Request $request)
    {
        $data = $request->validate([
            'coins' => 'required|integer|min:1',
            'operator' => 'required|in:'.implode(',', array_keys(CoinWithdrawal::OPERATORS)),
            'phone' => 'required|string|min:9|max:30',
        ], ['coins.required' => 'Indique combien de coins retirer.', 'phone.required' => 'Indique le numéro qui reçoit l\'argent.']);
        $w = $this->coins->requestWithdrawal($request->user(), (int) $data['coins'], $data['operator'], $data['phone']);
        $msg = 'Retrait demandé : '.number_format($w->amount, 0, ',', ' ').' FC arrivent sur ton '.CoinWithdrawal::OPERATORS[$w->operator].'.';

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $msg, 'balance' => $this->coins->balance($request->user())])
            : redirect('/portefeuille?v=retirer')->with('ok', $msg);
    }

    /** Offrir des coins à une personne ou un Espace. */
    public function gift(Request $request)
    {
        $data = $request->validate([
            'to_type' => 'required|in:user,space',
            'to_id' => 'required|integer',
            'amount' => 'required|integer|min:1|max:100000',
            'message' => 'nullable|string|max:140',
            'competition_id' => 'nullable|integer',
        ], ['amount.required' => 'Choisis un nombre de coins.']);
        $recipient = $data['to_type'] === 'space' ? Space::findOrFail($data['to_id']) : User::findOrFail($data['to_id']);
        $context = ! empty($data['competition_id']) ? \App\Models\Competition::find($data['competition_id']) : null;
        try {
            $this->coins->gift($request->user(), $recipient, (int) $data['amount'], $data['message'] ?? null, $context);
        } catch (CompetitionException $e) {
            throw $e;
        }
        $balance = $this->coins->balance($request->user());

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => 'Cadeau envoyé : '.$data['amount'].' coins.', 'balance' => $balance])
            : back()->with('ok', 'Cadeau envoyé : '.$data['amount'].' coins.');
    }

    private function back(Request $request, string $message)
    {
        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message, 'balance' => $this->coins->balance($request->user())])
            : redirect('/portefeuille')->with('ok', $message);
    }
}
