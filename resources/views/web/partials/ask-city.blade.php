{{-- Rappel à part : la ville (pour le classement par ville et les compétitions près de chez soi) --}}
<section class="card ask-card" aria-labelledby="ask-city-h">
    <form method="post" action="/ville" data-ajax data-dismiss=".ask-card">@csrf
        <h2 id="ask-city-h">@icon('map-pin', 'ico-sm')Tu es dans quelle ville ?</h2>
        <p class="muted small">Pour le classement des villes et les compétitions près de chez toi.</p>
        <span class="input-btn"><input class="input" name="city" list="ask-cities" maxlength="60" placeholder="Kinshasa" aria-label="Ta ville" data-my-city>
            <button type="button" class="btn btn-soft btn-sm" data-locate>@icon('map-pin', 'ico-sm')<span class="hide-sm">Me localiser</span></button></span>
        <datalist id="ask-cities">@foreach (config('places.cities') as $c)<option value="{{ $c }}">@endforeach</datalist>
        <div class="ask-actions">
            <button class="btn">@icon('check', 'ico-sm')Enregistrer</button>
            <button class="btn btn-ghost" name="later" value="1" formnovalidate>Plus tard</button>
        </div>
    </form>
</section>
