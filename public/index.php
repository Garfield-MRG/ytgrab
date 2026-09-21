<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config;
use App\JobStore;
use App\Scheduler;
use App\UrlValidator;
use App\YtDlp;

define('BASE_DIR', Config::baseDir());
define('DOWNLOADS_DIR', Config::downloadsDir());
define('JOBS_DIR', Config::jobsDir());

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
    // On ne sert que les fichiers produits par ytgrab (suffixe [id video]) :
    // le dossier est le Telechargements de l'utilisateur, ses fichiers
    // personnels ne doivent pas etre accessibles.
    if (!YtDlp::isManagedFile($name)) {
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

/**
 * Titre affiche dans la file : vient du client (il l'a recu de /api/metadata),
 * ne sert qu'a l'affichage, on le nettoie quand meme.
 */
function clean_title(mixed $title): string
{
    $title = \is_string($title) ? $title : '';
    $title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title) ?? '';

    return mb_substr(trim($title), 0, 200);
}

if ($path === '/api/download' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    $body = \is_array($body) ? $body : [];
    $format = (string) ($body['format'] ?? '');

    if (!YtDlp::isValidFormat($format)) {
        json_response(['error' => 'Format invalide'], 422);
    }

    // Une video ({id, title}) ou plusieurs ({items: [{id, title}, ...]}).
    $items = isset($body['items']) && \is_array($body['items'])
        ? $body['items']
        : [['id' => $body['id'] ?? '', 'title' => $body['title'] ?? '']];

    if ($items === [] || \count($items) > Config::PLAYLIST_LIMIT) {
        json_response(['error' => 'Entre 1 et ' . Config::PLAYLIST_LIMIT . ' videos par requete'], 422);
    }
    foreach ($items as $item) {
        if (!\is_array($item) || !UrlValidator::isValidId((string) ($item['id'] ?? ''))) {
            json_response(['error' => 'ID video invalide'], 422);
        }
    }

    $scheduler = new Scheduler(new JobStore(JOBS_DIR));
    $jobs = [];
    foreach ($items as $item) {
        $jobs[] = $scheduler->enqueue((string) $item['id'], $format, clean_title($item['title'] ?? ''));
    }

    json_response(['jobs' => $jobs, 'job_id' => $jobs[0]['job_id']], 202);
}

if ($path === '/api/jobs' && $method === 'GET') {
    $store = new JobStore(JOBS_DIR);
    $store->prune(Config::JOB_RETENTION);
    // dispatch() repere aussi les workers morts : la liste reste juste
    // meme si un worker a ete tue sans passer par l'appli.
    (new Scheduler($store))->dispatch();

    json_response(['jobs' => array_reverse($store->all())]);
}

if ($path === '/api/cancel' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    $jobId = \is_array($body) ? (string) ($body['id'] ?? '') : '';
    if (!JobStore::isValidJobId($jobId)) {
        json_response(['error' => 'ID de job invalide'], 422);
    }

    $job = (new Scheduler(new JobStore(JOBS_DIR)))->cancel($jobId);
    if ($job === null) {
        json_response(['error' => 'Job inconnu'], 404);
    }

    json_response($job);
}

if ($path === '/api/jobs/remove' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    $jobId = \is_array($body) ? (string) ($body['id'] ?? '') : '';
    $store = new JobStore(JOBS_DIR);

    if ($jobId === 'all') {
        // Retire tous les jobs termines d'un coup.
        foreach ($store->all() as $job) {
            if (!JobStore::isActive($job)) {
                $store->delete((string) $job['job_id']);
            }
        }
        json_response(['ok' => true]);
    }

    if (!JobStore::isValidJobId($jobId)) {
        json_response(['error' => 'ID de job invalide'], 422);
    }
    $job = $store->get($jobId);
    if ($job === null) {
        json_response(['error' => 'Job inconnu'], 404);
    }
    if (JobStore::isActive($job)) {
        json_response(['error' => 'Annule le job avant de le retirer'], 409);
    }
    $store->delete($jobId);

    json_response(['ok' => true]);
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
        // Seuls les fichiers produits par ytgrab, jamais le reste du dossier.
        if (!YtDlp::isManagedFile($name)) {
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
                    <button type="button" id="download-btn">Ajouter a la file</button>
                </div>
                <p class="hint" id="preview-hint"></p>
            </div>
        </div>
    </section>

    <section class="card" id="jobs">
        <div class="card-head">
            <h2>File d'attente</h2>
            <button type="button" class="ghost-btn hidden" id="jobs-clear">Retirer les termines</button>
        </div>
        <ul class="jobs-list" id="jobs-list"></ul>
        <p class="hint" id="jobs-empty">Aucun telechargement en cours.</p>
    </section>

    <section class="card">
        <h2>Fichiers telecharges</h2>
        <p class="hint folder-hint">Dossier : <?= e(DOWNLOADS_DIR) ?></p>
        <ul class="files-list" id="files-list"></ul>
        <p class="hint hidden" id="files-empty">Aucun fichier pour l'instant.</p>
    </section>
</main>
<script src="assets/app.js"></script>
</body>
</html>
