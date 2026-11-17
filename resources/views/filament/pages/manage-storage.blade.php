<x-filament-panels::page>
    {{-- Pendant une copie, la page avance d'elle-même (et la tâche planifiée continue si on la ferme) --}}
    <div @if ($this->copyRunning()) wire:poll.4s="tick" @endif>
        {{ $this->form }}
    </div>
</x-filament-panels::page>
