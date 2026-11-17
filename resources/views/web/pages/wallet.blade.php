@extends('web.layout')
@section('content')
{{-- Portefeuille : solde et gains, recharger ou retirer, historique --}}
@php $fc = fn ($n) => number_format($n, 0, ',', ' '); $me = auth()->user(); @endphp
<div class="back-line"><a href="/@{{ $me->username }}">@icon('arrow-left', 'ico-sm')<span>Mon portefeuille</span></a></div>
<section class="wallet-hero card">
    <span class="wallet-coin">@icon('coin')</span>
    <div><b class="wallet-balance" data-coin-balance-full>{{ $fc($balance) }}</b><span>coins</span></div>
    <div class="wallet-stats">
        <span><b>{{ $fc($earned) }}</b>gagnés</span>
        @feature('withdrawals')<span><b>{{ $fc($withdrawable) }}</b>retirables</span>@endfeature
    </div>
</section>

@if ($pending)
    <p class="mini-note">@icon('clock', 'ico-sm')Achat de {{ $pending->coins }} coins en attente de paiement.</p>
@endif

@php if (\App\Support\Features::off('withdrawals')) $section = 'recharger'; @endphp
@feature('withdrawals')
<nav class="seg" aria-label="Portefeuille">
    <a href="?v=recharger" @if($section === 'recharger') aria-current="page" @endif>@icon('plus', 'ico-sm')Recharger</a>
    <a href="?v=retirer" @if($section === 'retirer') aria-current="page" @endif>@icon('arrow-down', 'ico-sm')Retirer</a>
</nav>
@else
<div style="height:14px"></div>
@endfeature

@if ($section === 'recharger' && \App\Support\Features::off('buy_coins_web'))
<p class="muted" style="padding:16px 4px">L'achat de coins n'est pas ouvert pour le moment.</p>
@elseif ($section === 'recharger')
<form method="post" action="/portefeuille/acheter" class="packs" data-buy-form>@csrf
    @foreach ($packs as $i => $p)
        <label class="pack {{ !empty($p['bonus']) ? 'is-best' : '' }}">
            <input type="radio" name="pack" value="{{ $p['code'] }}" @checked($i === 1) required>
            <span class="pack-box">
                @if (!empty($p['bonus']))<em>{{ $p['bonus'] }}</em>@endif
                <span class="pack-coins">@icon('coin', 'ico-sm'){{ $fc($p['coins']) }}</span>
                <span class="pack-price">{{ $fc($p['price']) }} {{ $currency === 'CDF' ? 'FC' : $currency }}</span>
            </span>
        </label>
    @endforeach
    @if (count($networks))
        <div class="field"><span>Payer avec</span>
            <div class="ops">@foreach ($networks as $k => $label)<label class="op"><input type="radio" name="operator" value="{{ $k }}" @checked($loop->first) required><span>{{ $label }}</span></label>@endforeach</div></div>
    @endif
    <label class="field pack-phone"><span>Numéro mobile money</span>
        <span class="input-icon">@icon('phone')<input class="input" name="phone" inputmode="tel" placeholder="0812345678" value="{{ $me->phone_number }}" @if(count($networks)) required @endif></span></label>
    <button class="btn btn-lg btn-block">@icon('plus', 'ico-sm')Recharger</button>
    @if (count($networks))<p class="faint small" style="margin:8px 0 0;text-align:center">Tu reçois une demande sur ton téléphone : valide-la avec ton code PIN. Paiement sécurisé par SasPay.</p>@endif
    @if ($testMode)<p class="faint small" style="margin:8px 0 0;text-align:center">Mode test : les coins sont ajoutés tout de suite, sans paiement.</p>@endif
</form>
@else
<form method="post" action="/portefeuille/retirer" class="card cashout" data-cashout data-rate="{{ $cashout['rate'] }}">@csrf
    <p class="muted small" style="margin:0 0 14px">Les coins que tu gagnes (votes et cadeaux reçus) se changent en argent : 1 coin = {{ $cashout['rate'] }} FC. Minimum {{ $cashout['min'] }} coins.</p>
    @if ($withdrawable < $cashout['min'])
        <div class="cashout-empty">@icon('trophy')<span>Encore {{ $cashout['min'] - $withdrawable }} coins à gagner avant ton premier retrait. Participe, fais-toi soutenir.</span></div>
    @else
        <label class="field"><span>Coins à retirer</span>
            <span class="input-icon">@icon('coin')<input class="input" type="number" name="coins" min="{{ $cashout['min'] }}" max="{{ $withdrawable }}" value="{{ $withdrawable }}" required data-cashout-coins></span></label>
        <div class="coin-total"><span>Tu reçois</span><b><span data-cashout-amount>{{ $fc($withdrawable * $cashout['rate']) }}</span> FC</b></div>
        <span class="step-label" style="margin-top:14px">Vers</span>
        <div class="ops">
            @foreach ($operators as $k => $label)
                <label class="op"><input type="radio" name="operator" value="{{ $k }}" @checked($loop->first) required><span>{{ $label }}</span></label>
            @endforeach
        </div>
        <label class="field" style="margin-top:12px"><span>Numéro qui reçoit</span>
            <span class="input-icon">@icon('phone')<input class="input" name="phone" inputmode="tel" required value="{{ $me->phone_number }}"></span></label>
        <button class="btn btn-lg btn-block">@icon('arrow-down', 'ico-sm')Retirer</button>
    @endif
    @if ($withdrawals->isNotEmpty())
        <ul class="coin-history compact">
            @foreach ($withdrawals as $w)
                <li><span class="ch-ico out">@icon('arrow-down', 'ico-sm')</span>
                    <span class="grow"><b>{{ $fc($w->amount) }} FC</b><small>{{ $operators[$w->operator] ?? $w->operator }} {{ $w->phone }}</small></span>
                    <span class="w-status {{ $w->status }}">{{ ['pending' => 'En cours', 'paid' => 'Versé', 'rejected' => 'Refusé'][$w->status] ?? $w->status }}</span></li>
            @endforeach
        </ul>
    @endif
</form>
@endif

<h2 class="wallet-h">Historique</h2>
<ul class="coin-history">
    @forelse ($history as $t)
        <li>
            <span class="ch-ico {{ $t->amount > 0 ? 'in' : 'out' }}">@icon(['purchase' => 'plus', 'vote' => 'arrow-up', 'vote_income' => 'arrow-up', 'gift_sent' => 'gift', 'gift_received' => 'gift', 'bonus' => 'sparkles', 'withdrawal' => 'arrow-down', 'refund' => 'refresh'][$t->type] ?? 'coin', 'ico-sm')</span>
            <span class="grow"><b>{{ \App\Models\CoinTransaction::LABELS[$t->type] ?? $t->type }}</b><small>{{ $t->description }}@if(!empty($t->meta['message'])) « {{ $t->meta['message'] }} »@endif</small></span>
            <span class="ch-amount {{ $t->amount > 0 ? 'in' : 'out' }}">{{ $t->amount > 0 ? '+' : '' }}{{ $t->amount }}</span>
        </li>
    @empty
        <li class="empty-line">Pas encore de mouvement.</li>
    @endforelse
</ul>
@endsection
