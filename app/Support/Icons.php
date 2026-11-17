<?php

namespace App\Support;

/**
 * Icônes SVG du site (tracés au trait, style Lucide, licence ISC).
 * Dans une vue Blade : @icon('home') ou @icon('heart', 'ico-lg').
 */
class Icons
{
    private const PATHS = [
        'arrow-up' => '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>',
        'arrow-down' => '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'reply' => '<path d="m9 17-5-5 5-5"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>',
        'pause' => '<rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/>',
        'film' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 3v18"/><path d="M17 3v18"/><path d="M3 7.5h4"/><path d="M3 12h18"/><path d="M3 16.5h4"/><path d="M17 7.5h4"/><path d="M17 16.5h4"/>',
        'at' => '<circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'whatsapp' => '<path d="M3 21l1.65-3.8a9 9 0 1 1 3.4 2.9L3 21"/><path d="M9 10a.5.5 0 0 0 1 0V9a.5.5 0 0 0-1 0v1a5 5 0 0 0 5 5h1a.5.5 0 0 0 0-1h-1a.5.5 0 0 0 0 1"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
        'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'camera' => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'home' => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        'compass' => '<circle cx="12" cy="12" r="10"/><path d="m16.24 7.76-2.12 6.36-6.36 2.12 2.12-6.36z"/>',
        'flame' => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.07-2.14-.22-4.05 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.43-2.29 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
        // Face-à-face : deux flèches qui se répondent (plus d'épées)
        'swords' => '<path d="M4 8h13"/><path d="m13 4 4 4-4 4"/><path d="M20 16H7"/><path d="m11 12-4 4 4 4"/>',
        // Marque : un ratel (le blaireau à miel d'Afrique, qui n'a peur de personne) sur une tuile violette
        // Logo Sosa : tuile violette, deux s blancs face à face (le second retourné)
        'sosa' => '<rect x="0" y="0" width="24" height="24" rx="5.3" fill="currentColor" stroke="none"/><g transform="translate(3.120 17.019) scale(0.018385 -0.018385)"><path stroke="none" transform="translate(-22.0 0)" fill="#fff" d="M269 -12Q179 -12 112.5 30.5Q46 73 22 145L132 197Q153 153 189.0 128.0Q225 103 269 103Q335 103 335 152Q335 185 281 200L188 226Q121 245 85.0 287.5Q49 330 49 388Q49 439 75.0 477.0Q101 515 148.0 536.5Q195 558 256 558Q336 558 396.5 520.5Q457 483 482 415L371 363Q359 397 327.5 417.5Q296 438 256 438Q227 438 210.5 426.0Q194 414 194 393Q194 361 253 344L340 318Q408 298 444.0 257.5Q480 217 480 158Q480 82 422.0 35.0Q364 -12 269 -12Z"/><path stroke="none" transform="translate(736.0 0) scale(-1 1) translate(-252.0 0)" fill="#fff" d="M269 -12Q179 -12 112.5 30.5Q46 73 22 145L132 197Q153 153 189.0 128.0Q225 103 269 103Q335 103 335 152Q335 185 281 200L188 226Q121 245 85.0 287.5Q49 330 49 388Q49 439 75.0 477.0Q101 515 148.0 536.5Q195 558 256 558Q336 558 396.5 520.5Q457 483 482 415L371 363Q359 397 327.5 417.5Q296 438 256 438Q227 438 210.5 426.0Q194 414 194 393Q194 361 253 344L340 318Q408 298 444.0 257.5Q480 217 480 158Q480 82 422.0 35.0Q364 -12 269 -12Z"/></g>',
        'badger' => '<rect x="0" y="0" width="24" height="24" rx="5.8" fill="currentColor" stroke="none"/><g transform="matrix(.98 0 0 .98 .24 .6)" stroke="none"><g transform="matrix(1.1 0 0 1.1 -1.76 -2.74)"><path d="M4.2 11.4C6 9.6 8.6 9 11.4 9h4.2c2 0 3.4.6 4.6 1.6.8.6 1.7 1.2 2.6 1.6.3.2.2.7-.2.8-.9.2-1.8.4-2.5.9-.5.4-.9 1-1.1 1.6l.2 2.8c.7.1 1.2.3 1.4.6.1.3-.1.4-.4.4h-3l-.2-2.7c-1.4.3-2.9.4-4.4.4h-3c-.6 0-1.2-.1-1.7-.2l-.1 1.5c.6.1 1.1.3 1.2.6.1.3-.1.4-.4.4H5.8l-.2-3.3c-1.1-.7-1.8-1.8-1.9-3.1-.7-.4-1.3-1.2-1.6-2.1.8.3 1.5.5 2.1.6Z" fill="#15151C"/><path d="M4.2 11.4C6 9.6 8.6 9 11.4 9h4.2c2 0 3.4.6 4.6 1.6l1.2.9c-.8.2-1.5.5-2 .9-1.4-1-2.8-1.4-4.2-1.4h-3.6c-2.6 0-5 .6-7 1.6-.2-.4-.3-.8-.4-1.2Z" fill="#fff"/><g fill="#fff"><circle cx="20.75" cy="12.55" r=".5"/></g></g></g>',
        'trophy' => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
        'crown' => '<path d="m2 4 3 12h14l3-12-6 7-4-7-4 7-6-7z"/><path d="M5 20h14"/>',
        'chart' => '<path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'mail' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'log-in' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
        'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.59 13.51 6.83 3.98"/><path d="m15.41 6.51-6.82 3.98"/>',
        'heart' => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
        'comment' => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'bookmark' => '<path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'volume' => '<path d="M11 5 6 9H2v6h4l5 4V5Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a10 10 0 0 1 0 14"/>',
        'volume-x' => '<path d="M11 5 6 9H2v6h4l5 4V5Z"/><path d="m22 9-6 6"/><path d="m16 9 6 6"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        // Le coin Sosa : un jeton rond avec un losange taillé, pas de symbole monétaire
        'coin' => '<circle cx="12" cy="12" r="9.2"/><path d="M12 6.6 16.4 12 12 17.4 7.6 12Z" fill="currentColor" stroke="none"/>',
        'wallet' => '<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2.5"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
        'shuffle' => '<path d="m18 14 4 4-4 4"/><path d="m18 2 4 4-4 4"/><path d="M2 18h1.97a4 4 0 0 0 3.3-1.7l5.47-8.6a4 4 0 0 1 3.3-1.7H22"/><path d="M2 6h1.97a4 4 0 0 1 3.3 1.7l.53.83"/><path d="M22 18h-6.04a4 4 0 0 1-3.3-1.7l-.53-.83"/>',
        'refresh' => '<path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M8 16H3v5"/>',
        'flag' => '<path d="M4 22V4"/><path d="M4 4h12l-2 4 2 4H4"/>',
        'maximize' => '<path d="M15 3h6v6"/><path d="M9 21H3v-6"/><path d="m21 3-7 7"/><path d="m3 21 7-7"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'percent' => '<path d="M19 5 5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'arrow-right' => '<path d="m12 5 7 7-7 7"/><path d="M5 12h14"/>',
        'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'image' => '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
        'video' => '<path d="m22 8-6 4 6 4V8Z"/><rect width="14" height="12" x="2" y="6" rx="2"/>',
        'mic' => '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/>',
        'type' => '<path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/>',
        'calendar' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
        'lock' => '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'zap' => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
        'award' => '<circle cx="12" cy="8" r="6"/><path d="M15.48 12.89 17 22l-5-3-5 3 1.52-9.11"/>',
        'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'bracket' => '<rect x="16" y="16" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="9" y="2" width="6" height="6" rx="1"/><path d="M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3"/><path d="M12 12V8"/>',
        'columns' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M12 3v18"/>',
        'rows' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 12h18"/>',
        'map-pin' => '<path d="M20 10c0 5-8 12-8 12s-8-7-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'school' => '<path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5"/><path d="M22 10v6"/>',
        'equal' => '<path d="M5 9h14"/><path d="M5 15h14"/>',
        // Défi : un drapeau planté au sommet (relever le défi)
        'challenge' => '<circle cx="12" cy="12" r="9.5"/><path d="M13.2 6.2 8.4 13h3.8l-1.2 4.8 4.8-6.8H12z"/>',
        // Duel : « VS », comme sur les cartes de duel
        'vs' => '<path d="M14.5 17.5 3 6V3h3l11.5 11.5"/><path d="m13 19 6-6"/><path d="m16 16 4 4"/><path d="m19 21 2-2"/><path d="M14.5 6.5 18 3h3v3l-3.5 3.5"/><path d="m5 14 4 4"/><path d="m7 17-3 3"/><path d="m3 19 2 2"/>',
        // Concours : un podium et une étoile
        'podium' => '<path d="M9 21v-9h6v9"/><path d="M3 21v-5h6"/><path d="M15 15h6v6"/><path d="M2 21h20"/><path d="m12 2.8 1 2 2.2.3-1.6 1.5.4 2.2-2-1-2 1 .4-2.2-1.6-1.5 2.2-.3Z"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
        'more' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
        'sparkles' => '<path d="m12 3-1.91 5.81a2 2 0 0 1-1.28 1.28L3 12l5.81 1.91a2 2 0 0 1 1.28 1.28L12 21l1.91-5.81a2 2 0 0 1 1.28-1.28L21 12l-5.81-1.91a2 2 0 0 1-1.28-1.28z"/>',
        'rotate' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
        'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C9 3 11 5 12 8c1-3 3-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
        'eye-off' => '<path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/>',
        'scale' => '<path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/>',
        'trending' => '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>',
        'at-sign' => '<circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"/>',
        'radio' => '<path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5"/><path d="M19.1 4.9C23 8.8 23 15.1 19.1 19"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/>',
        'megaphone' => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'play' => '<path d="m6 3 14 9-14 9V3z"/>',
        'star' => '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>',
        'medal' => '<path d="M7.21 15 2.66 7.14a2 2 0 0 1 .13-2.2L4.4 2.8A2 2 0 0 1 6 2h12a2 2 0 0 1 1.6.8l1.6 2.14a2 2 0 0 1 .14 2.2L16.79 15"/><path d="M11 12 5.12 2.2"/><path d="m13 12 5.88-9.8"/><path d="M8 7h8"/><circle cx="12" cy="17" r="5"/><path d="M12 18v-2h-.5"/>',
        'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'smile' => '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><path d="M9 9h.01"/><path d="M15 9h.01"/>',
    ];

