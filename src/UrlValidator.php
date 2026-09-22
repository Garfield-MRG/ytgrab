<?php

declare(strict_types=1);

namespace App;

/**
 * Validation des URLs YouTube. On accepte quatre formes (watch, youtu.be,
 * shorts, playlist), on en extrait l'ID et le reste de l'application ne
 * travaille qu'avec cet ID.
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
     * Vrai si la chaine est un ID video valide.
     */
    public static function isValidId(string $id): bool
    {
        return preg_match('#^' . self::ID_PATTERN . '$#', $id) === 1;
    }

    /**
     * Un ID de playlist : meme alphabet que les videos, longueur variable
     * (PL + 32 caracteres le plus souvent, mais aussi des formes courtes).
     */
    private const PLAYLIST_ID_PATTERN = '[A-Za-z0-9_-]{2,128}';

    /**
     * Extrait l'ID d'une URL de playlist (youtube.com/playlist?list=ID).
     * Une URL watch?v=...&list=... reste une video : c'est extractId() qui
     * la traite, et la playlist qui l'entoure est ignoree.
     */
    public static function extractPlaylistId(string $input): ?string
    {
        $input = trim($input);
        $pattern = '#^https?://(?:www\.|m\.)?youtube\.com/playlist\?(?:[^\s\#]*&)?list=(' . self::PLAYLIST_ID_PATTERN . ')(?:[&\#]|$)#';

        return preg_match($pattern, $input, $m) === 1 ? $m[1] : null;
    }

    public static function isValidPlaylistId(string $id): bool
    {
        return preg_match('#^' . self::PLAYLIST_ID_PATTERN . '$#', $id) === 1;
    }

    public static function canonicalPlaylistUrl(string $id): string
    {
        if (!self::isValidPlaylistId($id)) {
            throw new \InvalidArgumentException('ID de playlist invalide');
        }

        return 'https://www.youtube.com/playlist?list=' . $id;
    }

    /**
     * Reconstruit une URL canonique a partir d'un ID valide.
     * C'est cette URL, et jamais l'entree utilisateur, qui est passee a yt-dlp.
     */
    public static function canonicalUrl(string $id): string
    {
        if (!self::isValidId($id)) {
            throw new \InvalidArgumentException('ID video invalide');
        }

        return 'https://www.youtube.com/watch?v=' . $id;
    }
}
