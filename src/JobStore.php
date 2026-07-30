<?php

declare(strict_types=1);

namespace App;

/**
 * Etat des jobs de telechargement : un fichier JSON par job dans
 * storage/jobs/. Ecrit par le worker, lu par l'endpoint de statut.
 */
final class JobStore
{
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
