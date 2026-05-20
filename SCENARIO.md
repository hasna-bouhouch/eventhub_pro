# SCENARIO.md — EventHub Pro
## Scénario de test bout-en-bout (Partie 5.1)

| Étape | Action | Résultat attendu | Statut |
|-------|--------|-----------------|--------|
| 1 | Organisateur crée 'DevFest Marrakech 2026' (capacité: 5) via le formulaire `events/create.php` | Événement inséré en BD avec `createEvent()` préparé. Réponse JSON `{ success: true, event_id: X }` | ✅ |
| 2 | 4 utilisateurs s'inscrivent via `events/register.php` | 4 tokens générés, 4 lignes en table `registrations`, 4 emails de confirmation envoyés via `SendConfirmation::send()`. Compteur mis à jour en temps réel (AJAX). | ✅ |
| 3 | Le 4ème inscrit déclenche le seuil 80% (4/5 = 80%) | `register.php` détecte `$pct >= 80 && alert_sent == 0`. UPDATE atomique `alert_sent = 1`. `AlertMailer::sendCapacityAlert()` envoie l'email à l'organisateur avec le rapport PDF joint. | ✅ |
| 4 | Le 5ème s'inscrit — événement complet | `registered_count >= capacity` → réponse `{ success: false, error: 'Événement complet.', full: true }`. En JS : bouton d'inscription désactivé en temps réel sans rechargement de page. | ✅ |
| 5 | Organisateur télécharge le rapport PDF | `GET /pdf/report.php?event_id=X` → `generateReportPDF()` produit un PDF 3 pages : résumé exécutif, liste inscrits triée, graphique en barres (primitives TCPDF). | ✅ |
| 6 | Un inscrit clique sur son lien de désinscription | `GET /events/unsubscribe.php?token=abc123` → vérification token, suppression de la ligne `registrations`, réponse confirmant la désinscription. | ⚠️ (à implémenter dans `events/unsubscribe.php`) |

## Décisions techniques justifiées

### 1. Anti-doublon alerte 80% (Partie 2.2)
**Approche choisie :** colonne `events.alert_sent` + UPDATE atomique  
**Justification :** `UPDATE events SET alert_sent=1 WHERE id=? AND alert_sent=0` ne met à jour qu'une seule ligne. Si deux inscriptions arrivent simultanément, MySQL garantit qu'un seul UPDATE réussit (`rowCount()=1`). L'autre transaction trouve `alert_sent=1` et ne renvoie pas l'email. C'est plus fiable qu'un fichier lock (pas multi-serveur) et plus simple qu'une table verrou.

### 2. Filtres dynamiques PDO (Partie 1.3)
**Approche choisie :** tableau `$conditions[]` + `$bindings[]`  
**Justification :** chaque filtre actif ajoute une condition et une valeur. Aucune variable utilisateur n'est jamais concaténée dans la chaîne SQL. `bindValue()` avec `PDO::PARAM_INT` pour LIMIT/OFFSET empêche les injections de type.

### 3. Bibliothèque PDF : TCPDF (Partie 3)
**Justification :** TCPDF permet un contrôle précis des primitives graphiques (Rect, Line, Cell) pour le graphique en barres du rapport. Dompdf est meilleur pour le rendu HTML/CSS mais ne supporte pas les primitives de dessin de la même façon.
