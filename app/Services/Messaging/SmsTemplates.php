<?php

namespace App\Services\Messaging;

/**
 * Textes des SMS et modèles eMess.
 * ---------------------------------------------------------------------------
 * eMess (emess.cd) refuse tout message qui ne correspond pas à un modèle
 * approuvé sur le compte : « Message does not match any approved template ».
 *
 * Les modèles ci-dessous sont CEUX DE TUENDEA, déjà approuvés : on les reprend
 * à l'identique (même slug, même texte, mêmes variables) pour ne pas avoir à
 * en faire valider de nouveaux. Le texto reçu commence donc par « Tuendea ».
 *
 *    purpose => [slug eMess, texte exact du modèle, variables attendues]
 *
 * Pour passer un jour à des modèles « Sosa » : les faire approuver dans eMess,
 * puis changer EMESS_CODE_TEMPLATE / EMESS_TEST_TEMPLATE et le texte ici.
 * Rien d'autre à modifier dans le code.
 */
class SmsTemplates
{
    public const CATALOG = [
        'code' => [
            'tuendea_code',
            'Tuendea : votre code pour changer de mot de passe est {code}. Il expire dans 15 minutes. Ne le donnez à personne.',
            ['code' => 'chiffres, 6'],
        ],
        'test' => [
            'tuendea_essai',
            'Tuendea : ceci est un message d\'essai.',
            [],
        ],
    ];

    /** Nom du modèle : celui réglé dans .env s'il existe, sinon celui du catalogue. */
    public static function slug(?string $purpose): ?string
    {
        $purpose = (string) $purpose;
        $override = config('messaging.emess.'.$purpose.'_template');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return self::CATALOG[$purpose][0] ?? null;
    }

    public static function text(?string $purpose): ?string
    {
        return self::CATALOG[(string) $purpose][1] ?? null;
    }

    /** Variables d'un texte : « {code} » → « code ». @return array<int, string> */
    public static function variables(string $text): array
    {
        preg_match_all('/\{(\w+)\}/', $text, $m);

        return $m[1] ?? [];
    }

    /**
     * Le texte du modèle, variables remplies : c'est ce qui part réellement
     * (et le repli si eMess refuse le nom du modèle).
     */
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