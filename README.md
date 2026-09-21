# ytgrab

Telechargeur YouTube local et self-hosted. Tu colles une URL, tu choisis le
format, ca part dans une file d'attente et ca telecharge directement dans ton
dossier Telechargements avec la progression en direct. Usage strictement
personnel, sur ta machine, en local uniquement.

## Fonctionnalites

- Preview de la video au collage de l'URL : titre, miniature, duree,
  resolutions disponibles
- Choix du format : meilleure qualite mp4, resolution precise, ou audio seul
  en mp3
- File d'attente : les videos se telechargent une par une, dans l'ordre
  d'ajout, chacune avec sa progression (pourcentage, vitesse, temps restant)
- Annulation d'un telechargement en attente ou en cours, avec nettoyage des
  fichiers partiels
- Playlists : colle une URL `youtube.com/playlist?list=...`, l'appli liste
  les videos (50 au maximum) et les ajoute toutes a la file dans le format
  choisi
- Bouton de mise a jour de yt-dlp depuis l'interface (YouTube casse
  regulierement les vieilles versions, ca evite d'ouvrir un terminal)
- Liste des fichiers telecharges, lecture dans le navigateur (streaming avec
  support des requetes Range) ou telechargement
- Les telechargements survivent a la fermeture de l'onglet : le worker
  tourne en process detache et la page retrouve la file au rechargement

## Prerequis

