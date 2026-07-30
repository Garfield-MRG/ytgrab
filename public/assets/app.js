"use strict";

/**
 * Le serveur integre de PHP ne reecrit pas les URLs, donc tous les appels
 * API passent par index.php/api/... (PATH_INFO).
 */
const API_BASE = "index.php/api/";

async function api(path, options) {
    const res = await fetch(API_BASE + path, options);
    const data = await res.json();
    if (!res.ok) {
        throw new Error(data.error || "Erreur HTTP " + res.status);
    }
    return data;
}

const form = document.getElementById("url-form");
const hint = document.getElementById("form-hint");

form.addEventListener("submit", (event) => {
    event.preventDefault();
    // Etape 2 : validation de l'URL + recuperation des metadonnees.
    hint.textContent = "Pas encore branche : la recuperation des metadonnees arrive a l'etape 2.";
});
