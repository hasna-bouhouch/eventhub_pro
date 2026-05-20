/**
 * EventHub Pro — assets/js/app.js  [COMPLÉTÉ - Parties 4.1 + 4.2]
 */

'use strict';

const STATE = {
    currentTab:    'all',
    dashInterval:  null,
    debounceTimer: null,
    selectedEvent: null,
    lastPerEvent:  {},   // Stocke fill_pct précédent pour détecter passage à 100%
};

const CATEGORY_COLORS = {
    tech:     { bg: '#DBEAFE', text: '#1D4ED8', primary: '#2563EB' },
    design:   { bg: '#EDE9FE', text: '#6D28D9', primary: '#7C3AED' },
    business: { bg: '#FEF3C7', text: '#B45309', primary: '#EA580C' },
    science:  { bg: '#DCFCE7', text: '#15803D', primary: '#16A34A' },
};


// ══════════════════════════════════════════════════════════════════════════
// PARTIE 4.1 — CHARGEMENT DES ÉVÉNEMENTS
// ══════════════════════════════════════════════════════════════════════════

async function loadEvents() {
    const keyword   = document.getElementById('search-input')?.value ?? '';
    const category  = document.getElementById('filter-category')?.value ?? '';
    const hasPlaces = document.getElementById('filter-places')?.value === '1';

    showSkeletons();

    try {
        const response = await fetch('api/events.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                keyword,
                category,
                has_places: hasPlaces,
                tab:  STATE.currentTab,
            }),
        });

        if (!response.ok) throw new Error('HTTP ' + response.status);

        const data = await response.json();

        if (data.success) {
            renderEventCards(data.data);
            // Mise à jour des compteurs en en-tête si présents dans le DOM
            if (data.meta) {
                const el = document.getElementById('events-count');
                if (el) el.textContent = data.meta.total;
            }
        } else {
            showGridError(data.error ?? 'Erreur inconnue.');
        }

    } catch (err) {
        console.error('[loadEvents]', err);
        showToast('Impossible de charger les événements.', 'error');
        showGridError('Erreur de connexion au serveur.');
    }
}


// ══════════════════════════════════════════════════════════════════════════
// PARTIE 4.1 — INSCRIPTION EN TEMPS RÉEL
// ══════════════════════════════════════════════════════════════════════════

async function registerToEvent(eventId, name, email) {
    setButtonLoading('btn-register', true, 'Inscription en cours…');

    try {
        const response = await fetch('events/register.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ event_id: eventId, name, email }),
        });

        const data = await response.json();

        if (data.success) {
            closeRegisterModal();
            showToast('Inscription réussie ! Votre ticket PDF sera envoyé par email.', 'success');

            // ── Mise à jour temps réel de la carte SANS rechargement ──
            const pct     = data.capacity_pct;
            const barEl   = document.getElementById('bar-'    + eventId);
            const plEl    = document.getElementById('places-' + eventId);
            const btnEl   = document.getElementById('btn-'    + eventId);

            if (barEl)  barEl.style.width = pct + '%';
            if (plEl)   plEl.textContent  = '…'; // Rafraîchi par loadEvents si besoin

            if (data.is_full && btnEl) {
                btnEl.disabled   = true;
                btnEl.textContent = 'Complet';
                btnEl.style.background = '#94A3B8';
                btnEl.onclick = null;
            }

            if (data.alert_sent) {
                showToast('⚠️ Alerte 80% envoyée à l\'organisateur', 'info');
            }

        } else {
            showToast(data.error ?? 'Erreur lors de l\'inscription.', 'error');
        }

    } catch (err) {
        console.error('[registerToEvent]', err);
        showToast('Erreur réseau. Veuillez réessayer.', 'error');
    } finally {
        setButtonLoading('btn-register', false, "S'inscrire");
    }
}


// ══════════════════════════════════════════════════════════════════════════
// PARTIE 4.1 — RECHERCHE AVEC DEBOUNCE (400ms)
// ══════════════════════════════════════════════════════════════════════════

function debounceSearch() {
    clearTimeout(STATE.debounceTimer);
    STATE.debounceTimer = setTimeout(loadEvents, 400);
}


// ══════════════════════════════════════════════════════════════════════════
// PARTIE 4.2 — DASHBOARD TEMPS RÉEL
// ══════════════════════════════════════════════════════════════════════════

function startDashboard() {
    if (STATE.dashInterval) clearInterval(STATE.dashInterval);
    fetchDashboardStats();
    STATE.dashInterval = setInterval(fetchDashboardStats, 30000);
}

