{{-- « Comment participer » : les étapes d'un concours ou d'un tournoi, avec les vraies dates,
     l'étape où en est le spectateur, et un seul bouton. $c : compétition, $v : spectateur --}}
@php
    $d = fn ($x) => $x ? \Illuminate\Support\Carbon::parse($x)->locale('fr')->translatedFormat('j M à H\hi') : null;
    $isT = $c['type'] === 'tournament';
    $app = (bool) ($c['application'] ?? false);
    $fmt = $c['entry_format'] ?? null;
    $what = ['video' => 'ta vidéo', 'audio' => 'ton audio', 'image' => 'ta photo', 'text' => 'ton texte'][$fmt] ?? 'ta performance';
    $regEnd = $d($c['registration_ends_at'] ?? null);
    $left = $c['max_entries'] ? max(0, $c['max_entries'] - $c['entries_count']) : null;
    $status = $v['my_entry_status'] ?? null;
    $entered = count($v['my_entry_ids'] ?? []) > 0 && ! in_array($status, ['withdrawn', 'rejected'], true);
    $pending = $entered && $status === 'pending';
    $cost = (int) ($c['vote_cost'] ?? 0);
    $jury = ($c['jury_weight'] ?? 0) > 0;
    $winners = max(1, (int) ($c['winners_count'] ?? 1));

    $steps = [];
    $steps[] = [
        'ico' => $app ? 'user' : ($isT ? 'user-plus' : (['video' => 'video', 'audio' => 'mic', 'image' => 'image', 'text' => 'type'][$fmt] ?? 'upload')),
        't' => $app ? 'Envoie ta candidature' : ($isT ? 'Inscris-toi avec '.$what : 'Envoie '.$what),
        'd' => $app ? 'Une photo et quelques mots sur toi.' : ($isT ? 'Elle te représente dans chacun de tes duels.' : 'Elle apparaît tout de suite dans les candidats.'),
    ];
    if ($app || ($c['requires_approval'] ?? false)) {
        $steps[] = ['ico' => 'check', 't' => 'L\'organisateur valide', 'd' => 'Tu reçois une notification dès que c\'est fait.'];
    }
    if ($isT) {
        $steps[] = ['ico' => 'shuffle', 't' => 'Tirage au sort', 'd' => ($regEnd ? 'Le '.$regEnd : 'Au lancement').', tu découvres ton adversaire.'];
        $steps[] = ['ico' => 'vs', 't' => 'Gagne tes duels', 'd' => 'Un duel de '.($c['match_duration_hours'] ?? 24).' h par tour. Le public vote, le gagnant passe au tour suivant.'];
    } else {
        $steps[] = ['ico' => 'arrow-up', 't' => 'Le public vote', 'd' => trim(($regEnd ? 'À partir du '.$regEnd.'. ' : 'Dès l\'ouverture des votes. ').($jury ? 'Le jury note aussi. ' : '').'Partage ton lien pour récolter des voix.')];
        $steps[] = ['ico' => 'trophy', 't' => $winners > 1 ? 'Les '.$winners.' premiers gagnent' : 'Le plus voté gagne', 'd' => $c['prize'] ? $c['prize'] : ($c['ends_at'] ? 'Résultat le '.$d($c['ends_at']).'.' : 'Le classement final s\'affiche à la fin.')];
    }
    $hasCheck = $app || ($c['requires_approval'] ?? false);
    $current = ! $entered ? 0 : ($pending ? 1 : ($hasCheck ? 2 : 1));

    $label = $app ? 'Candidater' : ($isT ? 'S\'inscrire' : 'Participer');
    $blocked = null;
    $geoCity = $c['geo_city'] ?? null;
    $geoEnter = $geoCity && in_array($c['geo_scope'] ?? 'both', ['enter', 'both'], true);
    if (! $entered && ! empty($v['geo_wrong_city']) && $geoEnter) {
        $blocked = 'Réservé aux personnes à '.$geoCity.'. Ta position indique '.$v['geo_wrong_city'].'.';
    } elseif (! $entered && ($c['is_over'] ?? false)) {
        $blocked = $c['status'] === 'cancelled' ? 'Annulé : on ne peut plus participer.' : 'C\'est terminé : on ne peut plus participer.';
    } elseif (! $entered && ! ($c['accepts_entries'] ?? true) && $left !== 0) {
        $blocked = 'Les inscriptions sont closes.';
    } elseif (! $entered && ! $v['can_enter'] && ! $v['login_required']) {
        $blocked = $left === 0 ? 'Complet : toutes les places sont prises.'
            : ($c['access'] === 'members' ? 'Réservé aux membres de '.($c['organizer']['name'] ?? 'l\'Espace').'.'
            : ($c['access'] === 'invited' ? 'Sur invitation uniquement.'
            : ($c['participation_mode'] === 'space' ? 'Réservé aux Espaces : crée ou gère un Espace pour participer.' : 'Les inscriptions sont fermées.')));
    }
@endphp
<section class="card join-guide" aria-labelledby="jg-title">
    <div class="jg-head">
        <h2 id="jg-title">{{ $entered ? ($pending ? 'Candidature envoyée' : 'Tu es inscrit') : 'Comment participer' }}</h2>
        <div class="jg-facts">
            @if ($regEnd && ! $entered)<span>@icon('clock', 'ico-sm')Jusqu'au {{ $regEnd }}</span>@endif
            @if ($geoCity)<span>@icon('map-pin', 'ico-sm'){{ $geoCity }}</span>@endif
            @if ($left !== null && ! $entered)<span class="{{ $left <= 3 ? 'hot' : '' }}">@icon('users', 'ico-sm'){{ $left }} place{{ $left > 1 ? 's' : '' }}</span>@endif
            <span>@icon($cost ? 'coin' : 'heart', 'ico-sm'){{ $cost ? $cost.' coin'.($cost > 1 ? 's' : '').' / vote' : 'Votes gratuits' }}</span>
        </div>
    </div>
    <ol class="jg-steps" style="--n: {{ count($steps) }}">
        @foreach ($steps as $i => $s)
            <li class="{{ $i < $current ? 'done' : ($i === $current ? 'now' : '') }}">
                <span class="jg-dot">@if ($i < $current)@icon('check')@else{{ $i + 1 }}@endif</span>
                <div><strong>{{ $s['t'] }}</strong><span>{{ $s['d'] }}</span></div>
            </li>
        @endforeach
    </ol>
    @if ($c['prize'] && $isT)
        <p class="jg-prize">@icon('gift', 'ico-sm'){{ $c['prize'] }}</p>
    @endif
    @if ($entered)
        <p class="jg-ok">@icon($pending ? 'clock' : 'check', 'ico-sm'){{ $pending ? 'L\'organisateur doit encore valider ta place.' : ($isT ? 'Ta place est réservée. Rendez-vous au tirage.' : 'Ta participation est en ligne. Partage-la pour récolter des voix.') }}</p>
    @elseif ($v['can_enter'] && $geoEnter && ! empty($v['geo_locate']))
        <button type="button" class="btn btn-lg jg-cta" data-locate="reload">@icon('map-pin', 'ico-sm')Vérifier ma position</button>
        <p class="jg-sub">Réservé à {{ $geoCity }} : ton téléphone confirme ta ville, puis tu peux {{ $app ? 'candidater' : 'participer' }}.</p>
    @elseif ($v['can_enter'])
        <button type="button" class="btn btn-lg jg-cta" data-open="participer" @if($inSheet ?? false) data-close @endif>@icon($app ? 'user' : 'zap', 'ico-sm'){{ $label }}</button>
    @elseif ($v['login_required'] && ($left === null || $left > 0) && ($c['accepts_entries'] ?? true) && ! ($c['is_over'] ?? false))
        <a class="btn btn-lg jg-cta" href="/connexion?suite={{ urlencode($c['url']) }}">@icon('zap', 'ico-sm'){{ $label }}</a>
        <p class="jg-sub">Crée ton compte en une minute avec ton numéro.</p>
    @elseif ($blocked)
        <p class="jg-no">@icon('info', 'ico-sm'){{ $blocked }}</p>
    @endif
</section>
