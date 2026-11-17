{{-- Compétitions où la personne (ou l'Espace) participe : en cours / terminées, avec son résultat --}}
@php $pp = $s['participations'] ?? null; @endphp
@if ($pp)
<section class="card part-box" data-tabs>
    <div class="part-head">
        <h2>Compétitions</h2>
        <nav class="part-tabs" role="tablist">
            <button type="button" role="tab" class="on" data-tab="live" aria-selected="true">En cours <b>{{ $pp['live_count'] }}</b></button>
            <button type="button" role="tab" data-tab="done" aria-selected="false">Terminées <b>{{ $pp['done_count'] }}</b></button>
        </nav>
    </div>
    @foreach (['live' => 'Aucune compétition en cours.', 'done' => 'Aucune compétition terminée.'] as $state => $empty)
        <ul class="part-list" data-pane="{{ $state }}" @if($state === 'done') hidden @endif>
            @forelse ($pp[$state] as $c)
                <li><a href="{{ $c['url'] }}">
                    <span class="part-type">{{ $c['type_label'] }}</span>
                    <span class="part-title">{{ $c['title'] }}</span>
                    <span class="part-res tone-{{ $c['result']['tone'] }}">{{ $c['result']['label'] }}</span>
                </a></li>
            @empty
                <li class="part-empty">{{ ! $pp['live_count'] && ! $pp['done_count'] ? 'Pas encore de participation. Relève un défi ou inscris-toi à un concours : il apparaîtra ici.' : $empty }}</li>
            @endforelse
        </ul>
    @endforeach
</section>
@endif
