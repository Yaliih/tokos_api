@php
    $periodes = ['24h' => '24 h', '7j' => '7 jours', '30j' => '30 jours', 'tout' => 'Tout'];
@endphp
<x-filament::section>
    <x-slot name="heading">Visiteurs sans compte</x-slot>
    <x-slot name="description">Les personnes qui viennent sans s'inscrire : combien, et quelle part des vues.</x-slot>

    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px">
        @foreach ($periodes as $k => $label)
            <a href="?periode={{ $k }}" style="padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;text-decoration:none;{{ $periode === $k ? 'background:#6C5CE7;color:#fff' : 'background:#f3f4f6;color:#374151' }}">{{ $label }}</a>
        @endforeach
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
        <div style="padding:14px;border-radius:12px;background:#f9fafb;border:1px solid #e5e7eb">
            <div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase">Visiteurs</div>
            <div style="font-size:26px;font-weight:800">{{ number_format($guests, 0, ',', ' ') }}</div>
            <div style="font-size:12px;color:#6b7280">{{ number_format($guestPeople, 0, ',', ' ') }} personnes différentes</div>
        </div>
        <div style="padding:14px;border-radius:12px;background:#f9fafb;border:1px solid #e5e7eb">
            <div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase">Part des vues</div>
            <div style="font-size:26px;font-weight:800">{{ $part }} %</div>
            <div style="font-size:12px;color:#6b7280">visiteurs / membres</div>
        </div>
        <div style="padding:14px;border-radius:12px;background:#f9fafb;border:1px solid #e5e7eb">
            <div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase">Membres connectés</div>
            <div style="font-size:26px;font-weight:800">{{ number_format($members, 0, ',', ' ') }}</div>
            <div style="font-size:12px;color:#6b7280">vues avec un compte</div>
        </div>
        <div style="padding:14px;border-radius:12px;background:#f9fafb;border:1px solid #e5e7eb">
            <div style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase">Total des vues</div>
            <div style="font-size:26px;font-weight:800">{{ number_format($total, 0, ',', ' ') }}</div>
            <div style="font-size:12px;color:#6b7280">{{ $periodes[$periode] }}</div>
        </div>
    </div>
</x-filament::section>