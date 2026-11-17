{{--
    Réserver à une ou plusieurs zones (pays, province, territoire, ville, village) choisies dans une recherche de lieux.
    L'organisateur n'a pas besoin d'y être ; ce sont les votants et participants dont la position est vérifiée.
--}}
@feature('geo_limits')
@php $gs = old('geo_scope', 'both'); $viaIq = config('places.search_provider') === 'locationiq'; @endphp
<div class="geo-field" data-geo>
    <label class="switch"><span><b>@icon('map-pin', 'ico-sm')Réservé à des zones</b>
        <small>Des pays, provinces, villes ou villages : on vérifie la position de ceux qui participent ou votent.</small></span>
        <input type="checkbox" data-geo-toggle></label>
    <div class="geo-more" data-geo-more hidden>
        <ul class="geo-picked" data-geo-picked></ul>
        <div class="geo-search">
            <span class="input-icon">@icon('search')<input class="input" type="search" autocomplete="off" placeholder="Ajoute une ville, une province, un pays…" data-geo-q aria-label="Chercher une zone"></span>
            <ul class="geo-results" data-geo-results hidden role="listbox"></ul>
            <small class="geo-credit">Lieux : <a href="{{ $viaIq ? 'https://locationiq.com/' : 'https://photon.komoot.io/' }}" target="_blank" rel="noopener">{{ $viaIq ? 'LocationIQ' : 'Photon' }}</a> · © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a></small>
        </div>
        <div class="qc-chips">@include('web.partials.chips', ['name' => 'geo_scope', 'options' => ['both' => 'Participer et voter', 'enter' => 'Participer', 'vote' => 'Voter'], 'value' => $gs])</div>
    </div>
</div>
@endfeature
