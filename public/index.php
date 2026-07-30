<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

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
        <p class="hint" id="form-hint">
            <?= $allFound
                ? 'Etape suivante : recuperation des metadonnees (a venir).'
                : 'Installe les binaires manquants pour activer le formulaire.' ?>
        </p>
    </section>
</main>
<script src="assets/app.js"></script>
</body>
</html>
