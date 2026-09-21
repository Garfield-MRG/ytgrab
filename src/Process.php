<?php

declare(strict_types=1);

namespace App;

/**
 * Vie et mort des process externes, sans extension posix ni pcntl (elles
 * ne sont pas disponibles sous Windows). Tout passe par les outils systeme,
 * toujours en tableau d'arguments et avec un PID entier.
 */
final class Process
{
    /**
     * Vrai si un process avec ce PID tourne encore.
     */
    public static function isAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (YtDlp::isWindows()) {
            // tasklist affiche une ligne CSV par process trouve, ou un
            // message d'information quand rien ne correspond au filtre.
            $run = YtDlp::runCapture(['tasklist', '/FI', 'PID eq ' . $pid, '/NH', '/FO', 'CSV'], 10);

            return str_contains($run['stdout'], '"' . $pid . '"');
        }

        // kill -0 ne tue rien : il verifie juste que le process existe.
        $run = YtDlp::runCapture(['kill', '-0', (string) $pid], 10);

        return $run['exit'] === 0;
    }

    /**
     * Arrete un process et ses enfants (yt-dlp lance ffmpeg en sous-process).
     */
    public static function kill(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        if (YtDlp::isWindows()) {
            YtDlp::runCapture(['taskkill', '/F', '/T', '/PID', (string) $pid], 10);

            return;
        }

        YtDlp::runCapture(['kill', '-TERM', (string) $pid], 10);
    }
}
