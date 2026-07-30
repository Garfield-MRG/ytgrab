<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\JobStore;
use App\UrlValidator;
use App\YtDlp;

const BASE_DIR = __DIR__ . '/..';
const DOWNLOADS_DIR = BASE_DIR . '/storage/downloads';
const JOBS_DIR = BASE_DIR . '/storage/jobs';

foreach ([DOWNLOADS_DIR, JOBS_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

/**
 * Resolution de la route.
 *
 * Le serveur integre `php -S 127.0.0.1:8080 -t public/` ne reecrit pas les
 * URLs : une requete sur /api/health renverrait un 404 car aucun fichier
 * n'existe a ce chemin. Le front appelle donc index.php/api/... et on lit
 * PATH_INFO. On garde aussi le chemin brut en secours pour que /api/...
 * fonctionne si un jour le serveur est lance avec un script routeur.
 */
function resolve_path(): string
{
    $pathInfo = $_SERVER['PATH_INFO'] ?? '';
    if ($pathInfo !== '') {
        return $pathInfo;
    }

    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = preg_replace('#^/index\.php#', '', $uri);

    return $path === '' || $path === null ? '/' : $path;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Sert un fichier de storage/downloads/ en streaming, avec support des
 * requetes Range (un seul intervalle) pour que la lecture video puisse
 * avancer/reculer dans le navigateur.
 */
function serve_download_file(string $name, bool $attachment): never
{
    // Le nom ne doit etre qu'un nom de fichier : pas de separateur de
    // chemin, pas de fichier cache. Puis realpath doit rester dans le
    // dossier de telechargements.
    if ($name === '' || $name[0] === '.' || preg_match('#[/\\\\]#', $name) === 1) {
        http_response_code(404);
        exit;
    }
    $base = realpath(DOWNLOADS_DIR);
    $real = realpath(DOWNLOADS_DIR . DIRECTORY_SEPARATOR . $name);
    if ($base === false || $real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
        http_response_code(404);
        exit;
    }

    $size = (int) filesize($real);
    $mime = match (strtolower(pathinfo($real, PATHINFO_EXTENSION))) {
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'opus' => 'audio/ogg',
        default => 'application/octet-stream',
    };

    $start = 0;
    $end = $size - 1;
    $status = 200;
    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if (preg_match('#^bytes=(\d*)-(\d*)$#', $range, $m) === 1 && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') {
            // Forme suffixe : les N derniers octets.
            $start = max(0, $size - (int) $m[2]);
        } else {
            $start = (int) $m[1];
            if ($m[2] !== '') {
                $end = min($end, (int) $m[2]);
            }
        }
        if ($start >= $size || $start > $end) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $status = 206;
    }

    http_response_code($status);
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    if ($status === 206) {
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }
    $disposition = $attachment ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $name) . '"');

    $fh = fopen($real, 'rb');
    if ($fh === false) {
        exit;
    }
    fseek($fh, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fh)) {
        $chunk = fread($fh, (int) min(1 << 20, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= \strlen($chunk);
    }
    fclose($fh);
    exit;
}

$path = resolve_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/api/health' && $method === 'GET') {
    $binaries = YtDlp::binaryStatus();
    $allFound = !in_array(false, array_column($binaries, 'found'), true);

    json_response([
        'ok' => $allFound,
        'binaries' => $binaries,
        'install_hint' => $allFound ? null : YtDlp::installHint(),
    ]);
}

if ($path === '/api/metadata' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    $url = \is_array($body) ? (string) ($body['url'] ?? '') : '';

    $videoId = UrlValidator::extractId($url);
    if ($videoId === null) {
        json_response([
            'error' => 'URL non reconnue. Formats acceptes : youtube.com/watch?v=..., youtu.be/..., youtube.com/shorts/...',
        ], 422);
    }

    try {
        json_response(YtDlp::fetchMetadata($videoId));
    } catch (\RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 502);
    }
}

if ($path === '/api/download' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    $videoId = \is_array($body) ? (string) ($body['id'] ?? '') : '';
    $format = \is_array($body) ? (string) ($body['format'] ?? '') : '';

    if (!UrlValidator::isValidId($videoId)) {
        json_response(['error' => 'ID video invalide'], 422);
    }
    if (!YtDlp::isValidFormat($format)) {
        json_response(['error' => 'Format invalide'], 422);
    }

    $store = new JobStore(JOBS_DIR);
    $jobId = $store->create([
        'video_id' => $videoId,
        'format' => $format,
        'status' => 'queued',
        'progress' => null,
        'speed' => null,
        'eta' => null,
        'stage' => null,
        'file' => null,
        'size' => null,
        'error' => null,
    ]);

    try {
        YtDlp::spawnWorker(BASE_DIR . '/bin/worker.php', $jobId);
    } catch (\RuntimeException $e) {
        $store->update($jobId, ['status' => 'error', 'error' => $e->getMessage()]);
        json_response(['error' => $e->getMessage()], 500);
    }

    json_response(['job_id' => $jobId], 202);
}

