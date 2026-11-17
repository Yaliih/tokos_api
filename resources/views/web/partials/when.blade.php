{{-- Le temps, partout pareil : « reste 2 j 4 h » (compte à rebours) ou « Terminé il y a 3 h ». $t = Presenter::timing / matchTiming --}}
@php $st = $t['state'] ?? 'none'; $what = in_array($t['what'] ?? '', ['Inscriptions', 'Pour accepter', 'Tour en cours', 'Votes du tour', 'Prestations', 'Votes'], true) ? $t['what'] : null; @endphp
@if ($st === 'left')<span class="when" title="{{ ($t['what'] ?? 'Temps') }} : temps restant">@icon('clock', 'ico-sm')@if($what)<span class="when-what">{{ $what }}</span>@endif<span class="countdown" data-ends="{{ $t['at'] }}" @if(!empty($t['ended_label'])) data-ended-text="{{ $t['ended_label'] }}" @endif></span></span>
@elseif ($st === 'ended')<span class="when over">{{ $t['label'] }}</span>
@elseif ($st === 'cancelled')<span class="when over">Annulé</span>
@elseif ($st === 'info')<span class="when">{{ $t['label'] }}</span>
@endif