async function fetchDashboardStats() {
    try {
        const response = await fetch('api/stats.php');
        if (!response.ok) throw new Error('HTTP ' + response.status);

        const data = await response.json();
        if (!data.success) throw new Error(data.error);

        // ── Mise à jour des KPI avec animation ────────────────────────
        animateCounter('kpi-total',   data.summary.total_registered);
        animateCounter('kpi-new-24h', data.summary.new_last_24h);
        animateCounter('kpi-alertes', data.summary.alert_count);

        const tauxEl = document.getElementById('kpi-taux');
        if (tauxEl) tauxEl.textContent = data.summary.avg_fill_pct + '%';

        // ── Top 3 ──────────────────────────────────────────────────────
        if (typeof renderTop3 === 'function') renderTop3(data.top3);

        // ── Notification toast si un événement passe à 100% ───────────
        data.per_event.forEach(ev => {
            const prev = STATE.lastPerEvent[ev.id];
            if (prev !== undefined && prev < 100 && parseInt(ev.fill_pct) >= 100) {
                showToast('🎉 ' + ev.title + ' est maintenant COMPLET !', 'info');
            }
            STATE.lastPerEvent[ev.id] = parseInt(ev.fill_pct);
        });

        // ── Horodatage ─────────────────────────────────────────────────
        const lastUpdateEl = document.getElementById('last-update');
        if (lastUpdateEl) {
            lastUpdateEl.textContent = 'Mis à jour à ' + new Date().toLocaleTimeString('fr-FR');
        }

    } catch (err) {
        console.error('[fetchDashboardStats]', err);
        // Gestion d'erreur : réessayer dans 10s sans casser l'interface
        clearInterval(STATE.dashInterval);
        setTimeout(() => { startDashboard(); }, 10000);

        const errEl = document.getElementById('dash-error');
        if (errEl) {
            errEl.textContent = '⚠️ Erreur de chargement. Nouvelle tentative dans 10s…';
            errEl.style.display = 'block';
        } else {
            showToast('Erreur dashboard. Nouvelle tentative dans 10s.', 'error');
        }
    }
}


// ══════════════════════════════════════════════════════════════════════════
// BONUS — Fonctionnalité AJAX originale : Suggestion live de capacité
// Description : Quand l'utilisateur tape une capacité dans le formulaire
// de création, on affiche dynamiquement la fourchette recommandée basée
// sur la moyenne des événements de la même catégorie, sans rechargement.
// ══════════════════════════════════════════════════════════════════════════

async function suggestCapacity(category) {
    if (!category) return;
    try {
        const res  = await fetch('api/events.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ category }),
        });
        const data = await res.json();
        if (!data.success || !data.data.length) return;

        const capacities = data.data.map(e => parseInt(e.capacity));
        const avg        = Math.round(capacities.reduce((a, b) => a + b, 0) / capacities.length);

        const hint = document.getElementById('capacity-hint');
        if (hint) {
            hint.textContent = `💡 Capacité moyenne pour la catégorie "${category}" : ${avg} places`;
            hint.style.display = 'block';
        }
    } catch (e) {
        // Silencieux — fonctionnalité bonus non critique
    }
}


// ══════════════════════════════════════════════════════════════════════════
// FOURNI — RENDU DES CARTES + UTILITAIRES 
// ══════════════════════════════════════════════════════════════════════════

