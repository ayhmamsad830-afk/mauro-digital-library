# MAURO — Rapport final Batch A + Batch B (2026-11-19)

Rien n'a été commité, poussé ni déployé. Aucune base n'a été réinitialisée. Aucune sauvegarde n'a été supprimée. Le SQLite d'origine et la base `entreprise` n'ont pas été touchés. L'e-mail reste **désactivé** tant qu'aucun fournisseur n'est approuvé.

## 1. Batch A — bilan

| # | Élément | État |
|---|---|---|
| A1 | Suite teal/or terminée | 264 réussis, 1 échec connu (KI-1 : styles inline de Google sur `/login`), 0 ignoré ; intégrité inchangée |
| A2 | Code Cosmic supprimé | `cosmic.css`, `cosmic.js` et `cosmic-sky.jpg` supprimés, `UI_CURSOR_TRAIL` et `cursor_trail` retirés, ombres bleues remplacées par des ombres chaudes. Les seules animations conservées (révélation au défilement, flottement du livre vedette) sont dans `motion.css` / `motion.js`, désactivées avec `prefers-reduced-motion` |
| A3 | SEO | URL canonique, Open Graph (dont `og:type=book` et la couverture sur la fiche livre), Twitter Card, `theme-color` clair/sombre, `lang`/`dir`. Données structurées schema.org `Book` en **microdata**, car la CSP stricte interdit le JSON-LD inline. Le sitemap inclut éditeurs, À propos et Contact ; robots inchangé (`noindex` hors production) |
| A4 | Paramètres membre `/account/settings` | Nom, langue, thème (mémorisés en base et en cookie, appliqués dès la connexion). Changement de mot de passe : ancien mot de passe vérifié, politique appliquée, limite de débit, les autres sessions sont déconnectées et la session courante est conservée |
| A5 | Revue UI page par page | 34 pages × AR/EN × clair/sombre × 375/768/1280 × Chrome + Edge, voir §4. 3 défauts réels trouvés et corrigés |

## 2. Batch B — bilan

| # | Élément | État |
|---|---|---|
| B1 | Service e-mail | Client SMTP sans dépendance : STARTTLS ou TLS implicite avec vérification du certificat, AUTH LOGIN, MIME UTF-8, protection contre l'injection d'en-têtes. **Désactivé par défaut** ; le pilote `log` est refusé en production. `mail_log` ne stocke que le type, le statut et l'erreur. `env:check` affiche l'état |
| B2 | Récupération de mot de passe | `/password/forgot` puis `/password/reset`. Jeton de 32 octets stocké uniquement en SHA-256, valable 60 min, à usage unique (réclamé de façon atomique). Réponse neutre, sans énumération de comptes. Limites : 10/h par IP et 3/h par adresse. Toutes les autres sessions sont fermées. Tant que l'e-mail est désactivé, la page l'indique honnêtement |
| B3 | Page À propos `/about` | Neutre, sans aucune information personnelle ou organisationnelle inventée ; utilise la marque configurable |
| B4 | Contact `/contact` | Validation, pot de miel, 5 messages/h par IP, enregistrement en base. Côté admin, `/admin/messages` permet de filtrer et de passer un message en lu, traité ou archivé (actions journalisées) |
| B5 | Écran Sauvegardes `/admin/backups` | Lecture seule : type, date, taille, empreinte SHA-256, intégrité (archive, fin de dump SQL, en-tête SQLite). Aucun téléchargement, restauration ni suppression |
| B6 | Pages éditeurs `/publishers` et `/publishers/{slug}` | Dérivées des livres visibles, sans nouvelle table ; les variantes d'espacement sont fusionnées. Slugs stables, avec ou sans `ext-intl`. Lien depuis la fiche livre, filtre dans le catalogue |
| B7 | Intégration e-mail / contact | Si SMTP est configuré **et** si `site.contact_email` est défini (Admin → Paramètres), chaque message est copié par e-mail (statut sent/failed). Sinon le statut `not_configured` est enregistré |

Migration `0012` (`password_resets`, `contact_messages`, `mail_log`) appliquée sur DEV après la sauvegarde `mysql-dev-pre-0012-20261119-162607.sql`. Seule la table `migrations` a changé en plus des trois tables vides créées.

## 3. Fonctionnalités implémentées (cette session)

- Identité Vintage Paper + accents teal/or partout (site, compte, lecteur, admin), AA vérifié
- Animations sobres (`motion.css`/`motion.js`), suppression complète de Cosmic
- SEO complet (canonical, OG, Twitter, microdata Book, sitemap étendu)
- Paramètres membre (profil, langue, thème, mot de passe) avec invalidation des autres sessions
- Service SMTP, récupération de mot de passe, À propos, contact et boîte de réception admin, écran Sauvegardes, pages éditeurs
- `env:check` : ligne « E-mail delivery »
- 4 nouvelles classes de tests (22 tests), qui passent sur SQLite et sur MySQL TEST

