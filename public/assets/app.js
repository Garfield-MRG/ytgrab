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

downloadBtn.addEventListener("click", async () => {
    if (!currentVideo) {
        return;
    }

    downloadBtn.disabled = true;
    downloadBtn.textContent = "Telechargement...";
    previewHint.textContent = "Telechargement en cours, la requete peut durer un moment (progression en direct a l'etape 4).";

    try {
        const result = await api("download", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                id: currentVideo.id,
                format: formatSelect.value,
            }),
        });
        previewHint.textContent = "Termine : " + result.file + " (" + formatSize(result.size) + ")";
    } catch (err) {
        previewHint.textContent = "Echec : " + err.message;
    } finally {
        downloadBtn.disabled = false;
        downloadBtn.textContent = "Telecharger";
    }
});
