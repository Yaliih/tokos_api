<?php

/*
| Envoi des SMS et messages WhatsApp (codes de vérification, invitations, alertes).
| Les canaux sont essayés dans l'ordre : si le premier échoue, le suivant prend le relais.
| L'ordre se règle ici (MESSAGING_CHANNELS) ou dans l'admin (page « Messages »).
| Les clés secrètes restent dans .env, jamais dans l'admin.
*/
return [
    // Ex. « whatsapp,sms » : WhatsApp d'abord, SMS si WhatsApp échoue. « log » écrit dans storage/logs (développement).
    // Par défaut : SMS seulement (eMess, puis Infobip en relais).
    'channels' => array_values(array_filter(array_map('trim', explode(',', (string) env('MESSAGING_CHANNELS', 'sms'))))),

    // Pilote utilisé pour chaque canal
    'drivers' => [
        'sms' => env('SMS_DRIVER', 'emess,infobip'),  // emess, infobip, twilio, africastalking, http, log — plusieurs = relais dans l'ordre
        'whatsapp' => env('WHATSAPP_DRIVER', 'log'),  // meta, twilio, http, log
        'log' => 'log',
    ],

    // Durée de validité d'un code (minutes). Doit correspondre à ce qu'annonce le modèle SMS
    // approuvé (EMESS_CODE_TEXT) : sinon le texto mentirait sur sa durée de validité.
    'code_ttl' => (int) env('VERIFICATION_CODE_TTL', 10),

    // Nom entre crochets dans le SMS ({app_name}) : c'est la marque que lit la personne
    'app_name' => env('SMS_APP_NAME', 'Sosa'),

    // eMess (https://emess.cd) : SMS en RDC. eMess n'accepte QUE des textes qui correspondent à un
    // modèle approuvé sur le compte. On lui envoie donc le NOM du modèle et sa variable
    // (voir App\Services\Messaging\SmsTemplates) : ces réglages se changent dans .env.
    'emess' => [
        'url' => env('EMESS_URL', 'https://emess.cd/api/v1'),
        'app_id' => env('EMESS_APP_ID'),
        'secret_key' => env('EMESS_SECRET_KEY'),

        // Code de vérification : nom du modèle approuvé, nom de sa variable, texte exact (facultatif)
        'code_template' => env('EMESS_CODE_TEMPLATE', 'auth-otp'),
        'code_variable' => env('EMESS_CODE_VARIABLE', 'code'),
        'code_text' => env('EMESS_CODE_TEXT'),

        // Message d'essai : vide tant qu'aucun modèle d'essai n'est approuvé
        'test_template' => env('EMESS_TEST_TEMPLATE'),
        'test_variable' => env('EMESS_TEST_VARIABLE'),
        'test_text' => env('EMESS_TEST_TEXT'),
    ],

    // Infobip SMS (relais) : la base vient de la page d'accueil du portail Infobip (ex. xxxxx.api.infobip.com)
    'infobip' => [
        'base_url' => env('INFOBIP_BASE_URL'),
        'api_key' => env('INFOBIP_API_KEY'),
        'sender' => env('INFOBIP_SMS_SENDER', 'Sosa'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_FROM'),                       // numéro SMS ou identifiant d'expéditeur
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),     // ex. +14155238886
    ],

    'africastalking' => [
        'username' => env('AT_USERNAME'),
        'api_key' => env('AT_API_KEY'),
        'from' => env('AT_FROM'),                           // identifiant d'expéditeur (facultatif)
        'sandbox' => (bool) env('AT_SANDBOX', false),
    ],

    // WhatsApp Cloud API (Meta). Les codes passent par un modèle « authentication » approuvé chez Meta.
    'meta' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'code_template' => env('WHATSAPP_CODE_TEMPLATE', 'code_verification'),
        'text_template' => env('WHATSAPP_TEXT_TEMPLATE'),    // modèle à un paramètre pour les autres messages (facultatif)
        'language' => env('WHATSAPP_TEMPLATE_LANG', 'fr'),
    ],

    // Fournisseur quelconque avec une API HTTP simple (beaucoup d'opérateurs et d'agrégateurs locaux)
    'http' => [
        'sms' => [
            'url' => env('SMS_HTTP_URL'),
            'method' => env('SMS_HTTP_METHOD', 'POST'),
            'token' => env('SMS_HTTP_TOKEN'),                // envoyé en « Authorization: Bearer »
            'to_field' => env('SMS_HTTP_TO_FIELD', 'to'),
            'text_field' => env('SMS_HTTP_TEXT_FIELD', 'message'),
            'extra' => json_decode((string) env('SMS_HTTP_EXTRA', '{}'), true) ?: [], // champs fixes, ex. {"sender":"SOSA"}
            'json' => (bool) env('SMS_HTTP_JSON', true),
        ],
        'whatsapp' => [
            'url' => env('WHATSAPP_HTTP_URL'),
            'method' => env('WHATSAPP_HTTP_METHOD', 'POST'),
            'token' => env('WHATSAPP_HTTP_TOKEN'),
            'to_field' => env('WHATSAPP_HTTP_TO_FIELD', 'to'),
            'text_field' => env('WHATSAPP_HTTP_TEXT_FIELD', 'message'),
            'extra' => json_decode((string) env('WHATSAPP_HTTP_EXTRA', '{}'), true) ?: [],
            'json' => (bool) env('WHATSAPP_HTTP_JSON', true),
        ],
    ],
];