if ($path === '/api/status' && $method === 'GET') {
    $jobId = (string) ($_GET['id'] ?? '');
    if (!JobStore::isValidJobId($jobId)) {
        json_response(['error' => 'ID de job invalide'], 422);
    }

    $job = (new JobStore(JOBS_DIR))->get($jobId);
    if ($job === null) {
        json_response(['error' => 'Job inconnu'], 404);
    }

    json_response($job);
}

if ($path === '/api/files' && $method === 'GET') {
    $files = [];
    foreach (scandir(DOWNLOADS_DIR) ?: [] as $name) {
        $full = DOWNLOADS_DIR . DIRECTORY_SEPARATOR . $name;
        if ($name === '' || $name[0] === '.' || !is_file($full)) {
            continue;
        }
        // Fichiers temporaires de yt-dlp en cours de telechargement.
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (\in_array($ext, ['part', 'ytdl', 'tmp'], true)) {
            continue;
        }
        $files[] = [
            'name' => $name,
            'size' => (int) filesize($full),
            'mtime' => (int) filemtime($full),
        ];
    }
    usort($files, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

    json_response(['files' => $files]);
}

if ($path === '/api/file' && $method === 'GET') {
    serve_download_file((string) ($_GET['name'] ?? ''), isset($_GET['dl']));
}

if (str_starts_with($path, '/api/')) {
    json_response(['error' => 'Route inconnue : ' . $path], 404);
}

if ($path !== '/') {
    http_response_code(404);
    echo 'Page introuvable';
    exit;
}

// Vue unique. La detection des binaires est faite au chargement de la page
// et le resultat est injecte dans le HTML.
$binaries = YtDlp::binaryStatus();
$allFound = !in_array(false, array_column($binaries, 'found'), true);
$installHint = YtDlp::installHint();

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ytgrab</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<main>
    <header>
        <h1>ytgrab</h1>
        <p class="subtitle">Telechargeur YouTube local</p>
    </header>

    <?php if (!$allFound): ?>
    <section class="banner error">
        <strong>Binaires manquants.</strong>
        L'application a besoin de yt-dlp et ffmpeg pour fonctionner.
        Commande d'installation :
        <code><?= e($installHint) ?></code>
        Relance ensuite le serveur.
    </section>
    <?php endif; ?>

    <section class="card" id="binaries">
        <h2>Binaires</h2>
        <ul class="binary-list">
            <?php foreach ($binaries as $name => $info): ?>
            <li class="<?= $info['found'] ? 'ok' : 'ko' ?>">
                <span class="dot"></span>
                <span class="name"><?= e($name) ?></span>
                <?php if ($info['found']): ?>
                <span class="detail"><?= e((string) $info['version']) ?></span>
                <?php else: ?>
                <span class="detail">introuvable dans le PATH</span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="card">
        <h2>Telecharger une video</h2>
        <form id="url-form" autocomplete="off">
            <input type="url" id="url-input" placeholder="Colle une URL YouTube..."
                   <?= $allFound ? '' : 'disabled' ?>>
            <button type="submit" <?= $allFound ? '' : 'disabled' ?>>Analyser</button>
        </form>
        <p class="error-msg hidden" id="form-error"></p>
        <?php if (!$allFound): ?>
        <p class="hint">Installe les binaires manquants pour activer le formulaire.</p>
        <?php endif; ?>
    </section>

    <section class="card hidden" id="preview">
        <div class="preview-body">
            <img id="preview-thumb" alt="Miniature de la video">
            <div class="preview-info">
                <p class="preview-title" id="preview-title"></p>
                <p class="preview-meta">
                    <span id="preview-channel"></span>
                    <span id="preview-duration"></span>
                </p>
                <div class="format-row">
                    <select id="format-select"></select>
                    <button type="button" id="download-btn">Telecharger</button>
                </div>
                <div class="progress-wrap hidden" id="progress-wrap">
                    <div class="progress-track">
                        <div class="progress-bar" id="progress-bar"></div>
                    </div>
                    <p class="progress-stats" id="progress-stats"></p>
                </div>
                <p class="hint" id="preview-hint"></p>
            </div>
        </div>
    </section>

    <section class="card">
        <h2>Fichiers telecharges</h2>
        <ul class="files-list" id="files-list"></ul>
        <p class="hint hidden" id="files-empty">Aucun fichier pour l'instant.</p>
    </section>
</main>
<script src="assets/app.js"></script>
</body>
</html>
