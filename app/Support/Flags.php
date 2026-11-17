<?php

namespace App\Support;

/**
 * Pays proposés pour le numéro de téléphone, avec un drapeau dessiné en SVG
 * (simplifié pour rester lisible à petite taille). La RDC en premier.
 */
class Flags
{
    /** iso => [nom, indicatif] */
    public const COUNTRIES = [
        'CD' => ['RD Congo', '+243'],
        'CG' => ['Congo-Brazzaville', '+242'],
        'AO' => ['Angola', '+244'],
        'ZM' => ['Zambie', '+260'],
        'TZ' => ['Tanzanie', '+255'],
        'UG' => ['Ouganda', '+256'],
        'RW' => ['Rwanda', '+250'],
        'BI' => ['Burundi', '+257'],
        'CF' => ['Centrafrique', '+236'],
        'SS' => ['Soudan du Sud', '+211'],
        'CM' => ['Cameroun', '+237'],
        'GA' => ['Gabon', '+241'],
        'TD' => ['Tchad', '+235'],
        'NG' => ['Nigeria', '+234'],
        'CI' => ['Côte d\'Ivoire', '+225'],
        'SN' => ['Sénégal', '+221'],
        'ML' => ['Mali', '+223'],
        'BF' => ['Burkina Faso', '+226'],
        'GN' => ['Guinée', '+224'],
        'BJ' => ['Bénin', '+229'],
        'TG' => ['Togo', '+228'],
        'NE' => ['Niger', '+227'],
        'GH' => ['Ghana', '+233'],
        'KE' => ['Kenya', '+254'],
        'ZA' => ['Afrique du Sud', '+27'],
        'MA' => ['Maroc', '+212'],
        'FR' => ['France', '+33'],
        'BE' => ['Belgique', '+32'],
        'CH' => ['Suisse', '+41'],
        'GB' => ['Royaume-Uni', '+44'],
        'CA' => ['Canada', '+1'],
        'US' => ['États-Unis', '+1'],
    ];

    /** Le pays correspondant à un indicatif (le premier trouvé), RDC par défaut. */
    public static function isoFor(?string $dial): string
    {
        $dial = '+'.ltrim((string) $dial, '+');
        foreach (self::COUNTRIES as $iso => [, $d]) {
            if ($d === $dial) {
                return $iso;
            }
        }

        return 'CD';
    }

    private static function star(float $cx, float $cy, float $r, string $fill): string
    {
        $pts = [];
        for ($i = 0; $i < 10; $i++) {
            $a = -M_PI / 2 + $i * M_PI / 5;
            $rr = $i % 2 ? $r * 0.4 : $r;
            $pts[] = round($cx + $rr * cos($a), 2).','.round($cy + $rr * sin($a), 2);
        }

        return '<polygon points="'.implode(' ', $pts).'" fill="'.$fill.'"/>';
    }

    private static function h(array $colors): string
    {
        $out = ''; $n = count($colors); $hgt = 20 / $n;
        foreach ($colors as $i => $c) {
            $out .= '<rect y="'.round($i * $hgt, 3).'" width="30" height="'.round($hgt + .05, 3).'" fill="'.$c.'"/>';
        }

        return $out;
    }

    private static function v(array $colors): string
    {
        $out = ''; $n = count($colors); $w = 30 / $n;
        foreach ($colors as $i => $c) {
            $out .= '<rect x="'.round($i * $w, 3).'" width="'.round($w + .05, 3).'" height="20" fill="'.$c.'"/>';
        }

        return $out;
    }

