import axios from "axios";

// Adresse de base de l'API (définie dans .env en local, dans Vercel en ligne)
export const API_BASE: string = import.meta.env.VITE_API_URL ?? "";

const api = axios.create({
    baseURL: API_BASE,
    headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
    },
});

// Ajoute ou retire le jeton de connexion sur toutes les requêtes
export function setAuthToken(token?: string | null): void {
    if (token) {
        api.defaults.headers.common["Authorization"] = `Bearer ${token}`;
    } else {
        delete api.defaults.headers.common["Authorization"];
    }
}

export default api;
