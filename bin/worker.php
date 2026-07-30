<?php

/**
 * Worker de telechargement, lance en process detache par /api/download.
 *
 * Usage : php bin/worker.php <job_id>
 *
 * Il execute yt-dlp, lit sa sortie ligne par ligne (--newline +
 * --progress-template) et ecrit l'avancement dans le JSON du job.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\JobStore;
use App\YtDlp;

$baseDir = dirname(__DIR__);
$downloadsDir = $baseDir . '/storage/downloads';
$jobsDir = $baseDir . '/storage/jobs';

$jobId = $argv[1] ?? '';
if (!JobStore::isValidJobId($jobId)) {
    fwrite(STDERR, "job id invalide\n");
    exit(1);
}

$store = new JobStore($jobsDir);
$job = $store->get($jobId);
if ($job === null) {
    fwrite(STDERR, "job introuvable\n");
    exit(1);
}

$fail = function (string $message) use ($store, $jobId): never {
    $store->update($jobId, [
        'status' => 'error',
        'error' => $message,
        'speed' => null,
        'eta' => null,
    ]);
    exit(1);
};

try {
    $args = YtDlp::downloadArgs((string) $job['video_id'], (string) $job['format'], $downloadsDir);
} catch (\Throwable $e) {
    $fail($e->getMessage());
}

// stderr part dans un fichier : lire deux pipes bloquants en parallele est
// fragile sous Windows, un seul pipe (stdout) suffit pour la progression.
$stderrFile = $jobsDir . DIRECTORY_SEPARATOR . $jobId . '.stderr';
$spec = [
    0 => ['file', YtDlp::isWindows() ? 'NUL' : '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['file', $stderrFile, 'w'],
];

$proc = proc_open($args, $spec, $pipes, null, null, ['bypass_shell' => true]);
if (!\is_resource($proc)) {
    $fail('Impossible de lancer yt-dlp');
}

$procStatus = proc_get_status($proc);
$store->update($jobId, [
    'status' => 'running',
    'worker_pid' => getmypid(),
    'ytdlp_pid' => $procStatus['pid'],
]);

$plainLines = [];
$lastWrite = 0.0;

while (($line = fgets($pipes[1])) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    if (!str_starts_with($line, 'PROGRESS|')) {
        // Sortie normale de yt-dlp, dont le chemin final du fichier.
        $plainLines[] = $line;
        continue;
    }

    // PROGRESS|downloaded|total|total_estimate|speed|eta|status
    $parts = explode('|', $line);
    if (\count($parts) < 7) {
        continue;
    }

    $num = static fn (string $v): ?float => is_numeric($v) ? (float) $v : null;
    $downloaded = $num($parts[1]);
    $total = $num($parts[2]) ?? $num($parts[3]);
    $speed = $num($parts[4]);
    $eta = $num($parts[5]);
    $stage = $parts[6];

    $percent = null;
    if ($downloaded !== null && $total !== null && $total > 0) {
        $percent = min(100.0, round($downloaded / $total * 100, 1));
    }

    // Fin du telechargement d'un flux : la suite (fusion video+audio ou
    // conversion mp3) tourne dans ffmpeg sans progression exploitable.
    if ($stage === 'finished') {
        $store->update($jobId, [
            'progress' => $percent ?? 100.0,
            'speed' => null,
            'eta' => null,
            'stage' => 'processing',
        ]);
        $lastWrite = microtime(true);
        continue;
    }

    // On limite la frequence d'ecriture du JSON, le front poll a 500ms.
    $now = microtime(true);
    if ($now - $lastWrite < 0.25) {
        continue;
    }
    $lastWrite = $now;

    $store->update($jobId, [
        'progress' => $percent,
        'speed' => $speed,
        'eta' => $eta,
        'stage' => 'downloading',
    ]);
}

fclose($pipes[1]);
$exitCode = proc_close($proc);
$stderr = @file_get_contents($stderrFile);
@unlink($stderrFile);

if ($exitCode !== 0) {
    $fail(YtDlp::shortError($stderr === false ? '' : $stderr));
}

$filePath = YtDlp::extractFinalPath(implode("\n", $plainLines), $downloadsDir);
if ($filePath === null) {
    $fail('Telechargement termine mais fichier introuvable');
}

$store->update($jobId, [
    'status' => 'finished',
    'progress' => 100.0,
    'speed' => null,
    'eta' => null,
    'stage' => null,
    'file' => basename($filePath),
    'size' => (int) filesize($filePath),
]);