## 4. Captures d'écran

Sélection de 16 captures (dont 2 Edge) dans le projet : `docs/qa/batch-ab-screens/`. Jeu complet (340 + 168 captures, temporaire) : `C:\Users\DELL\AppData\Local\Temp\claude\704bcaac6a904085\f5c3b4ed-bc07-4240-a13a-a9036363d430\scratchpad\a5\shots` et `...\a5\shots2`. Le serveur isolé tournait sur une base SQLite synthétique, jamais sur DEV ni TEST. Les captures Chrome couvrent AR/EN × clair/sombre × 375/1280 ; les captures Edge, AR/EN en 1280 clair.

**Audit automatisé** (`C:\Users\DELL\mauro-tools\playwright\a5-audit.js`) : 34 pages (24 publiques, 2 membre, 8 admin) × AR/EN × clair/sombre × 375/768/1280 × **Chrome et Edge** = **816 chargements**, avec 12 contrôles par chargement, soit **9 792 contrôles**.

Contrôles : statut HTTP, `lang`/`dir`, thème, débordement horizontal, éléments hors écran, images cassées ou sans `alt`, liens ou boutons sans nom accessible, ids dupliqués, un seul `h1`, champs sans étiquette, clé de traduction visible, erreurs console, contenu resté invisible après défilement.

Résultat final : **0 problème** (après corrections, nouvel audit des pages modifiées : 144 chargements, 1 728 contrôles). Test complémentaire « saut en bas de page » (`reveal-jump.js`) : 20/20 pages sans contenu caché, Chrome et Edge.

Défauts trouvés et corrigés :
1. **Contact** : le formulaire était invisible, à cause d'une classe `reveal` en dur que `motion.js` ne retirait jamais. Classe supprimée, et l'audit vérifie désormais tout contenu resté à opacité 0 après défilement.
2. **Révélation au défilement** : un contenu « sauté » d'un coup (touche Fin, défilement rapide, ancre) pouvait rester invisible, car l'IntersectionObserver ne se déclenche jamais pour lui. Un filet de sécurité léger au défilement l'affiche maintenant sans animation.
3. **Paramètres** : une adresse e-mail longue débordait sur la tuile voisine. Elle passe maintenant à la ligne dans sa tuile.
4. **Admin → Sauvegardes** : la dernière colonne était coupée à 1280 px. Les noms de fichiers passent à la ligne et le tableau s'empile sur mobile.

Faux positifs écartés : le bouton « précédent » des rails est masqué volontairement au début ; les cellules de tableaux, les onglets et les rails sont dans des conteneurs défilants ; `admin.invalid` est le domaine des comptes admin locaux.

## 5. Tests — chiffres exacts

| Suite | Réussis | Échoués | Ignorés |
|---|---|---|---|
| Suite complète, SQLite (harnais par défaut) | **286** | **1** (KI-1) | **0** |
| Suite complète, `MAURO_HARNESS=mysql` (MySQL 8.0.46 TEST) | **286** | **1** (KI-1) | **0** |

- Les deux suites incluent les tests navigateur réels : `BrowserUiTest` (587 contrôles, dont 6 en échec, tous KI-1 sur `/login`) et l'E2E (28 contrôles lecture/publication + 292 contrôles membre/admin, 0 échec).
- Transparence : la première exécution SQLite a donné 272/15/0, parce que j'avais lancé les deux suites **en parallèle** et qu'elles partageaient la base TEST MySQL (erreurs « table already exists » et interblocages). Une fois les suites séparées, les 14 tests concernés passent (`MySqlLibrary` 32/32, `SqliteImport` 5/5) et la suite MySQL relancée seule donne 286/1/0.
- Après les dernières corrections (vues contact et sauvegardes, `motion.js`), réexécution ciblée : `TemplateEscaping` 2/2, `ContactAndAdminScreens` 6/6, `Phase2Pages` 12/12, `AccountSettingsAndPublishers` 5/5, `BrowserUi` (seulement KI-1).

## 6. Sécurité

