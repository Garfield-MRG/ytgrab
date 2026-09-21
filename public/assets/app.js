"use strict";

/**
 * Le serveur integre de PHP ne reecrit pas les URLs, donc tous les appels
 * API passent par index.php/api/... (PATH_INFO).
 */
const API_BASE = "index.php/api/";

async function api(path, options = {}) {
    const res = await fetch(API_BASE + path, options);
    let data;
    try {
        data = await res.json();
    } catch {
        throw new Error("Reponse illisible du serveur (HTTP " + res.status + ")");
    }
    if (!res.ok) {
        throw new Error(data.error || "Erreur HTTP " + res.status);
    }
    return data;
}

function postJson(path, body) {
    return api(path, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
    });
}

function formatDuration(totalSeconds) {
    const s = Math.max(0, Math.floor(totalSeconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    const pad = (n) => String(n).padStart(2, "0");
    return h > 0 ? `${h}:${pad(m)}:${pad(sec)}` : `${m}:${pad(sec)}`;
}

function formatSize(bytes) {
    if (bytes >= 1024 * 1024 * 1024) {
        return (bytes / (1024 * 1024 * 1024)).toFixed(2) + " Go";
    }
    if (bytes >= 1024 * 1024) {
        return (bytes / (1024 * 1024)).toFixed(1) + " Mo";
    }
    return Math.round(bytes / 1024) + " Ko";
}

function formatSpeed(bytesPerSecond) {
    if (bytesPerSecond >= 1024 * 1024) {
        return (bytesPerSecond / (1024 * 1024)).toFixed(1) + " Mo/s";
    }
    return Math.round(bytesPerSecond / 1024) + " Ko/s";
}

function formatEta(seconds) {
    const s = Math.round(seconds);
    const m = Math.floor(s / 60);
    return m > 0 ? m + " min " + String(s % 60).padStart(2, "0") + " s" : s + " s";
}

function formatLabel(format) {
    if (format === "best") {
        return "meilleure qualite";
    }
    if (format === "mp3") {
        return "mp3";
    }
    return format + "p";
}

/* ------------------------------------------------------------------ */
/* Analyse d'une URL et preview                                        */
/* ------------------------------------------------------------------ */

const form = document.getElementById("url-form");
const urlInput = document.getElementById("url-input");
const analyzeBtn = form.querySelector("button");
const formError = document.getElementById("form-error");

const preview = document.getElementById("preview");
const previewThumb = document.getElementById("preview-thumb");
const previewTitle = document.getElementById("preview-title");
const previewChannel = document.getElementById("preview-channel");
const previewDuration = document.getElementById("preview-duration");
const formatSelect = document.getElementById("format-select");
const downloadBtn = document.getElementById("download-btn");
const previewHint = document.getElementById("preview-hint");

let currentVideo = null;

function showError(message) {
    formError.textContent = message;
    formError.classList.remove("hidden");
}

function clearError() {
    formError.textContent = "";
    formError.classList.add("hidden");
}

function buildFormatOptions(heights) {
    formatSelect.innerHTML = "";

    const add = (value, label) => {
        const opt = document.createElement("option");
        opt.value = value;
        opt.textContent = label;
        formatSelect.appendChild(opt);
    };

    add("best", "Meilleure qualite (mp4)");
    for (const h of heights) {
        add(String(h), h + "p (mp4)");
    }
    add("mp3", "Audio seul (mp3)");
}

function renderPreview(meta) {
    currentVideo = meta;
    previewThumb.src = meta.thumbnail;
    previewTitle.textContent = meta.title;
    previewChannel.textContent = meta.channel;
    previewDuration.textContent = formatDuration(meta.duration);
    buildFormatOptions(meta.heights);
    previewHint.textContent = "";
    preview.classList.remove("hidden");
}

form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const url = urlInput.value.trim();
    if (url === "") {
        return;
    }

    clearError();
    preview.classList.add("hidden");
    analyzeBtn.disabled = true;
    analyzeBtn.textContent = "Analyse...";

    try {
        renderPreview(await postJson("metadata", { url }));
    } catch (err) {
        showError(err.message);
    } finally {
        analyzeBtn.disabled = false;
        analyzeBtn.textContent = "Analyser";
    }
});

