# ytgrab

Telechargeur YouTube local et self-hosted. Tu colles une URL, tu choisis le
format, ca telecharge dans `storage/downloads/` avec la progression en direct.
Usage strictement personnel, sur ta machine, en local uniquement.

## Fonctionnalites

- Preview de la video au collage de l'URL : titre, miniature, duree,
  resolutions disponibles
- Choix du format : meilleure qualite mp4, resolution precise, ou audio seul
  en mp3
- Telechargement en process detache : l'interface reste reactive, la
  progression (pourcentage, vitesse, temps restant) s'affiche en direct
- Liste des fichiers telecharges, lecture dans le navigateur (streaming avec
  support des requetes Range) ou telechargement

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
src/YtDlp.php         wrapper du binaire yt-dlp
src/JobStore.php      etat des jobs (1 fichier JSON par job)
src/UrlValidator.php  validation d'URL + extraction de l'ID video
storage/downloads/    fichiers telecharges
storage/jobs/         etat des jobs
```

## API

Tous les endpoints passent par `index.php/api/...` (voir Notes techniques).

| Endpoint | Methode | Role |
|---|---|---|
| `/api/health` | GET | Etat des binaires yt-dlp et ffmpeg |
| `/api/metadata` | POST `{url}` | Preview : titre, miniature, duree, resolutions |
| `/api/download` | POST `{id, format}` | Lance un job detache, repond 202 avec `job_id` |
| `/api/status?id=X` | GET | Etat du job : statut, progression, vitesse, ETA |
| `/api/files` | GET | Liste des fichiers telecharges |
| `/api/file?name=X` | GET | Streaming d'un fichier (`&dl=1` pour forcer le telechargement) |

Le front poll `/api/status` toutes les 500 ms pendant un telechargement.

Les formats acceptes par `/api/download` : `best` (meilleure qualite mp4),
une hauteur en pixels (`1080`, `720`, ...), ou `mp3`.

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
  `/api/status` se contente de relire.
- Les noms de fichiers viennent du template yt-dlp
  `%(title)s [%(id)s].%(ext)s` avec `--restrict-filenames`, et tout chemin
  est verifie par `realpath()` avant d'etre servi ou accepte (protection
  path traversal).
- Retelechargez la meme video dans une autre qualite et yt-dlp verra le
  fichier existant (meme nom) et ne retelechargera pas : supprimez d'abord
  le fichier si vous voulez changer de qualite.