- CSP stricte inchangée (aucun script ni style inline ajouté ; données structurées en microdata). `TemplateEscapingTest` et `Phase2PagesTest` passent sur toutes les nouvelles vues.
- CSRF sur tous les nouveaux POST. Identifiant de compte toujours pris dans la session (test : les `id` injectés dans le formulaire sont ignorés).
- Jetons de réinitialisation : 256 bits, hachés, 60 min, usage unique, purgés après usage ou changement de mot de passe. Après la saisie, le jeton est retiré de l'URL (redirection 303).
- Invalidation de session : `password_changed_at` comparé à `pw_seen` à chaque requête ; la session courante est régénérée.
- Limites de débit : `password_reset_ip` 10/h, `password_reset_email` 3/h, `contact` 5/h, `member_password_change` 6/15 min.
- SMTP : TLS obligatoire avec vérification du certificat, adresses validées (pas de CRLF), aucun secret journalisé.
- Écran Sauvegardes en lecture seule ; aucun chemin n'est exposé au téléchargement.
- Exception connue : KI-1 (Google GSI sur `/login`), acceptée et documentée dans `docs/KNOWN_ISSUES.md`.

## 7. Intégrité des bases

Instantanés (58 entrées : sommes de contrôle de chaque table DEV et `entreprise`, SHA-256 du SQLite, du `.env` et des sauvegardes) :

- **Avant et après les deux suites complètes et l'audit : 0 différence.** Les tests n'ont touché ni DEV, ni `entreprise`, ni le SQLite, ni le `.env`, ni les sauvegardes. L'audit tournait sur une base SQLite synthétique jetable.
- Entre la suite teal/or et ce lot, seules ces différences, toutes attendues :
  - migration `0012` sur DEV : 3 tables vides créées, table `migrations` mise à jour, nouvelle sauvegarde `pre-0012` ;
  - usage réel du site de dev le 2026-11-19 entre 16:25 et 16:41 (connexion, lecture : `users.last_login_at`, `reading_*`, `oauth_audit_events`, compteur de lectures, `rate_limits`).
  - DEV contient toujours **1 livre et 3 comptes** : aucune perte.
- `entreprise` : inchangée. `storage/mauro.sqlite` : inchangé.

## 8. Sauvegardes

Les 12 sauvegardes sont présentes ; aucune n'a été supprimée ni modifiée.

| Sauvegarde | Empreinte | Contenu |
|---|---|---|
| `db-20261119-105120.sql.gz` (officielle) | OK | `backup:verify` : dump SQL complet |
| `files-20261119-105120.tar.gz` (officielle) | OK | `backup:verify` : 4 fichiers |
| 4 dumps `mysql-dev-*.sql` (dont `pre-0012`) | OK | « Dump completed » présent |
| 4 copies SQLite avec `.sha256` | OK | — |
| 2 copies SQLite anciennes (`before-author-fix`, `before-cover-change`) | pas de fichier `.sha256` (créées avant la règle) | en-tête SQLite valide |

## 9. Configuration restante (décisions du propriétaire)

1. **Fournisseur SMTP : décision du 2026-11-20, SMTP de l'hébergeur.** À activer une fois l'hébergement choisi (voir §10). Il faudra alors une boîte d'envoi sur le domaine (par exemple `noreply@<domaine>`), son hôte, son port et ses identifiants, plus SPF/DKIM validés. Jusque-là, `MAIL_DRIVER=disabled` et la récupération de mot de passe reste indisponible.
2. **`site.contact_email`** (Admin → Paramètres) : adresse qui reçoit la copie des messages de contact. Actuellement vide.
3. **Page À propos** : texte neutre. Ajouter des informations sur l'organisation seulement si vous les fournissez.
4. **Domaine de production et HTTPS**, puis origine Google OAuth `https://<domaine>` (KI-2 : connexion Google réelle à valider par vous).
5. `APP_KEY` de production, `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false`.

## 10. Exigences du fournisseur e-mail

- SMTP avec **STARTTLS (587)** ou **TLS implicite (465)** ; le SMTP en clair n'est jamais utilisé.
- Authentification par identifiant et mot de passe ou clé API (AUTH LOGIN).
- Domaine expéditeur vérifié : **SPF**, **DKIM**, et un enregistrement **DMARC** (`p=none` au départ).
- Volume : faible (réinitialisations et copies de messages de contact, quelques dizaines par jour au plus).
- Options courantes, à choisir par vous :
  - SMTP transactionnel dédié (Brevo, Mailgun, Postmark, Amazon SES) : meilleure délivrabilité, journaux de rebond ;
  - SMTP de l'hébergeur : le plus simple, délivrabilité variable ;
  - Google Workspace ou Microsoft 365 : si le domaine y est déjà, avec des limites d'envoi.
- Variables, uniquement dans le `.env` du serveur : `MAIL_DRIVER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`.

## 11. Compatibilité d'hébergement