function renderEventCards(events) {
    const grid = document.getElementById('events-grid');
    if (!events || events.length === 0) {
        grid.innerHTML = `
            <div class="col-span-3 text-center py-16">
                <div class="text-5xl mb-4">🔍</div>
                <p class="font-display font-bold text-slate-600 text-lg">Aucun événement trouvé</p>
                <p class="text-slate-400 text-sm mt-2">Modifiez vos critères de recherche</p>
            </div>`;
        return;
    }

    grid.innerHTML = events.map(e => {
        const pct    = parseInt(e.fill_percentage) || 0;
        const isFull = e.available_places <= 0;
        const isWarn = pct >= 80 && !isFull;
        const colors = CATEGORY_COLORS[e.category] || { bg: '#F1F5F9', text: '#334155', primary: '#64748B' };
        const barColor = isFull ? '#DC2626' : isWarn ? '#F59E0B' : colors.primary;

        return `
        <div class="event-card bg-white rounded-2xl border border-slate-200 overflow-hidden flex flex-col shadow-sm"
             data-event-id="${e.id}">
            <div class="h-2" style="background:${colors.primary}"></div>
            <div class="p-5 flex flex-col flex-1">
                <div class="flex items-start gap-2 mb-3 flex-wrap">
                    <span class="badge" style="background:${colors.bg};color:${colors.text}">${e.category}</span>
                    ${isFull ? '<span class="badge" style="background:#FEE2E2;color:#DC2626">Complet</span>' : ''}
                    ${isWarn ? '<span class="badge" style="background:#FEF3C7;color:#B45309">🔥 Quasi plein</span>' : ''}
                </div>
                <h3 class="font-display font-bold text-base text-slate-900 mb-1 leading-snug">${e.title}</h3>
                <p class="text-xs text-slate-500 mb-1">📅 ${formatDate(e.event_date)}</p>
                <p class="text-xs text-slate-500 mb-3">📍 ${e.location}</p>
                <p class="text-xs text-slate-600 leading-relaxed flex-1">${e.description}</p>
                <div class="mt-4">
                    <div class="flex justify-between text-xs font-display font-bold mb-1">
                        <span class="text-slate-400">Capacité</span>
                        <span style="color:${barColor}" id="places-${e.id}">
                            ${e.registered_count} / ${e.capacity}
                        </span>
                    </div>
                    <div class="cap-bar">
                        <div class="cap-bar-fill" id="bar-${e.id}"
                             style="width:${pct}%; background:${barColor}"></div>
                    </div>
                    ${!isFull ? `<p class="text-xs text-slate-400 mt-1">${e.available_places} place(s) restante(s)</p>` : ''}
                </div>
                <button
                    id="btn-${e.id}"
                    ${isFull ? 'disabled' : `onclick="openRegisterModal(${e.id})"`}
                    class="mt-4 w-full py-2.5 rounded-xl font-display font-bold text-xs text-white tracking-wide
                           ${isFull ? 'opacity-40 cursor-not-allowed' : 'hover:opacity-90 transition'}"
                    style="background:${isFull ? '#94A3B8' : colors.primary}">
                    ${isFull ? 'Complet' : "S'inscrire →"}
                </button>
            </div>
        </div>`;
    }).join('');
}

function showSkeletons(count = 3) {
    const grid = document.getElementById('events-grid');
    grid.innerHTML = Array.from({ length: count }, () => `
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
            <div class="skeleton h-2 w-full mb-4 -mx-5 -mt-5" style="width:calc(100% + 40px); border-radius:0"></div>
            <div class="skeleton h-5 w-3/4 mb-2 mt-2"></div>
            <div class="skeleton h-3 w-1/2 mb-1"></div>
            <div class="skeleton h-3 w-2/3 mb-4"></div>
            <div class="skeleton h-2 w-full mb-4"></div>
            <div class="skeleton h-9 w-28 rounded-xl"></div>
        </div>`).join('');
}

function showGridError(message) {
    document.getElementById('events-grid').innerHTML = `
        <div class="col-span-3 text-center py-16">
            <div class="text-5xl mb-4">⚠️</div>
            <p class="font-display font-bold text-red-600">${message}</p>
            <button onclick="loadEvents()"
                    class="mt-4 px-6 py-2 rounded-lg text-sm font-display font-bold text-white"
                    style="background:#2563eb">Réessayer</button>
        </div>`;
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    const toast     = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.cssText = 'opacity:0; transform:translateX(120%); transition:all .3s ease;';
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

function setButtonLoading(buttonId, loading, loadingText = 'Chargement…') {
    const btn = document.getElementById(buttonId);
    if (!btn) return;
    btn.disabled = loading;
    if (loading) {
        btn.dataset.originalText = btn.textContent;
        btn.innerHTML = `<span class="spinner"></span> ${loadingText}`;
    } else {
        btn.innerHTML = btn.dataset.originalText || loadingText;
    }
}

function animateCounter(elementId, target) {
    const el = document.getElementById(elementId);
    if (!el) return;
    const start = parseInt(el.textContent) || 0;
    const diff  = target - start;
    const steps = 24;
    let   step  = 0;
    const timer = setInterval(() => {
        step++;
        el.textContent = Math.round(start + diff * (step / steps));
        if (step >= steps) { el.textContent = target; clearInterval(timer); }
    }, 20);
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleDateString('fr-FR', {
        weekday: 'short', day: 'numeric', month: 'short',
        year: 'numeric', hour: '2-digit', minute: '2-digit'
    }).replace(':', 'h');
}

// ── Initialisation ────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loadEvents();

    // Démarrer le dashboard si la page est dashboard.php
    if (document.getElementById('kpi-total')) {
        startDashboard();
    }
});