- PHP 8.3 ou plus recent
- Composer (uniquement pour generer l'autoload)
- yt-dlp
- ffmpeg

### Installer yt-dlp et ffmpeg

**Windows**

```
winget install yt-dlp.yt-dlp
```

Le paquet winget installe aussi ffmpeg. Rouvre ton terminal apres
l'installation pour que le PATH soit a jour.

**macOS**

```
brew install yt-dlp ffmpeg
```

**Linux (Debian/Ubuntu)**

```
sudo apt install ffmpeg
pipx install yt-dlp
```

La version apt de yt-dlp est souvent trop vieille, prefere pipx (ou
`python3 -m pip install -U yt-dlp`).

## Installation

```
composer dump-autoload
```

C'est tout. Aucune dependance, Composer ne sert qu'a generer l'autoload PSR-4.

## Lancement

```
php -S 127.0.0.1:8080 -t public/
```

Puis ouvre http://127.0.0.1:8080 dans ton navigateur.

Sur macOS et Linux, lance plutot :

```
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t public/
```

Le serveur integre de PHP est mono-thread par defaut : avec plusieurs workers,
le polling de progression reste fluide meme quand une requete est occupee.
Cette variable n'a pas d'effet sur Windows (limitation de PHP), mais les
telechargements tournent dans des process detaches donc aucune requete ne
bloque longtemps.

Le serveur ecoute uniquement sur 127.0.0.1 : rien n'est accessible depuis
l'exterieur de la machine.

## Arborescence

```
public/index.php      routeur + vue unique
public/assets/        CSS et JS vanilla, aucun build
bin/worker.php        worker de telechargement (process detache)
src/Config.php        chemins et reglages partages entre routeur et worker
src/YtDlp.php         wrapper du binaire yt-dlp
src/JobStore.php      etat des jobs (1 fichier JSON par job) + verrou
src/Scheduler.php     file d'attente : qui demarre, qui s'annule
src/Process.php       PID vivant ? tuer un process ? (sans extension posix)
src/UrlValidator.php  validation d'URL + extraction de l'ID video/playlist
storage/downloads/    dossier de secours si Telechargements introuvable
storage/jobs/         etat des jobs
```

## Ou vont les fichiers

Les fichiers atterrissent dans le dossier Telechargements de ta session
(`%USERPROFILE%\Downloads` sous Windows, `~/Downloads` sinon), avec
`storage/downloads/` en secours si ce dossier n'existe pas. Le dossier utilise
est affiche dans l'interface.

Comme ce dossier contient aussi tes fichiers personnels, l'appli ne liste et
ne sert que les fichiers produits par ytgrab, reconnaissables au suffixe
`[id video]` impose par le template de nommage. Le reste du dossier n'est
jamais expose.

## API

Tous les endpoints passent par `index.php/api/...` (voir Notes techniques).

| Endpoint | Methode | Role |
|---|---|---|
| `/api/health` | GET | Etat des binaires yt-dlp et ffmpeg |
| `/api/metadata` | POST `{url}` | Preview d'une video (titre, miniature, duree, resolutions) ou d'une playlist (`type: playlist`, liste des videos) |
| `/api/download` | POST `{format, items: [{id, title}, ...]}` | Ajoute les videos a la file, repond 202 avec un `job_id` par video (`duplicate: true` si elle y etait deja). `{id, format, title}` marche aussi pour une seule video |
| `/api/jobs` | GET | Tous les jobs, du plus recent au plus ancien, avec statut et progression |
| `/api/status?id=X` | GET | Etat d'un seul job |
| `/api/cancel` | POST `{id}` | Annule un job en attente ou en cours |
| `/api/jobs/remove` | POST `{id}` | Retire un job termine de la liste (`id: "all"` pour tous les termines) |
| `/api/update-ytdlp` | POST | Lance `yt-dlp -U`, refuse (409) si un telechargement tourne |
| `/api/files` | GET | Liste des fichiers telecharges |
| `/api/file?name=X` | GET | Streaming d'un fichier (`&dl=1` pour forcer le telechargement) |

Le front poll `/api/jobs` toutes les 700 ms tant qu'un job est actif, puis
s'arrete.

Les formats acceptes par `/api/download` : `best` (meilleure qualite mp4),
une hauteur en pixels (`1080`, `720`, ...), ou `mp3`.

Statuts d'un job : `queued`, `starting`, `running`, `cancelling`, puis
`finished`, `error` ou `cancelled`. Les jobs termines sont oublies au bout
de 24 h.

## Notes techniques

- Les commandes externes sont lancees via `proc_open()` avec la commande en
  tableau d'arguments : aucune entree utilisateur ne passe par un shell.
- L'URL collee n'est jamais transmise telle quelle a yt-dlp : on extrait l'ID
  video avec une regex stricte puis on reconstruit une URL canonique.
- Les appels API passent par `index.php/api/...` car le serveur integre de
  PHP ne reecrit pas les URLs.
- Le telechargement tourne dans `bin/worker.php`, lance en process detache
  (`start /b` sous Windows, `sh -c '... &'` sous POSIX) : il survit a la fin
  de la requete HTTP et ecrit sa progression dans le JSON du job, que
  `/api/jobs` se contente de relire.
- Pas de demon pour la file d'attente. A chaque evenement (ajout, fin d'un
  worker, annulation, consultation de la liste), `Scheduler::dispatch()`
  prend un verrou (`flock` sur `storage/jobs/.lock`), repere les workers
  morts et demarre le job suivant s'il y a de la place. Le nombre de
  telechargements simultanes est `Config::MAX_CONCURRENT` (1 par defaut).
- L'annulation tue yt-dlp (`taskkill /T` sous Windows, `kill` sinon) ; c'est
  le worker qui constate l'arret, supprime les `.part` et flux intermediaires
  de la video, puis passe le job en `cancelled`.
- Une playlist est listee avec `--flat-playlist --playlist-end 50`, sans
  resoudre chaque video. Chaque entree devient ensuite un job ordinaire :
  yt-dlp n'a jamais a telecharger une playlist entiere d'un coup.
- Les noms de fichiers viennent du template yt-dlp
  `%(title)s [%(id)s].%(ext)s` avec `--restrict-filenames`, et tout chemin
  est verifie par `realpath()` avant d'etre servi ou accepte (protection
  path traversal).
- Retelechargez la meme video dans une autre qualite et yt-dlp verra le
  fichier existant (meme nom) et ne retelechargera pas : supprimez d'abord
  le fichier si vous voulez changer de qualite.

## Licence

MIT, voir le fichier [LICENSE](LICENSE).
