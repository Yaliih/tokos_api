<section class="comments {{ ($inSheet ?? false) ? 'in-sheet' : '' }}" @unless($inSheet ?? false) id="commentaires" @endunless>
    
    @auth
        @php $duels = $duels ?? []; $duel = $duel ?? null; @endphp
        <form method="post" action="/c/{{ $c['slug'] }}/commenter" class="comment-form has-duel" data-cm-form data-me-name="{{ trim(auth()->user()->first_name.' '.auth()->user()->last_name) }}">@csrf
            {{-- Le duel visé (un défi en a plusieurs) : choisi depuis la liste, ou celui d'où l'on vient --}}
            <input type="hidden" name="match_id" value="{{ $duel['id'] ?? '' }}" data-cm-duel-id>
            <div class="cm-duel-pick" data-cm-duel-chip @unless($duel) hidden @endunless>
                @icon('vs', 'ico-sm')<span>À propos du duel <b data-cm-duel-label>{{ $duel['label'] ?? '' }}</b></span>
                <button type="button" class="icon-btn" data-cm-duel-clear aria-label="Ne plus viser ce duel">@icon('x', 'ico-sm')</button>
            </div>
            <div class="cm-row">
                @include('web.partials.avatar', ['p' => \App\Support\Participant::present(auth()->user()), 'size' => 'sm'])
                <label class="sr-only" for="{{ ($inSheet ?? false) ? 'cs-input' : 'comment' }}">Ton commentaire</label>
                <input class="input" id="{{ ($inSheet ?? false) ? 'cs-input' : 'comment' }}" name="content" maxlength="1000" placeholder="Écris un commentaire…" style="flex:1" required data-emoji>
                @if (count($duels))
                    <button type="button" class="icon-btn" data-cm-duel-open aria-label="Viser un duel" title="Viser un duel">@icon('vs')</button>
                @endif
                <button class="icon-btn send" aria-label="Publier">@icon('send')</button>
            </div>
            @if (count($duels))
                <div class="cm-duels" data-cm-duels hidden>
                    <p class="cm-duels-head">De quel duel parles-tu ?</p>
                    <ul>
                        @foreach ($duels as $d)
                            <li><button type="button" data-cm-duel="{{ $d['id'] }}" data-label="{{ $d['label'] }}">@icon('vs', 'ico-sm')<span><b>{{ $d['a'] }}</b> contre <b>{{ $d['b'] }}</b></span></button></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </form>
    @else
        <p class="muted"><a href="/connexion?suite={{ urlencode($c['url']) }}" style="color:var(--primary);font-weight:700">Connecte-toi</a> pour commenter.</p>
    @endauth
    {{-- Commentaires : dix visibles, le reste derrière « Voir plus ». Les réponses sont repliées. --}}
    @foreach ($comments as $k => $cm)
        <div class="thread" @if($k >= 10) hidden data-more-item="comments" @endif>
            @include('web.partials.comment', ['cm' => $cm, 'slug' => $c['slug']])
            @if ($cm->replies->isNotEmpty())
                <button type="button" class="more-replies" data-toggle-replies="replies-{{ $cm->id }}" aria-expanded="false">
                    <span class="line"></span><span data-label-closed>Voir {{ $cm->replies->count() > 1 ? 'les '.$cm->replies->count().' réponses' : 'la réponse' }}</span><span data-label-open hidden>Masquer les réponses</span>@icon('chevron-down', 'ico-sm')</button>
                <div class="replies" id="replies-{{ $cm->id }}" hidden>
                    @foreach ($cm->replies as $r)@include('web.partials.comment', ['cm' => $r, 'slug' => $c['slug'], 'replyTo' => $cm->id])@endforeach
                </div>
            @endif
        </div>
    @endforeach
    @if (count($comments) === 0)<p class="cs-empty">@icon('comment')<span>Aucun commentaire. Sois le premier à réagir.</span></p>@endif
    @if (count($comments) > 10)
        <button type="button" class="btn btn-ghost btn-sm more-comments" data-more="comments">Voir plus de commentaires ({{ count($comments) - 10 }})</button>
    @endif
</section>