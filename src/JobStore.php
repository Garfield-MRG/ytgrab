<?php

declare(strict_types=1);

namespace App;

/**
 * Etat des jobs de telechargement : un fichier JSON par job dans
 * storage/jobs/. Ecrit par le worker et le planificateur, lu par l'API.
 *
 * Cycle de vie d'un job :
 *   queued -> starting -> running -> finished | error | cancelled
 * Un job queued ou running peut passer par cancelling avant cancelled.
 */
final class JobStore
{
    /** Statuts d'un job qui n'a pas encore fini. */
    public const ACTIVE = ['queued', 'starting', 'running', 'cancelling'];

    public function __construct(private readonly string $dir)
    {
    }

    /**
     * Un ID de job : 16 caracteres hexadecimaux generes par le serveur.
     * Valide strictement avant tout acces disque (pas de path traversal).
     */
    public static function isValidJobId(string $jobId): bool
    {
        return preg_match('#^[a-f0-9]{16}$#', $jobId) === 1;
    }

    /**
     * @param array<string, mixed> $job
     */
    public static function isActive(array $job): bool
    {
        return \in_array($job['status'] ?? '', self::ACTIVE, true);
    }

    /**
     * Cree un job et retourne son ID.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): string
    {
        $jobId = bin2hex(random_bytes(8));

        $this->write($jobId, $data + [
            'job_id' => $jobId,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $jobId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $jobId): ?array
    {
        $raw = @file_get_contents($this->pathFor($jobId));
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);

        return \is_array($data) ? $data : null;
    }

    /**
     * Tous les jobs, du plus ancien au plus recent.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $jobs = [];
        foreach (scandir($this->dir) ?: [] as $name) {
            if (!str_ends_with($name, '.json')) {
                continue;
            }
            $jobId = substr($name, 0, -5);
            if (!self::isValidJobId($jobId)) {
                continue;
            }
            $job = $this->get($jobId);
            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        usort($jobs, static function (array $a, array $b): int {
            return [(int) $a['created_at'], (string) $a['job_id']] <=> [(int) $b['created_at'], (string) $b['job_id']];
        });

        return $jobs;
    }

    /**
     * Fusionne les champs donnes dans le job existant.
     *
     * @param array<string, mixed> $patch
     */
    public function update(string $jobId, array $patch): void
    {
        $job = $this->get($jobId);
        if ($job === null) {
            throw new \RuntimeException('Job inconnu : ' . $jobId);
        }

        $this->write($jobId, array_merge($job, $patch, ['updated_at' => time()]));
    }

    public function delete(string $jobId): void
    {
        @unlink($this->pathFor($jobId));
    }

    /**
     * Oublie les jobs termines depuis plus de $maxAge secondes.
     */
    public function prune(int $maxAge): void
    {
        $limit = time() - $maxAge;
        foreach ($this->all() as $job) {
            if (!self::isActive($job) && (int) $job['updated_at'] < $limit) {
                $this->delete((string) $job['job_id']);
            }
        }
    }

    /**
     * Execute $fn sous verrou exclusif. Le routeur et les workers prennent
     * des decisions sur l'ensemble des jobs (qui demarre ensuite ?) : sans
     * verrou, deux process pourraient lancer le meme job.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withLock(callable $fn): mixed
    {
        $fh = fopen($this->dir . DIRECTORY_SEPARATOR . '.lock', 'c');
        if ($fh === false) {
            throw new \RuntimeException('Impossible d\'ouvrir le verrou des jobs');
        }

        flock($fh, LOCK_EX);
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $jobId, array $data): void
    {
        $path = $this->pathFor($jobId);
        $tmp = $path . '.tmp';

        // Ecriture atomique : le lecteur ne voit jamais un JSON a moitie ecrit.
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($tmp, $path);
    }

    private function pathFor(string $jobId): string
    {
        if (!self::isValidJobId($jobId)) {
            throw new \InvalidArgumentException('ID de job invalide');
        }

        return $this->dir . DIRECTORY_SEPARATOR . $jobId . '.json';
    }
}
