{{-- Rappel (de temps en temps) : ce qui plaît à la personne, et sa ville si elle manque --}}
@php $me = auth()->user(); @endphp
<section class="card ask-card" aria-labelledby="ask-h">
    <form method="post" action="/interets" data-ajax data-dismiss=".ask-card">@csrf
        <h2 id="ask-h">@icon('sparkles', 'ico-sm')Qu'est-ce qui te plaît ?</h2>
        <p class="muted small">Choisis tes disciplines : on te montre d'abord leurs défis et concours.</p>
        @include('web.partials.interest-chips', ['selected' => $me->interests ?? []])
        <div class="ask-actions">
            <button class="btn">@icon('check', 'ico-sm')Enregistrer</button>
            <button class="btn btn-ghost" name="later" value="1" formnovalidate>Plus tard</button>
        </div>
    </form>
</section>
