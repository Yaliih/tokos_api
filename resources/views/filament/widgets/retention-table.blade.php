<x-filament-widgets::widget>
    <x-filament::section heading="Reviennent-ils ?" description="Part des inscrits de chaque semaine revenus le lendemain, la 2e semaine et le 2e mois. Un tiret : pas encore mesurable.">
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;font-variant-numeric:tabular-nums">
                <thead>
                    <tr style="text-align:left;color:rgb(107 114 128)">
                        <th style="padding:8px 10px;font-weight:600">Inscrits</th>
                        <th style="padding:8px 10px;font-weight:600;text-align:right">Nombre</th>
                        <th style="padding:8px 10px;font-weight:600;text-align:center">Lendemain</th>
                        <th style="padding:8px 10px;font-weight:600;text-align:center">2e semaine</th>
                        <th style="padding:8px 10px;font-weight:600;text-align:center">2e mois</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr style="border-top:1px solid rgba(127,127,127,.15)">
                            <td style="padding:8px 10px">{{ $row['week'] }}</td>
                            <td style="padding:8px 10px;text-align:right">{{ $row['users'] }}</td>
                            @foreach (['d1', 'd7', 'd30'] as $k)
                                @php $v = $row[$k]; @endphp
                                <td style="padding:6px 10px;text-align:center">
                                    @if ($v === null)
                                        <span style="color:rgb(156 163 175)">–</span>
                                    @else
                                        <span style="display:inline-block;min-width:52px;padding:3px 8px;border-radius:8px;font-weight:600;background:rgba(108,92,231,{{ 0.08 + $v / 100 * 0.6 }});color:{{ $v > 55 ? '#fff' : 'inherit' }}">{{ $v }} %</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
