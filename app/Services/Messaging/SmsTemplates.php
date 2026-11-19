<?php

namespace App\Services\Messaging;

/**
 * Modèles SMS approuvés sur le compte eMess.
 * ---------------------------------------------------------------------------
 * eMess (emess.cd) refuse tout message qui ne correspond pas à un modèle
 * approuvé : « Message does not match any approved template ».
 *
 * Rien n'est écrit en dur ici : un envoi a besoin de trois réglages, dans .env
 * (ou dans l'admin), et se change sans toucher au code :
 *
 *   EMESS_CODE_TEMPLATE  nom du modèle approuvé    (ex. verify-otp-fr)
 *   EMESS_CODE_VARIABLE  nom de sa variable        (ex. code, otp…)
 *   EMESS_CODE_TEXT      texte exact du modèle     (facultatif : repli en texte
 *                        brut et affichage dans le journal)
 *
 * Idem pour l'essai : EMESS_TEST_TEMPLATE / _VARIABLE / _TEXT — tant qu'aucun
 * modèle d'essai n'est approuvé, ils restent vides et l'essai utilise le modèle
 * du code (`php artisan emess:essai`).
 */
class SmsTemplates
{
    public const PURPOSES = ['code', 'test'];

    /** Textes des modèles approuvés (utilisables tels quels dans eMess). */
    public const DEFAULT_TEXT = [
        'code' => '[{app_name}] Votre code de vérification est {code}. Il expire dans {validity_minutes} min. Ne le partagez pas.',
        'invitation' => '[{app_name}] {name} te défie : « {title} ». Relève le défi : {lien}',
    ];

    public static function slug(?string $purpose): ?string
    {
        $slug = config('messaging.emess.'.(string) $purpose.'_template');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    public static function text(?string $purpose): ?string
    {
        $text = config('messaging.emess.'.(string) $purpose.'_text');
        if (is_string($text) && $text !== '') {
            return $text;
        }

        return self::DEFAULT_TEXT[(string) $purpose] ?? null;
    }

    /** Variables attendues par le modèle : celles de son texte, sinon celle réglée dans .env. */
    public static function variables(?string $purpose): array
    {
        $text = self::text($purpose);
        if ($text !== null) {
            preg_match_all('/\{(\w+)\}/', $text, $m);

            return $m[1] ?? [];
        }
        $var = config('messaging.emess.'.(string) $purpose.'_variable');

        return is_string($var) && $var !== '' ? [$var] : [];
    }

    /** Le texte du modèle, variables remplies (facultatif : null si le texte n'est pas connu). */
    public static function render(string $purpose, array $params): ?string
    {
        $text = self::text($purpose);
        if ($text === null) {
            return null;
        }
        foreach ($params as $k => $v) {
            $text = str_replace('{'.$k.'}', (string) $v, $text);
        }

        return $text;
    }
}