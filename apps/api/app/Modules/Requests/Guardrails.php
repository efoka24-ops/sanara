<?php
declare(strict_types=1);

namespace Sanara\Modules\Requests;

/**
 * T161, T165 : repérage déterministe des signes graves dans le message d'un patient. L'assistant ne donne
 * jamais d'avis : il oriente vers les urgences. La liste est volontairement large ; elle doit être relue
 * par un pharmacien avant tout usage réel.
 */
final class Guardrails
{
    private const SEVERE = [
        'douleur (dans la |a la |de )?poitrine', 'mal (a|pour) respirer', 'difficultes? (a|pour) respirer', 'respire (mal|plus)', 'etouff',
        'perte de connaissance', 'inconscient', 'evanoui', 'convulsion', 'hemorragie', 'saigne(ment)? (beaucoup|abondant)',
        'paralys', 'surdose', 'overdose', 'empoisonn', 'intoxication', 'suicid',
        'chest pain', '(cannot|can\'t|difficulty|trouble) breath', 'unconscious', 'passed out', 'seizure', 'heavy bleeding', 'stroke', 'poisoning',
    ];

    public static function severe(string $message): bool
    {
        $plain = strtr(mb_strtolower($message), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', '’' => "'",
        ]);
        return preg_match('/' . implode('|', self::SEVERE) . '/u', $plain) === 1;
    }
}
