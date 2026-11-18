@extends('web.layout')
@section('content')
<article class="post">
    <header class="post-head">@include('web.partials.avatar', ['p' => $author])<div class="who-line"><a href="{{ $author['url'] ?? '#' }}">{{ $author['name'] ?? '' }}</a><div class="meta">@icon('clock'){{ \App\Support\Presenter::ago($post->published_at) }}</div></div></header>
    <div class="post-body">
        {{-- Une publication longue se replie ici aussi (douze lignes), avec « Lire plus » --}}
        <div class="post-text rm" data-rm="12"><p class="rm-txt">{{ $post->content }}</p><button type="button" class="rm-btn" data-rm-btn hidden>Voir plus</button></div>
        @foreach (\App\Support\MediaUrl::list(is_array($post->media) ? $post->media : []) as $md)<div style="border-radius:14px;overflow:hidden;margin-top:10px">@include('web.partials.media', ['media' => [$md]])</div>@endforeach
    </div>
</article>
<section class="comments">
    <h2>Commentaires</h2>
    @foreach ($comments as $cm)
        <div class="comment">@include('web.partials.avatar', ['p' => \App\Support\Participant::present($cm->author), 'size' => 'sm'])
            <div class="bubble"><strong>{{ $cm->author?->first_name }}</strong>{{ $cm->content }}</div></div>
    @endforeach
</section>
@endsection
