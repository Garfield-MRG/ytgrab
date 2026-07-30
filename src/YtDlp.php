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
     * Recupere les metadonnees d'une video via `yt-dlp -J`.
     *
     * Ne prend qu'un ID video deja valide : l'URL passee a yt-dlp est
     * toujours l'URL canonique reconstruite, jamais l'entree utilisateur.
     *
     * @return array{id:string, title:string, channel:string, thumbnail:string, duration:int, heights:list<int>}
     */
    public static function fetchMetadata(string $videoId): array
    {
        $bin = self::findBinary('yt-dlp');
        if ($bin === null) {
            throw new \RuntimeException('yt-dlp est introuvable dans le PATH');
        }

        $url = UrlValidator::canonicalUrl($videoId);
        $run = self::runCapture([$bin, '-J', '--no-playlist', $url], 60);

        if ($run['exit'] !== 0) {
            throw new \RuntimeException(self::shortError($run['stderr']));
        }

        $data = json_decode($run['stdout'], true);
        if (!\is_array($data)) {
            throw new \RuntimeException('Reponse illisible de yt-dlp');
        }

        // Resolutions video disponibles, triees de la plus haute a la plus basse.
        $heights = [];
        foreach ($data['formats'] ?? [] as $format) {
            if (($format['vcodec'] ?? 'none') !== 'none' && !empty($format['height'])) {
                $heights[(int) $format['height']] = true;
            }
        }
        $heights = array_keys($heights);
        rsort($heights);

        return [
            'id' => $videoId,
            'title' => (string) ($data['title'] ?? ''),
            'channel' => (string) ($data['channel'] ?? $data['uploader'] ?? ''),
            'thumbnail' => (string) ($data['thumbnail'] ?? ''),
            'duration' => (int) ($data['duration'] ?? 0),
            'heights' => $heights,
        ];
    }

    /**
     * Vrai si le nom de fichier vient bien de ytgrab : le template de sortie
     * impose un suffixe [id video]. Le dossier de telechargement etant le
     * dossier Telechargements de l'utilisateur, ce filtre garantit qu'on ne
     * liste ni ne sert jamais ses fichiers personnels.
     */
    public static function isManagedFile(string $name): bool
    {
        return preg_match('#\[[A-Za-z0-9_-]{11}\]\.[A-Za-z0-9]{2,5}$#', $name) === 1;
    }

    /**
     * Vrai si le format demande est accepte : best, mp3, ou une hauteur en pixels.
     */
    public static function isValidFormat(string $format): bool
    {
        return preg_match('#^(best|mp3|\d{3,4})$#', $format) === 1;
    }

    /**
     * Construit la commande yt-dlp de telechargement, en tableau d'arguments.
     *
     * @return list<string>
     */
    public static function downloadArgs(string $videoId, string $format, string $outDir): array
    {
        $bin = self::findBinary('yt-dlp');
        if ($bin === null) {
            throw new \RuntimeException('yt-dlp est introuvable dans le PATH');
        }
        if (!self::isValidFormat($format)) {
            throw new \InvalidArgumentException('Format inconnu : ' . $format);
        }

        $args = [
            $bin,
            '--no-playlist',
            '--restrict-filenames',
            '--newline',
            // --print implique --quiet, mais --progress reactive les lignes de
            // progression : on garde un stdout parsable ligne par ligne.
            '--no-simulate',
            '--progress',
            '--print', 'after_move:filepath',
            // Une ligne PROGRESS| parsable par le worker a chaque mise a jour.
            '--progress-template',
            'download:PROGRESS|%(progress.downloaded_bytes)s|%(progress.total_bytes)s|%(progress.total_bytes_estimate)s|%(progress.speed)s|%(progress.eta)s|%(progress.status)s',
            '-o', $outDir . DIRECTORY_SEPARATOR . '%(title)s [%(id)s].%(ext)s',
        ];

        if ($format === 'mp3') {
            array_push($args, '-f', 'bestaudio/best', '--extract-audio', '--audio-format', 'mp3');
        } elseif ($format === 'best') {
            array_push($args, '-f', 'bestvideo+bestaudio/best', '--merge-output-format', 'mp4');
        } else {
            $h = (int) $format;
            array_push(
                $args,
                '-f',
                "bestvideo[height<={$h}]+bestaudio/best[height<={$h}]",
                '--merge-output-format',
                'mp4',
            );
        }

        $args[] = UrlValidator::canonicalUrl($videoId);

        return $args;
    }

    /**
     * Lance le worker de telechargement en process detache : la requete HTTP
     * rend la main tout de suite, le worker survit a la fin de la requete.
     *
     * Le job id est genere par le serveur (16 caracteres hexadecimaux) et les
     * chemins viennent de la configuration : aucune entree utilisateur ici.
     */
    public static function spawnWorker(string $workerScript, string $jobId): void
    {
        if (!JobStore::isValidJobId($jobId)) {
            throw new \InvalidArgumentException('ID de job invalide');
        }

        if (self::isWindows()) {
            // `start /b` rend la main immediatement : proc_close n'attend que
            // cmd, pas le worker. En tableau d'arguments, PHP quote `start` et
            // cmd ne reconnait plus sa commande interne, donc cette couche de
            // detachement passe par une chaine. C'est la seule du projet, et
            // elle ne contient aucune entree utilisateur : le job id est
            // valide ([a-f0-9]{16}) et les chemins viennent de la config.
            // Le "" est le titre de fenetre que start attend quand un
            // argument est quote.
            $quote = static fn (string $p): string => '"' . str_replace('"', '', $p) . '"';
            $cmd = 'start /b "" ' . $quote(PHP_BINARY) . ' ' . $quote($workerScript) . ' ' . $jobId;
            $options = []; // bypass_shell absent : la chaine passe par cmd /c
        } else {
            // sh met le worker en arriere-plan puis sort tout de suite. La
            // chaine -c est un litteral fige, les valeurs passent en arguments
            // positionnels : rien n'est interpole dans le shell.
            $cmd = ['sh', '-c', 'exec "$0" "$1" "$2" < /dev/null > /dev/null 2>&1 &', PHP_BINARY, $workerScript, $jobId];
            $options = ['bypass_shell' => true];
        }

        $proc = proc_open($cmd, [], $pipes, null, null, $options);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('Impossible de lancer le worker');
        }
        proc_close($proc);
    }

    /**
     * Retrouve le chemin final imprime par `--print after_move:filepath` et
     * verifie qu'il reste bien dans le dossier de telechargement
     * (protection contre le path traversal).
     */
    public static function extractFinalPath(string $stdout, string $outDir): ?string
    {
        $realOutDir = realpath($outDir);
        if ($realOutDir === false) {
            return null;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $stdout))));
        foreach (array_reverse($lines) as $line) {
            if (!is_file($line)) {
                continue;
            }
            $real = realpath($line);
            if ($real !== false && str_starts_with($real, $realOutDir . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * Extrait un message d'erreur court et utile du stderr de yt-dlp.
     */
    public static function shortError(string $stderr): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $stderr))));
        foreach (array_reverse($lines) as $line) {
            if (str_starts_with($line, 'ERROR')) {
                return $line;
            }
        }

        return $lines === [] ? 'yt-dlp a echoue sans message' : end($lines);
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
