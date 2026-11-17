<?php

/*
| Compression des médias à l'envoi (vidéo et audio avec ffmpeg, photos avec GD).
| Si ffmpeg n'est pas installé, les vidéos et audios sont gardés tels quels : rien ne casse.
*/
return [
    'compress' => (bool) env('MEDIA_COMPRESS', true),
    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
    'timeout' => (int) env('MEDIA_COMPRESS_TIMEOUT', 150), // secondes au plus par fichier

    'video' => [
        'short_side' => (int) env('MEDIA_VIDEO_SIZE', 720), // 720 : le petit côté (720p), 480 pour encore plus léger
        'crf' => (int) env('MEDIA_VIDEO_CRF', 28),          // 23 = très bonne qualité, 28 = bon compromis, 32 = très léger
        'fps' => 30,
        'audio_kbps' => 96,
        'preset' => env('MEDIA_VIDEO_PRESET', 'veryfast'),
    ],
    'audio_kbps' => (int) env('MEDIA_AUDIO_KBPS', 96),
    'image' => [
        'max' => (int) env('MEDIA_IMAGE_MAX', 1600),   // plus grand côté, en pixels
        'quality' => (int) env('MEDIA_IMAGE_QUALITY', 82),
    ],
];
