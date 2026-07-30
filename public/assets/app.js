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

function formatDuration(totalSeconds) {
    const s = Math.max(0, Math.floor(totalSeconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    const pad = (n) => String(n).padStart(2, "0");
    return h > 0 ? `${h}:${pad(m)}:${pad(sec)}` : `${m}:${pad(sec)}`;
}

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
        const meta = await api("metadata", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ url }),
        });
        renderPreview(meta);
    } catch (err) {
        showError(err.message);
    } finally {
        analyzeBtn.disabled = false;
        analyzeBtn.textContent = "Analyser";
    }
});

function formatSize(bytes) {
    if (bytes >= 1024 * 1024 * 1024) {
        return (bytes / (1024 * 1024 * 1024)).toFixed(2) + " Go";
    }
    if (bytes >= 1024 * 1024) {
        return (bytes / (1024 * 1024)).toFixed(1) + " Mo";
    }
    return Math.round(bytes / 1024) + " Ko";
}

const progressWrap = document.getElementById("progress-wrap");
const progressBar = document.getElementById("progress-bar");
const progressStats = document.getElementById("progress-stats");

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

function renderProgress(job) {
    const percent = job.progress == null ? 0 : job.progress;
    progressBar.style.width = percent + "%";

    if (job.status === "queued") {
        progressStats.textContent = "En attente du demarrage...";
        return;
    }
    if (job.stage === "processing") {
        progressStats.textContent = "Conversion / fusion en cours...";
        return;
    }

    const parts = [percent.toFixed(1) + " %"];
    if (job.speed != null) {
        parts.push(formatSpeed(job.speed));
    }
    if (job.eta != null) {
        parts.push("reste " + formatEta(job.eta));
    }
    progressStats.textContent = parts.join("  ·  ");
}

function endDownloadUi() {
    downloadBtn.disabled = false;
    downloadBtn.textContent = "Telecharger";
}

function pollJob(jobId) {
    const timer = setInterval(async () => {
        let job;
        try {
            job = await api("status?id=" + encodeURIComponent(jobId));
        } catch (err) {
            clearInterval(timer);
            endDownloadUi();
            previewHint.textContent = "Echec du suivi : " + err.message;
            return;
        }

        renderProgress(job);

        if (job.status === "finished") {
            clearInterval(timer);
            endDownloadUi();
            progressBar.style.width = "100%";
            progressStats.textContent = "";
            previewHint.textContent = "Termine : " + job.file + " (" + formatSize(job.size) + ")";
        } else if (job.status === "error") {
            clearInterval(timer);
            endDownloadUi();
            progressWrap.classList.add("hidden");
            previewHint.textContent = "Echec : " + job.error;
        }
    }, 500);
}

downloadBtn.addEventListener("click", async () => {
    if (!currentVideo) {
        return;
    }

    downloadBtn.disabled = true;
    downloadBtn.textContent = "Telechargement...";
    previewHint.textContent = "";
    progressBar.style.width = "0%";
    progressStats.textContent = "Lancement...";
    progressWrap.classList.remove("hidden");

    try {
        const result = await api("download", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                id: currentVideo.id,
                format: formatSelect.value,
            }),
        });
        pollJob(result.job_id);
    } catch (err) {
        endDownloadUi();
        progressWrap.classList.add("hidden");
        previewHint.textContent = "Echec : " + err.message;
    }
});
