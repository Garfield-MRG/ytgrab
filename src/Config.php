<?php

declare(strict_types=1);

namespace App;

/**
 * Chemins de l'application, partages entre le routeur et le worker.
 */
final class Config
{
    public static function baseDir(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * Dossier ou atterrissent les fichiers : le dossier Telechargements de
     * l'utilisateur, avec storage/downloads en secours s'il est introuvable.
     */
    public static function downloadsDir(): string
    {
        $home = (string) getenv(YtDlp::isWindows() ? 'USERPROFILE' : 'HOME');
        if ($home !== '') {
            $downloads = $home . DIRECTORY_SEPARATOR . 'Downloads';
            if (is_dir($downloads)) {
                return $downloads;
            }
        }

        return self::baseDir() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'downloads';
    }

    public static function jobsDir(): string
    {
        return self::baseDir() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'jobs';
    }
}
