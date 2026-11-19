{{-- Compétitions d'un profil (compte ou Espace) : celles qu'il organise, avec l'état des
     conditions de modification. Le bloc se débrouille seul : on lui passe juste $owner. --}}
@php
    $owner = $owner ?? null;
    $isOrg = $owner && method_exists($owner, 'organizedCompetitions');
    $comps = $isOrg ? $owner->organizedCompetitions()->latest('id')->limit(9)->get() : collect();
@endphp
@if ($isOrg)
    <section class="pcomps" aria-label="Compétitions">
        <div class="pcomps-head">
            <h2>@icon('trophy', 'ico-sm')Compétitions</h2>
            @if ($comps->isNotEmpty())<a class="pcomps-all" href="/decouvrir">Tout voir</a>@endif
        </div>
        @if ($comps->isEmpty())
            <p class="pcomps-empty">Aucune compétition organisée pour l'instant.</p>
        @else
            <div class="pcomps-list">
                @foreach ($comps as $c)
                    @php
                        $n = $c->entries()->count();
                        // Conditions lisibles : on peut encore modifier tant que rien n'est lancé
                        $open = in_array($c->status, ['open', 'draft'], true);
                        $editable = $open && $n === 0;
                    @endphp
                    <a class="pcomp" href="/c/{{ $c->slug }}">
                        <span class="pcomp-t">{{ $c->title }}</span>
                        <span class="pcomp-m">
                            <span class="pcomp-tag">{{ ucfirst($c->type) }}</span>
                            <span class="pcomp-tag {{ $c->status === 'open' ? 'on' : '' }}">{{ $c->status === 'open' ? 'Ouverte' : ucfirst($c->status) }}</span>
                            <span class="pcomp-n">{{ $n }} inscription{{ $n > 1 ? 's' : '' }}</span>
                        </span>
                        <span class="pcomp-act">{{ $editable ? 'Modifiable' : 'Verrouillée' }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
@endif