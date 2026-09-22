# ytgrab

Telechargeur YouTube qui tourne sur ta machine. Tu colles une URL, tu choisis
un format, la video atterrit dans ton dossier Telechargements. Une page web,
pas de compte, rien ne sort du PC. C'est fait pour un usage perso.

## Ce que ca fait

- Preview au collage de l'URL : titre, miniature, duree et resolutions dispo
- mp4 en meilleure qualite ou dans une resolution precise, ou mp3 seul
- File d'attente. Les videos partent une par une, chacune avec sa progression
  (pourcentage, vitesse, temps restant)
- Annulation d'un job en attente ou en cours, les fichiers partiels sont
  supprimes
- Playlists jusqu'a 50 videos, ajoutees d'un coup dans le format choisi
- Bouton pour mettre yt-dlp a jour. YouTube casse regulierement les vieilles
  versions et c'est la premiere chose a essayer quand un telechargement
  echoue avec une erreur 403
- Lecture des fichiers dans le navigateur. Le streaming gere les requetes
  Range, donc on peut avancer dans la video

Tu peux fermer l'onglet pendant un telechargement, il continue. Le worker
tourne dans un process a part et la page retrouve la file quand tu reviens.

## Prerequis

PHP 8.3 minimum, Composer (juste pour l'autoload), yt-dlp et ffmpeg.

Windows :

```
winget install yt-dlp.yt-dlp
```

Ce paquet embarque ffmpeg. Rouvre ton terminal ensuite, sinon le PATH n'est
pas a jour.

macOS :

```
brew install yt-dlp ffmpeg
```

Debian/Ubuntu :

```
sudo apt install ffmpeg
pipx install yt-dlp
```

Evite le yt-dlp d'apt, il a souvent plusieurs mois de retard et YouTube ne
l'accepte plus.

## Installation

```
composer dump-autoload
```

Il n'y a aucune dependance, Composer genere juste l'autoload PSR-4.

## Lancement

```
php -S 127.0.0.1:8080 -t public/
```

Puis http://127.0.0.1:8080 dans ton navigateur.

Sur macOS et Linux, ajoute `PHP_CLI_SERVER_WORKERS=4` devant la commande. Le
serveur integre de PHP ne traite qu'une requete a la fois, et avec plusieurs
workers le suivi de progression ne saccade pas pendant qu'une analyse d'URL
tourne. Sous Windows la variable est ignoree, mais comme les telechargements
sont dans des process a part, ca reste utilisable.

Le serveur n'ecoute que sur 127.0.0.1. Personne d'autre sur le reseau ne peut
y acceder.

### En fond

La commande ci-dessus occupe le terminal. Pour lancer le serveur et fermer la
fenetre, depuis le dossier du projet :

Windows (PowerShell) :

```
Start-Process php -ArgumentList '-S 127.0.0.1:8080 -t public/' -WindowStyle Hidden
```

Pour l'arreter : `taskkill /F /IM php.exe`. Ca tue tous les php.exe, y
compris un telechargement en cours, donc attends que la file soit vide.

macOS et Linux :

```
PHP_CLI_SERVER_WORKERS=4 nohup php -S 127.0.0.1:8080 -t public/ > /dev/null 2>&1 &
```

Pour l'arreter : `pkill -f "php -S 127.0.0.1:8080"`. Les workers ne sont pas
concernes, un telechargement en cours va jusqu'au bout.

## Ou vont les fichiers

Dans le dossier Telechargements de ta session (`%USERPROFILE%\Downloads` sous
Windows, `~/Downloads` ailleurs). S'il n'existe pas, `storage/downloads/`
prend le relais. Le dossier utilise est affiche dans la page.

Ce dossier contient aussi tes fichiers perso. L'appli ne liste et ne sert que
ceux qui portent le suffixe `[id video]` ajoute par yt-dlp au nom, les autres
restent invisibles.

Si tu retelecharges une video dans une autre qualite, yt-dlp voit que le
fichier existe deja (le nom ne contient pas la qualite) et ne fait rien.
Supprime le fichier d'abord.

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

## API

Tout passe par `index.php/api/...`, la raison est expliquee plus bas.

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

Le front interroge `/api/jobs` toutes les 700 ms tant qu'un job est actif.

Formats acceptes : `best`, une hauteur en pixels (`1080`, `720`) ou `mp3`.

Statuts d'un job : `queued`, `starting`, `running`, `cancelling`, puis
`finished`, `error` ou `cancelled`. Les jobs termines sont oublies apres
24 h.

## Comment c'est fait

Aucune commande externe ne passe par un shell. Tout est lance avec
`proc_open()` et un tableau d'arguments. L'URL collee n'est d'ailleurs jamais
donnee a yt-dlp : on en extrait l'ID avec une regex stricte et on reconstruit
une URL propre.

Les appels API passent par `index.php/api/...` parce que le serveur integre de
PHP ne reecrit pas les URLs. Sans ca, `/api/health` renverrait un 404.

Le telechargement tourne dans `bin/worker.php`, lance en process detache
(`start /b` sous Windows, `sh -c '... &'` ailleurs). Il survit a la requete
HTTP et ecrit sa progression dans le JSON du job. `/api/jobs` ne fait que
relire ces fichiers.

Il n'y a pas de demon pour la file. A chaque evenement (ajout, fin d'un
worker, annulation, ou simple affichage de la liste), `Scheduler::dispatch()`
prend un verrou `flock` sur `storage/jobs/.lock`, repere les workers morts et
lance le job suivant s'il reste une place. `Config::MAX_CONCURRENT` fixe le
nombre de telechargements en parallele. J'ai laisse 1, deux yt-dlp en meme
temps se partagent la bande passante sans aller plus vite au total.

L'annulation tue yt-dlp (`taskkill /T` sous Windows, `kill` ailleurs). Le
worker voit le process s'arreter, supprime les `.part` et les flux
intermediaires de la video, et passe le job en `cancelled`.

Une playlist est lue avec `--flat-playlist --playlist-end 50`, ce qui evite de
resoudre chaque video. Chaque entree devient ensuite un job normal.

Les noms de fichiers sortent du template `%(title)s [%(id)s].%(ext)s` avec
`--restrict-filenames`. Tout chemin passe par `realpath()` avant d'etre servi,
pour ne jamais sortir du dossier de telechargement.

## Licence

MIT, voir [LICENSE](LICENSE).