downloadBtn.addEventListener("click", async () => {
    if (!currentVideo) {
        return;
    }

    downloadBtn.disabled = true;
    previewHint.textContent = "";

    try {
        const result = await postJson("download", {
            id: currentVideo.id,
            title: currentVideo.title,
            format: formatSelect.value,
        });
        previewHint.textContent = result.jobs[0].duplicate
            ? "Deja dans la file d'attente."
            : "Ajoute a la file d'attente.";
        await refreshJobs();
    } catch (err) {
        previewHint.textContent = "Echec : " + err.message;
    } finally {
        downloadBtn.disabled = false;
    }
});

/* ------------------------------------------------------------------ */
/* File d'attente                                                      */
/* ------------------------------------------------------------------ */

const jobsList = document.getElementById("jobs-list");
const jobsEmpty = document.getElementById("jobs-empty");
const jobsClear = document.getElementById("jobs-clear");

const ACTIVE_STATUSES = ["queued", "starting", "running", "cancelling"];
let jobsTimer = null;
let knownFinished = new Set();

function jobStatusText(job) {
    switch (job.status) {
        case "queued":
            return "En attente";
        case "starting":
            return "Demarrage...";
        case "cancelling":
            return "Annulation...";
        case "cancelled":
            return "Annule";
        case "error":
            return "Echec : " + (job.error || "erreur inconnue");
        case "finished":
            return "Termine" + (job.size != null ? " (" + formatSize(job.size) + ")" : "");
        case "running": {
            if (job.stage === "processing") {
                return "Conversion / fusion en cours...";
            }
            const parts = [(job.progress == null ? 0 : job.progress).toFixed(1) + " %"];
            if (job.speed != null) {
                parts.push(formatSpeed(job.speed));
            }
            if (job.eta != null) {
                parts.push("reste " + formatEta(job.eta));
            }
            return parts.join("  ·  ");
        }
        default:
            return job.status;
    }
}

function renderJobs(jobs) {
    jobsList.innerHTML = "";
    jobsEmpty.classList.toggle("hidden", jobs.length > 0);
    jobsClear.classList.toggle("hidden", !jobs.some((j) => !ACTIVE_STATUSES.includes(j.status)));

    for (const job of jobs) {
        const li = document.createElement("li");
        li.className = "job job-" + job.status;

        const head = document.createElement("div");
        head.className = "job-head";

        const title = document.createElement("span");
        title.className = "job-title";
        title.textContent = job.title || job.video_id;
        title.title = job.title || job.video_id;

        const badge = document.createElement("span");
        badge.className = "job-badge";
        badge.textContent = formatLabel(job.format);

        const actions = document.createElement("div");
        actions.className = "job-actions";

        if (ACTIVE_STATUSES.includes(job.status)) {
            const cancel = document.createElement("button");
            cancel.type = "button";
            cancel.className = "small-btn";
            cancel.textContent = "Annuler";
            cancel.disabled = job.status === "cancelling";
            cancel.addEventListener("click", async () => {
                cancel.disabled = true;
                try {
                    await postJson("cancel", { id: job.job_id });
                } catch {
                    cancel.disabled = false;
                }
                refreshJobs();
            });
            actions.appendChild(cancel);
        } else {
            if (job.status === "finished" && job.file) {
                const play = document.createElement("a");
                play.className = "small-btn";
                play.textContent = "Lire";
                play.href = API_BASE + "file?name=" + encodeURIComponent(job.file);
                play.target = "_blank";
                actions.appendChild(play);
            }
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "small-btn";
            remove.textContent = "Retirer";
            remove.addEventListener("click", async () => {
                remove.disabled = true;
                try {
                    await postJson("jobs/remove", { id: job.job_id });
                } catch {
                    remove.disabled = false;
                }
                refreshJobs();
            });
            actions.appendChild(remove);
        }

        head.append(title, badge, actions);

        const status = document.createElement("p");
        status.className = "job-status";
        status.textContent = jobStatusText(job);

        li.append(head);

        if (job.status === "running" || job.status === "cancelling") {
            const track = document.createElement("div");
            track.className = "progress-track";
            const bar = document.createElement("div");
            bar.className = "progress-bar";
            bar.style.width = (job.progress == null ? 0 : job.progress) + "%";
            track.appendChild(bar);
            li.appendChild(track);
        }

        li.appendChild(status);
        jobsList.appendChild(li);
    }
}

