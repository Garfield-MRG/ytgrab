<?php

declare(strict_types=1);

namespace App;

/**
 * Wrapper des binaires externes (yt-dlp, ffmpeg).
 *
 * Regle absolue : toute commande est construite sous forme de tableau
 * d'arguments et passee a proc_open(). Jamais de shell, jamais de
 * concatenation de chaine avec une entree utilisateur.
 */
final class YtDlp
{
    /** Binaires requis au fonctionnement de l'appli. */
    public const REQUIRED = ['yt-dlp', 'ffmpeg'];

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Cherche un binaire dans le PATH. Retourne son chemin absolu, ou null.
     */
    public static function findBinary(string $name): ?string
    {
        $dirs = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        $exts = self::isWindows() ? ['.exe', '.cmd', '.bat'] : [''];

        foreach ($dirs as $dir) {
            $dir = trim($dir);
            if ($dir === '') {
                continue;
            }
            foreach ($exts as $ext) {
                $candidate = $dir . DIRECTORY_SEPARATOR . $name . $ext;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Execute une commande et capture stdout/stderr, avec timeout.
     *
     * @param list<string> $cmd Commande en tableau : [binaire, arg1, arg2, ...]
     * @return array{exit:int, stdout:string, stderr:string}
     */
    public static function runCapture(array $cmd, int $timeoutSeconds = 30): array
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $spec, $pipes, null, null, ['bypass_shell' => true]);
        if (!\is_resource($proc)) {
            return ['exit' => -1, 'stdout' => '', 'stderr' => 'proc_open a echoue'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $exit = -1;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($proc);
            if (!$status['running']) {
                // Premier appel apres la fin du process : exitcode est fiable ici.
                $exit = $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc);
                proc_close($proc);
                return [
                    'exit' => -1,
                    'stdout' => $stdout,
                    'stderr' => $stderr . "\n[timeout apres {$timeoutSeconds}s]",
                ];
            }
            usleep(20_000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Etat des binaires requis : trouve ou non, chemin, version.
     *
     * @return array<string, array{found:bool, path:?string, version:?string}>
     */
    public static function binaryStatus(): array
    {
        $result = [];

        foreach (self::REQUIRED as $name) {
            $path = self::findBinary($name);
            $version = null;

            if ($path !== null) {
                $args = $name === 'ffmpeg' ? [$path, '-version'] : [$path, '--version'];
                $run = self::runCapture($args, 15);
                if ($run['exit'] === 0) {
                    $firstLine = strtok($run['stdout'], "\r\n");
                    $version = $firstLine !== false ? trim($firstLine) : null;
                }
            }

            $result[$name] = [
                'found' => $path !== null && $version !== null,
                'path' => $path,
                'version' => $version,
            ];
        }

        return $result;
    }

    /**
     * Commande d'installation a suggerer quand un binaire manque.
     */
    public static function installHint(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'winget install yt-dlp.yt-dlp   (installe aussi ffmpeg, puis rouvre ton terminal)',
            'Darwin' => 'brew install yt-dlp ffmpeg',
            default => 'sudo apt install ffmpeg && pipx install yt-dlp',
        };
    }
}