- PHP **8.3+** (8.4 recommandé) avec `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `gd` (WebP), `curl` ; `proc_open` autorisé.
- **MySQL 8.0.16+** (ou MariaDB 10.6+), InnoDB, utf8mb4.
- **Poppler** (`pdftoppm`, `pdfinfo`) installable : indispensable pour le lecteur protégé.
- **Cron** chaque minute (rendu PDF, nettoyage, sauvegardes) et `mysqldump`.
- Racine web = `public/` ; `storage/` hors de la racine web ; HTTPS ; envoi de PDF jusqu'à 500 Mo (`upload_max_filesize`, `post_max_size`, limites du serveur web).
- Sortie SMTP 587/465 autorisée (certains hébergeurs la bloquent).
- Conséquence : la plupart des hébergements mutualisés ne conviennent pas (pas de Poppler ni de `proc_open`). Il faut un **VPS** ou un mutualisé haut de gamme qui confirme ces points par écrit.

## 12. Architecture de production recommandée

- 1 VPS Linux (Ubuntu 24.04 LTS, 2 vCPU, 4 Go de RAM, 80 Go SSD ; à augmenter selon le volume de PDF).
- Nginx + PHP-FPM 8.4 (`deploy/nginx.conf`, `deploy/php-production.ini`), MySQL 8 local, accessible seulement en localhost.
- Let's Encrypt (renouvellement automatique), HSTS envoyé par l'application.
- `storage/` sur le même disque, hors racine web ; sauvegardes nocturnes `backup:run` copiées **hors du serveur** (stockage objet ou une autre machine).
- Pare-feu : ports 22 (clé SSH uniquement), 80 et 443 ; fail2ban.
- Un environnement **staging** (sous-domaine `staging.`, `noindex` automatique) pour valider chaque version avant la production.
- Optionnel plus tard : un CDN pour les ressources statiques (les pages du lecteur restent servies par l'application, avec des jetons signés).

## 13. Checklist de déploiement

1. Choisir et valider l'hébergeur (§11), le domaine et le fournisseur SMTP (§10).
2. Créer le serveur, installer Nginx, PHP 8.4 et ses extensions, MySQL 8, Poppler et `mysqldump` ; configurer le pare-feu.
3. Créer la base et un utilisateur MySQL dédié (droits limités à cette base).
4. Déployer le code (sans `.env`, sans `storage/` local), avec la racine web sur `public/`.
5. Écrire le `.env` de production : `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `php bin/console key:generate`, identifiants MySQL, `SESSION_SECURE_COOKIE=true`, `GOOGLE_CLIENT_ID`, `MAIL_*` (laisser `disabled` tant que SPF/DKIM ne sont pas validés).
6. `php bin/console env:check` : aucun FAIL.
7. `php bin/console migrate --production`, puis `migrate:status` : 12 migrations appliquées.
8. Importer les données réelles depuis DEV (dump vérifié par SHA-256) et copier `storage/` (PDF et rendus) ; vérifier le nombre de lignes.
9. Installer la crontab (`deploy/crontab.example`) et vérifier `storage/logs/cron.log`.
10. Google Cloud : ajouter l'origine `https://<domaine>`, se connecter une fois, puis `user:promote`.
11. Tests de fumée : accueil, catalogue, fiche livre, lecteur (invité et membre, filigrane), connexion, paramètres, contact, admin (messages, sauvegardes), `/.env` → 403/404, `/storage/` → 403/404.
12. `backup:run`, copie hors du serveur, test de restauration sur staging.
13. **Votre accord explicite**, puis ouverture publique et soumission de `sitemap.xml`.

## 14. Procédure de retour arrière

- **Application** : garder la version précédente dans un dossier daté (par exemple `releases/<date>`) ; revenir en arrière = rebasculer le lien symbolique et recharger PHP-FPM.
- **Base** : sauvegarde `backup:run` juste avant chaque migration ; en cas de problème, mettre l'application en maintenance, restaurer le dump vérifié (SHA-256) sur une base neuve, puis la mettre en service. Il n'y a pas de migration descendante automatique : on revient toujours par la sauvegarde.
- **Local (déjà en place)** : DEV MySQL → SQLite en remettant `DB_DRIVER=sqlite` (`.env` d'origine sauvegardé dans `C:\Users\DELL\mysql-recovery\20261118-231018\env.before-switch-20261119-105140`) ; `storage/mauro.sqlite` est intact (sha256 F13D5CE2…FF5AE).
- **E-mail** : `MAIL_DRIVER=disabled` le coupe immédiatement, sans perte (les messages restent en base).
- **Fichiers** : `files-*.tar.gz`, vérifiable par `backup:verify`.
