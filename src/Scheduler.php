<?php

declare(strict_types=1);

namespace App;

/**
 * File d'attente des telechargements.
 *
 * Il n'y a pas de demon : chaque fois que quelque chose change (nouveau job,
 * fin d'un worker, annulation, simple consultation de la liste), on appelle
 * dispatch(), qui demarre les jobs en attente tant qu'il reste de la place.
 * Tout se passe sous le verrou du JobStore.
 */
final class Scheduler
{
    /** Delai (s) au-dela duquel un job "starting" sans worker est mort. */
    private const START_TIMEOUT = 60;

    /** Age (s) d'un job "running" sans nouvelle a partir duquel on verifie son PID. */
    private const STALE_AFTER = 10;

    public function __construct(private readonly JobStore $store)
    {
    }

    /**
     * Ajoute un job a la file. Si un job identique (meme video, meme
     * format) est deja en attente ou en cours, on le renvoie au lieu d'en
     * creer un second.
     *
     * @return array{job_id:string, duplicate:bool}
     */
    public function enqueue(string $videoId, string $format, string $title): array
    {
        return $this->store->withLock(function () use ($videoId, $format, $title): array {
            foreach ($this->store->all() as $job) {
                if (JobStore::isActive($job) && $job['video_id'] === $videoId && $job['format'] === $format) {
                    return ['job_id' => (string) $job['job_id'], 'duplicate' => true];
                }
            }

            $jobId = $this->store->create([
                'video_id' => $videoId,
                'format' => $format,
                'title' => $title,
                'status' => 'queued',
                'progress' => null,
                'speed' => null,
                'eta' => null,
                'stage' => null,
                'file' => null,
                'size' => null,
                'error' => null,
            ]);

            $this->dispatchLocked();

            return ['job_id' => $jobId, 'duplicate' => false];
        });
    }

    /**
     * Demarre les jobs en attente tant qu'il y a de la place.
     */
    public function dispatch(): void
    {
        $this->store->withLock(fn () => $this->dispatchLocked());
    }

    /**
     * Demande l'annulation d'un job. Un job qui n'a pas demarre est annule
     * tout de suite ; un job en cours passe en "cancelling", on tue yt-dlp,
     * et c'est le worker qui constate l'arret et finalise.
     */
    public function cancel(string $jobId): ?array
    {
        return $this->store->withLock(function () use ($jobId): ?array {
            $job = $this->store->get($jobId);
            if ($job === null) {
                return null;
            }

            switch ($job['status']) {
                case 'queued':
                    $this->store->update($jobId, ['status' => 'cancelled', 'error' => null]);
                    break;
                case 'starting':
                case 'running':
                    $this->store->update($jobId, ['status' => 'cancelling']);
                    $pid = (int) ($job['ytdlp_pid'] ?? 0);
                    if ($pid > 0) {
                        Process::kill($pid);
                    }
                    break;
                default:
                    // Deja termine ou deja en cours d'annulation : rien a faire.
                    break;
            }

            $this->dispatchLocked();

            return $this->store->get($jobId);
        });
    }

    /**
     * A appeler sous verrou. Repere d'abord les workers morts, puis lance
     * les jobs en attente dans l'ordre d'arrivee.
     */
    private function dispatchLocked(): void
    {
        $jobs = $this->store->all();
        $running = 0;
        $now = time();

        foreach ($jobs as $job) {
            $status = (string) $job['status'];
            $jobId = (string) $job['job_id'];

            if ($status === 'starting') {
                if ($now - (int) $job['updated_at'] > self::START_TIMEOUT) {
                    $this->store->update($jobId, ['status' => 'error', 'error' => 'Le worker n\'a pas demarre']);
                    continue;
                }
                $running++;
                continue;
            }

            if ($status === 'running' || $status === 'cancelling') {
                // Un worker qui tourne ecrit regulierement, sauf pendant la
                // fusion ffmpeg : on ne verifie son PID que s'il est silencieux.
                if ($now - (int) $job['updated_at'] > self::STALE_AFTER
                    && !Process::isAlive((int) ($job['worker_pid'] ?? 0))) {
                    $this->store->update($jobId, [
                        'status' => $status === 'cancelling' ? 'cancelled' : 'error',
                        'error' => $status === 'cancelling' ? null : 'Le worker s\'est arrete sans finir',
                        'speed' => null,
                        'eta' => null,
                    ]);
                    continue;
                }
                $running++;
            }
        }

        foreach ($jobs as $job) {
            if ($running >= Config::MAX_CONCURRENT) {
                break;
            }
            if ($job['status'] !== 'queued') {
                continue;
            }
            $jobId = (string) $job['job_id'];
            $this->store->update($jobId, ['status' => 'starting']);
            try {
                YtDlp::spawnWorker(Config::workerScript(), $jobId);
                $running++;
            } catch (\RuntimeException $e) {
                $this->store->update($jobId, ['status' => 'error', 'error' => $e->getMessage()]);
            }
        }
    }
}