    /** Icône par type de compétition */
    public const TYPE = ['challenge' => 'challenge', 'duel' => 'vs', 'contest' => 'medal', 'tournament' => 'bracket'];

    /** Icône par verbe du fil d'activité */
    public const VERB = [
        'competition.created' => 'megaphone', 'duel.invited' => 'vs', 'challenge.accepted' => 'zap',
        'entry.submitted' => 'user-plus', 'match.completed' => 'trophy', 'match.milestone' => 'flame',
        'tournament.started' => 'bracket', 'tournament.round' => 'bracket', 'contest.voting' => 'check-circle', 'contest.standings' => 'medal',
        'competition.completed' => 'crown', 'space.joined' => 'users', 'space.created' => 'sparkles',
        'user.followed' => 'user-plus', 'badge.earned' => 'award', 'streak' => 'flame', 'rank.top10' => 'crown',
        'post.created' => 'edit',
        'contest.leader' => 'crown', 'contest.surge' => 'trending', 'competition.entries' => 'users', 'competition.last_call' => 'clock',
        'match.tight' => 'scale', 'prize.delivered' => 'gift',
    ];

    /** Icône par type de notification */
    public const NOTIF = [
        'challenge.received' => 'vs', 'challenge.accepted' => 'zap', 'entry.submitted' => 'user-plus',
        'entry.approved' => 'check-circle', 'entry.rejected' => 'x', 'match.started' => 'play',
        'match.ending_soon' => 'clock', 'match.lead' => 'trending', 'gift.received' => 'gift', 'verification.approved' => 'check-circle', 'verification.rejected' => 'info', 'match.won' => 'trophy', 'match.lost' => 'rotate', 'match.draw' => 'scale',
        'competition.completed' => 'crown', 'competition.started' => 'megaphone', 'tournament.next_round' => 'chevron-right',
        'tournament.eliminated' => 'target', 'follower.new' => 'user-plus', 'space.joined' => 'users',
        'badge.earned' => 'award', 'rematch.requested' => 'rotate', 'proposal.submitted' => 'sparkles',
        'comment.new' => 'comment', 'invitation.declined' => 'x', 'announcement' => 'megaphone',
        'space.request' => 'user-plus', 'space.invite' => 'users', 'space.accepted' => 'check-circle', 'space.role' => 'shield',
        'supported.result' => 'trophy', 'supported.progress' => 'trending', 'favorite.joined' => 'heart', 'competition.new' => 'megaphone',
        'contest.progress' => 'trending', 'prize.reminder' => 'gift', 'prize.delivered' => 'gift', 'mention' => 'at-sign',
    ];

    /** Icône par badge */
    public const BADGE = [
        'first_entry' => 'sparkles', 'first_win' => 'award', 'wins_10' => 'vs', 'wins_50' => 'shield',
        'streak_3' => 'flame', 'streak_10' => 'zap', 'giant_slayer' => 'trending', 'crowd_favorite' => 'heart',
        'champion' => 'trophy', 'laureate' => 'medal', 'organizer' => 'megaphone', 'voter_100' => 'check-circle',
        'top_10' => 'crown',
    ];

    public static function svg(string $name, string $class = ''): string
    {
        $paths = self::PATHS[$name] ?? self::PATHS['sparkles'];

        return '<svg class="ico '.e($class).'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.$paths.'</svg>';
    }
}
