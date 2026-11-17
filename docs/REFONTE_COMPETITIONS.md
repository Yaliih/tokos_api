# Refonte : moteur de compétition, couche sociale et site web

## Mise en route

```bash
composer install            # aucune nouvelle dépendance, juste par sécurité
php artisan migrate         # 2 nouvelles migrations, n'efface rien
php artisan storage:link    # si ce n'est pas déjà fait
php artisan schedule:work   # en local : fait avancer les duels chaque minute
php artisan db:seed --class=DemoSeeder   # (facultatif) données de démo, en local seulement
php artisan test            # 12 tests de bout en bout
```

En production, une seule ligne cron suffit :
`* * * * * cd /chemin/tokos_api && php artisan schedule:run >> /dev/null 2>&1`

Sans ce cron, les duels ne se terminent jamais : c'est lui qui clôture les votes,
désigne les gagnants, fait avancer les tournois et envoie les rappels.

Comptes de démo (DemoSeeder) : `+243 810000001` à `+243 810000010`, mot de passe `password`.

## Le moteur

Une seule mécanique pour les quatre formats :

| Format | Fonctionnement |
|---|---|
| Défi | Le créateur publie sa performance ; chaque challenger ouvre un duel contre lui. |
| Duel | Ciblé (je défie @quelqu'un ou un #espace, qui accepte ou refuse) ou versus (deux propositions A/B). |
| Concours | Candidats, votes (1 à 10 votes par personne), jury facultatif, classement final. |
| Tournoi | Élimination directe, têtes de série selon l'Elo, exemptions automatiques, tours qui s'enchaînent seuls. |

Chaque format peut opposer des **personnes**, des **Espaces** (groupe contre groupe) ou **les deux**.
Un Espace participe via un gestionnaire, ou via les propositions de ses membres (un gestionnaire choisit).

Tables : `competitions`, `competition_entries`, `competition_matches`, `competition_votes`,
`competition_invitations`, `competition_jurors`, `competition_jury_scores`, `entry_proposals`.

Code : `app/Services/Competition/*` (règles), `app/Support/Presenter.php` (JSON commun API + web),
`config/competition.php` (limites, durées, anti-triche, points : tout se règle là).

### Anti-triche
- Un vote par personne et par duel, garanti par la base ; verrouillage des lignes (compteurs exacts).
- Un participant ne vote pas dans son duel ; on ne vote pas pour sa propre participation.
- Membres d'un Espace en compétition : vote normal, à moitié, ou interdit (au choix du créateur).
- Comptes de moins de 24 h : vote compté à moitié. Limite de votes par IP et par duel.
- Ordre d'affichage des deux camps aléatoire par personne. Option votes à l'aveugle.

## Couche sociale
- **Fil d'activité** (`activities`) : défis lancés, défis relevés, victoires, séries, top 10, badges,
  nouveaux membres d'Espaces, abonnements, tournois qui avancent, duels qui dépassent 100 votes…
  Les activités similaires sont regroupées (« A, B et 3 autres participent à… »).
- **Notifications** (table `notifications` de Laravel) : défi reçu, duel commencé, plus qu'une heure
  (avec ta position), victoire/défaite, revanche, qualifié au tour suivant, nouveau membre, badge, commentaire…
- **Push** : renseigne `FCM_PROJECT_ID` et `FCM_CREDENTIALS` (chemin du JSON de compte de service Firebase)
  dans `.env`, puis appelle `POST /api/device-tokens` depuis l'app avec le jeton FCM. Sans ces valeurs, rien n'est envoyé.
- **Classements** Elo + points de saison (par trimestre), par catégorie, pour les personnes et les Espaces.
- **Badges** : `config/badges.php`.
- **Réactions** emoji sur les duels, **revanche** en un clic.

## API (nouvelles routes)
Lecture publique (le jeton est facultatif) : `GET /api/competitions`, `/competitions/options`,
`/competitions/{slug}`, `/{slug}/entries`, `/{slug}/matches`, `/{slug}/bracket`, `/matches/hot`,
`/matches/{id}`, `/activity`, `/activity/users/{username}`, `/activity/spaces/{slug}`, `/discover`,
`/leaderboards`, `/stats/users/{username}`, `/stats/spaces/{slug}`.

Connecté : `POST /competitions`, `PUT|DELETE /competitions/{slug}`, `/start`, `/entries`, `/candidates`,
`/entries/{id}/approve|reject|vote|jury-score`, `/invitations`, `/jurors`, `/proposals`,
`POST /matches/{id}/vote|react|rematch`, `GET /invitations`, `POST /invitations/{id}/accept|decline`,
`GET /notifications`, `/notifications/unread-count`, `POST /notifications/read`, `PUT /notifications/settings`,
`POST /device-tokens`.

`GET /api/posts/feed?type=duels|challenges` filtre maintenant les onglets de l'app.

## Site web
Servi par Laravel (Blade, sans étape de compilation) : `routes/web.php`, `resources/views/web`,
`public/css/tokos.css`, `public/js/tokos.js`. Mêmes comptes, mêmes règles que l'app.
Pages : fil, découvrir, compétitions, page de compétition (duels, tableau, classement, gestion,
propositions, commentaires), duel en direct, création, classements, profils `/@pseudo`, Espaces,
notifications, défis reçus, connexion, inscription (code SMS).
Les liens partagés (`/c/…`, `/m/…`) s'ouvrent sans compte, avec aperçu pour WhatsApp/Facebook.

## Correctif de sécurité
`GetMediaController` permettait de lire n'importe quel fichier du serveur (dont `.env`)
avec une URL du type `/api/get-media/..&..&..&,env`. C'est corrigé et testé.

## Ce qui a été retiré
Les anciennes routes `/challenges`, `/contests`, `/participations`, `/votes/duel`, `/votes/challenge-duel`,
`/votes/contest-candidate` et `/spaces/{slug}/contests…` sont remplacées par le moteur.
Les anciennes tables restent en base (rien n'est supprimé) ; les anciens posts de type duel/challenge/contest
n'apparaissent plus dans le fil.

Fichiers devenus inutiles à supprimer (`git rm`) :
- `app/Http/Controllers/Api/ChallengeController.php`, `ContestController.php`, `CandidateController.php`,
  `ParticipationController.php`, `SpaceContestAdminController.php`, `SpaceContestCandidateController.php`,
  `SpaceContestManagementController.php`