async function refreshJobs() {
    let data;
    try {
        data = await api("jobs");
    } catch {
        return;
    }

    renderJobs(data.jobs);

    // Un job vient de se terminer : la liste des fichiers a change.
    for (const job of data.jobs) {
        if (job.status === "finished" && !knownFinished.has(job.job_id)) {
            knownFinished.add(job.job_id);
            loadFiles();
        }
    }

    const active = data.jobs.some((j) => ACTIVE_STATUSES.includes(j.status));
    if (active && jobsTimer === null) {
        jobsTimer = setInterval(refreshJobs, 700);
    } else if (!active && jobsTimer !== null) {
        clearInterval(jobsTimer);
        jobsTimer = null;
    }
}

jobsClear.addEventListener("click", async () => {
    jobsClear.disabled = true;
    try {
        await postJson("jobs/remove", { id: "all" });
    } finally {
        jobsClear.disabled = false;
    }
    refreshJobs();
});

/* ------------------------------------------------------------------ */
/* Fichiers telecharges                                                */
/* ------------------------------------------------------------------ */

const filesList = document.getElementById("files-list");
const filesEmpty = document.getElementById("files-empty");

async function loadFiles() {
    let data;
    try {
        data = await api("files");
    } catch {
        return;
    }

    filesList.innerHTML = "";
    filesEmpty.classList.toggle("hidden", data.files.length > 0);

    for (const file of data.files) {
        const li = document.createElement("li");

        const info = document.createElement("div");
        info.className = "file-info";

        const name = document.createElement("span");
        name.className = "file-name";
        name.textContent = file.name;
        name.title = file.name;

        const meta = document.createElement("span");
        meta.className = "file-meta";
        meta.textContent = formatSize(file.size) + "  ·  "
            + new Date(file.mtime * 1000).toLocaleString("fr-FR", {
                dateStyle: "short",
                timeStyle: "short",
            });

        info.append(name, meta);

        const actions = document.createElement("div");
        actions.className = "file-actions";

        const play = document.createElement("a");
        play.className = "small-btn";
        play.textContent = "Lire";
        play.href = API_BASE + "file?name=" + encodeURIComponent(file.name);
        play.target = "_blank";

        const dl = document.createElement("a");
        dl.className = "small-btn";
        dl.textContent = "Telecharger";
        dl.href = API_BASE + "file?name=" + encodeURIComponent(file.name) + "&dl=1";

        actions.append(play, dl);
        li.append(info, actions);
        filesList.appendChild(li);
    }
}

// Au chargement : on note les jobs deja termines pour ne pas recharger la
// liste des fichiers une fois par job, puis on demarre le suivi s'il y a
// des jobs actifs (l'onglet a pu etre ferme pendant un telechargement).
api("jobs").then((data) => {
    for (const job of data.jobs) {
        if (job.status === "finished") {
            knownFinished.add(job.job_id);
        }
    }
}).catch(() => {}).finally(() => {
    refreshJobs();
    loadFiles();
});
