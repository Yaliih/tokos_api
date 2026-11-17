{{-- Un commentaire : j'aime et réponse. $cm (PostComment avec likes_count, liked), $slug, $replyTo (id du commentaire principal) --}}
@php $root = $replyTo ?? $cm->id; @endphp
<div class="comment" id="commentaire-{{ $cm->id }}">
    @include('web.partials.avatar', ['p' => \App\Support\Participant::present($cm->author), 'size' => 'sm'])
    <div class="grow">
        @php $cmDuel = \App\Support\CommentText::duel($cm->match); @endphp
        <div class="bubble"><a class="cm-author" href="{{ \App\Support\Participant::present($cm->author)['url'] ?? '#' }}">{{ $cm->author?->first_name }} {{ $cm->author?->last_name }}</a>
            @if ($cmDuel)<a class="cm-duel" href="{{ $cmDuel['url'] }}">@icon('vs', 'ico-sm')<span>Duel : <b>{{ $cmDuel['a'] }}</b> contre <b>{{ $cmDuel['b'] }}</b></span></a>@endif
            <span class="cm-text">{!! \App\Support\CommentText::html((string) $cm->content) !!}</span></div>
        <div class="comment-actions">
            <span class="faint">{{ \App\Support\Presenter::ago($cm->created_at) }}</span>
            @auth
                <form method="post" action="/commentaires/{{ $cm->id }}/aimer" data-ajax data-like data-silent>@csrf
                    <button class="link-act {{ $cm->liked ? 'on' : '' }}" aria-pressed="{{ $cm->liked ? 'true' : 'false' }}">@icon('heart', 'ico-sm')<span data-like-count>{{ $cm->likes_count }}</span></button></form>
                <button type="button" class="link-act" data-reply="reply-{{ $cm->id }}">@icon('reply', 'ico-sm')Répondre</button>
                @if ($cm->user_id !== auth()->id())<button type="button" class="link-act faint" data-report-open="comment:{{ $cm->id }}" aria-label="Signaler ce commentaire">@icon('flag', 'ico-sm')</button>@endif
            @else
                <span class="link-act">@icon('heart', 'ico-sm'){{ $cm->likes_count }}</span>
            @endauth
        </div>
        @auth
            <form method="post" action="/c/{{ $slug }}/commenter" class="comment-form reply-form" id="reply-{{ $cm->id }}" hidden>@csrf
                <input type="hidden" name="parent_id" value="{{ $root }}">
                <input class="input" name="content" maxlength="1000" required placeholder="Répondre à {{ $cm->author?->first_name }}…" data-emoji value="{{ $replyTo ?? false ? '@'.$cm->author?->username.' ' : '' }}" style="flex:1">
                <button class="icon-btn send" aria-label="Envoyer">@icon('send')</button>
            </form>
        @endauth
    </div>
</div>
