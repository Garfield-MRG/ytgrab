<?php

declare(strict_types=1);

namespace App;

/**
 * Validation des URLs YouTube.
 *
 * Principe : on n'accepte que trois formes d'URL (watch, youtu.be, shorts),
 * on en extrait l'ID video, et le reste de l'application ne travaille plus
 * qu'avec cet ID. L'entree brute de l'utilisateur ne sort jamais d'ici.
 */
final class UrlValidator
{
    /** Un ID video YouTube : exactement 11 caracteres de cet alphabet. */
    private const ID_PATTERN = '[A-Za-z0-9_-]{11}';

    /**
     * Extrait l'ID video d'une URL collee par l'utilisateur.
     * Retourne null si l'URL ne correspond a aucune forme acceptee.
     */
    public static function extractId(string $input): ?string
    {
        $input = trim($input);

        $patterns = [
            // youtube.com/watch?v=ID (le parametre v peut ne pas etre le premier)
            '#^https?://(?:www\.|m\.)?youtube\.com/watch\?(?:[^\s\#]*&)?v=(' . self::ID_PATTERN . ')(?:[&\#]|$)#',
            // youtu.be/ID
            '#^https?://youtu\.be/(' . self::ID_PATTERN . ')(?:[?\#]|$)#',
            // youtube.com/shorts/ID
            '#^https?://(?:www\.|m\.)?youtube\.com/shorts/(' . self::ID_PATTERN . ')(?:[/?\#]|$)#',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Reconstruit une URL canonique a partir d'un ID valide.
     * C'est cette URL, et jamais l'entree utilisateur, qui est passee a yt-dlp.
     */
    public static function canonicalUrl(string $id): string
    {
        if (preg_match('#^' . self::ID_PATTERN . '$#', $id) !== 1) {
            throw new \InvalidArgumentException('ID video invalide');
        }

        return 'https://www.youtube.com/watch?v=' . $id;
    }
}
