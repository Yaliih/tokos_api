<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\SmsServiceProvider::class,
    // L'admin ne se charge que si Filament est installé (composer require filament/filament)
    ...(class_exists(\Filament\PanelProvider::class) ? [App\Providers\Filament\AdminPanelProvider::class] : []),
];
