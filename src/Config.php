<?php

declare(strict_types=1);

namespace App;

/**
 * Chemins et reglages de l'application, partages entre le routeur et le
 * worker.
 */
final class Config
{
    /** Nombre de telechargements simultanes. Au-dela, les jobs attendent. */
    public const MAX_CONCURRENT = 1;

    /** Nombre maximum de videos prises dans une playlist. */
    public const PLAYLIST_LIMIT = 50;

    /** Les jobs termines plus vieux que ca (secondes) sont oublies. */
    public const JOB_RETENTION = 86400;

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

    public static function workerScript(): string
    {
        return self::baseDir() . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'worker.php';
    }
}