    /** Le drapeau en SVG (viewBox 30×20). */
    public static function svg(string $iso, string $class = 'flag'): string
    {
        $g = match ($iso) {
            'CD' => '<rect width="30" height="20" fill="#007FFF"/><path d="M0 16 26 0h4v4L4 20H0Z" fill="#F7D618"/><path d="M0 17.8 28.9 0H30v2.2L1.1 20H0Z" fill="#CE1021"/>'.self::star(6, 5.2, 3.6, '#F7D618'),
            'CG' => '<rect width="30" height="20" fill="#DC241F"/><path d="M0 0h20L0 20Z" fill="#009543"/><path d="M20 0h6L6 20H0Z" fill="#FBDE4A"/>',
            'AO' => self::h(['#CC092F', '#000']).self::star(15, 10, 3, '#FFCB00'),
            'ZM' => '<rect width="30" height="20" fill="#198A00"/><rect x="18.5" y="8" width="3.8" height="12" fill="#DE2010"/><rect x="22.3" y="8" width="3.8" height="12" fill="#000"/><rect x="26.1" y="8" width="3.9" height="12" fill="#EF7D00"/>',
            'TZ' => '<rect width="30" height="20" fill="#00A3DD"/><path d="M0 0h30L0 20Z" fill="#1EB53A"/><path d="M0 15 22.5 0H30v5L7.5 20H0Z" fill="#FCD116"/><path d="M0 17.5 26.25 0H30v2.5L3.75 20H0Z" fill="#000"/>',
            'UG' => self::h(['#000', '#FCDC04', '#D90000', '#000', '#FCDC04', '#D90000']).'<circle cx="15" cy="10" r="4" fill="#fff"/>',
            'RW' => '<rect width="30" height="20" fill="#20603D"/><rect width="30" height="15" fill="#FAD201"/><rect width="30" height="10" fill="#00A1DE"/><circle cx="25" cy="4.6" r="2.4" fill="#E5BE01"/>',
            'BI' => '<rect width="30" height="20" fill="#1EB53A"/><path d="M0 0h30L15 10Z M0 20h30L15 10Z" fill="#CE1126"/><path d="M0 0 30 20M30 0 0 20" stroke="#fff" stroke-width="3"/><circle cx="15" cy="10" r="5" fill="#fff"/>'.self::star(15, 8.3, 1.2, '#CE1126').self::star(13.4, 11.2, 1.2, '#CE1126').self::star(16.6, 11.2, 1.2, '#CE1126'),
            'CF' => self::h(['#003082', '#fff', '#289728', '#FFCE00']).'<rect x="13" width="4" height="20" fill="#D21034"/>'.self::star(5, 2.5, 2, '#FFCE00'),
            'SS' => '<rect width="30" height="20" fill="#fff"/><rect width="30" height="6" fill="#000"/><rect y="7" width="30" height="6" fill="#DA121A"/><rect y="14" width="30" height="6" fill="#078930"/><path d="M0 0 13 10 0 20Z" fill="#0F47AF"/>'.self::star(4.5, 10, 2.6, '#FCDD09'),
            'CM' => self::v(['#007A5E', '#CE1126', '#FCD116']).self::star(15, 10, 3.2, '#FCD116'),
            'GA' => self::h(['#009E60', '#FCD116', '#3A75C4']),
            'TD' => self::v(['#002664', '#FECB00', '#C60C30']),
            'NG' => self::v(['#008751', '#fff', '#008751']),
            'CI' => self::v(['#F77F00', '#fff', '#009E60']),
            'SN' => self::v(['#00853F', '#FDEF42', '#E31B23']).self::star(15, 10, 3, '#00853F'),
            'ML' => self::v(['#14B53A', '#FCD116', '#CE1126']),
            'BF' => self::h(['#EF2B2D', '#009E49']).self::star(15, 10, 3, '#FCD116'),
            'GN' => self::v(['#CE1126', '#FCD116', '#009460']),
            'BJ' => '<rect width="30" height="10" fill="#FCD116"/><rect y="10" width="30" height="10" fill="#E8112D"/><rect width="12" height="20" fill="#008751"/>',
            'TG' => self::h(['#006A4E', '#FFCE00', '#006A4E', '#FFCE00', '#006A4E']).'<rect width="12" height="12" fill="#D21034"/>'.self::star(6, 6, 3.4, '#fff'),
            'NE' => self::h(['#E05206', '#fff', '#0DB02B']).'<circle cx="15" cy="10" r="2.3" fill="#E05206"/>',
            'GH' => self::h(['#CE1126', '#FCD116', '#006B3F']).self::star(15, 10, 3, '#000'),
            'KE' => '<rect width="30" height="20" fill="#fff"/><rect width="30" height="6" fill="#000"/><rect y="7" width="30" height="6" fill="#BB0000"/><rect y="14" width="30" height="6" fill="#006600"/><ellipse cx="15" cy="10" rx="2.8" ry="5.4" fill="#BB0000" stroke="#000" stroke-width=".8"/>',
            'ZA' => '<rect width="30" height="10" fill="#E03C31"/><rect y="10" width="30" height="10" fill="#001489"/><path d="M0 0 13 10 0 20" fill="none" stroke="#fff" stroke-width="7"/><path d="M13 10h17" stroke="#fff" stroke-width="7"/><path d="M0 0 13 10 0 20" fill="none" stroke="#007749" stroke-width="4"/><path d="M13 10h17" stroke="#007749" stroke-width="4"/><path d="M0 2.5 9.8 10 0 17.5Z" fill="#FFB81C"/><path d="M0 4.4 7.3 10 0 15.6Z" fill="#000"/>',
            'MA' => '<rect width="30" height="20" fill="#C1272D"/><polygon points="15,5.4 16.4,9.6 20.6,9.6 17.2,12.1 18.5,16.2 15,13.7 11.5,16.2 12.8,12.1 9.4,9.6 13.6,9.6" fill="none" stroke="#006233" stroke-width="1"/>',
            'FR' => self::v(['#002395', '#fff', '#ED2939']),
            'BE' => self::v(['#000', '#FAE042', '#ED2939']),
            'CH' => '<rect width="30" height="20" fill="#DA291C"/><rect x="13" y="4" width="4" height="12" fill="#fff"/><rect x="9" y="8" width="12" height="4" fill="#fff"/>',
            'GB' => '<rect width="30" height="20" fill="#012169"/><path d="M0 0 30 20M30 0 0 20" stroke="#fff" stroke-width="4"/><path d="M0 0 30 20M30 0 0 20" stroke="#C8102E" stroke-width="1.6"/><path d="M15 0v20M0 10h30" stroke="#fff" stroke-width="6"/><path d="M15 0v20M0 10h30" stroke="#C8102E" stroke-width="3.4"/>',
            'CA' => self::v(['#D80621', '#fff', '#fff', '#D80621']).'<path d="M15 4.5 16 6.8 17.8 6.2 17.2 9.6 18.8 8.8 18.3 11 20 11.4 16 13.8 16.3 15.6H13.7L14 13.8 10 11.4 11.7 11 11.2 8.8 12.8 9.6 12.2 6.2 14 6.8Z" fill="#D80621"/>',
            'US' => self::h(['#B22234', '#fff', '#B22234', '#fff', '#B22234', '#fff', '#B22234']).'<rect width="13" height="10.8" fill="#3C3B6E"/>',
            default => '<rect width="30" height="20" fill="#ccc"/>',
        };

        return '<svg class="'.$class.'" viewBox="0 0 30 20" aria-hidden="true" preserveAspectRatio="none">'.$g.'</svg>';
    }
}
