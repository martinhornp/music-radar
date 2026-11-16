# Martins Music Radar – projektkontekst

Den centrale kilde til projektets aktuelle status. Arbejdsregler står i
[AGENTS.md](AGENTS.md); tekniske detaljer i `docs/` (se nederst).

Opdateret: 1. november 2026.

## Formål

Personlig webapp, der finder nye albums og EP'er, som passer til Martins musiksmag.

- **Regelbaseret og gratis:** ingen betalte API'er og ingen AI-kald i drift.
- **Live:** https://musik.hornpedersen.dk
- **Repo:** `martinhornp/music-radar`. Der arbejdes direkte på `main`.

## Arkitektur

| Del | Teknologi | Mappe |
|---|---|---|
| Frontend | React 19 + TypeScript + Vite | `frontend/src` (`pages/`, `components/`, `lib/`) |
| Backend | PHP 8.4, ingen Composer, egen router | `api/` (`routes.php`, `src/`) |
| Database | MariaDB hos one.com (lokalt i Docker) | tabeller oprettes med `CREATE TABLE IF NOT EXISTS` |
| Drift | one.com (ingen SSH) + GitHub Actions | `.github/workflows/` |

**Flow:**
- GitHub Actions kalder det token-beskyttede import-endpoint i bidder af ~20 sekunder.
- Serveren henter data fra MusicBrainz og Last.fm og gemmer i MariaDB.
- Serveren beregner anbefalingerne og aktiverer en ny kørsel atomisk.
- Frontenden læser den aktive kørsel.

**Vigtigste backend-mapper (`api/src`):**
- `Discovery/`: import og indsamling.
- `Recommend/`: motor, ratings og Last.fm Similar Artists.
- `Taste/`: genre-tags, familier og musikprofil.
- `Feed/`: kørsler og API-kontrakten.
- `Feedback/`, `Auth/`, `Import/`, `Controllers/`.

**Parametre:**
- Algoritmen: `api/config/recommender.php` og `api/config/discovery.php`.
- Miljøet: `api/config/config.php`, som overstyres af den git-ignorerede `config.local.php` og af miljøvariabler.

**API:**
- Åbne læse-endpoints: `/api/health`, `/recommendations`, `/search` og `GET /feedback`.
- `POST /feedback/{id}` kræver login.
- `/api/internal/*` kræver import-token, ellers 404.

## Funktioner i drift

- **Daglig import** kl. 00:17, 02:17 og 04:17 dansk tid:
  - Henter MusicBrainz pr. udgivelsesdag i et 31-dages vindue, samt kendte kunstnere, Last.fm og Last.fm Similar Artists.
  - Op til 3 runnere, hvis one.com ikke kan nås.
  - Genberegner, når data, feedback, profil eller algoritme-version er ændret, eller når det er en ny dag.
  - **Et deploy alene genberegner ikke;** det gør næste import.
  - Retter datoen for udgivelser, der er flyttet til en anden dag, og rydder forældede fejl.
  - Se [docs/daily-import.md](docs/daily-import.md).
- **Musikprofil:** Genopbygges hver søndag fra Last.fm (`MartinHornP`) og MusicBrainz. Smagsprofilen bruger top 100 pr. periode (ca. 193 unikke kunstnere); i genreprofilen vægtes hver kunstner efter afspilninger, dæmpet med kvadratrod (nr. 1 = 1, nr. 95 ≈ 0,36). Kendt begrænsning: den faste grænse 0,015 for "fuldt liked" kan fortynde smalle undergenrer, når profilen bliver bredere. Se [docs/music-profile.md](docs/music-profile.md).
- **Kunstnerniveauer:** Interested kommer fra Last.fm top 100 pr. periode samt bevarede kunstnere fra tidligere profiler. Listened beregnes pr. udgivelse fra det øvrige Last.fm-bibliotek, afspilninger og identitetssikkerhed; det garanterer ikke en anbefaling. Following er ikke en implementeret brugerfunktion og bruges ikke i den aktuelle opsætning. Koden har dog understøttelse af `following` og manuelle overrides; det må ikke beskrives som en aktiv funktion.
- **Ratings (1–5★), Not interested og Save for later:** My Music viser den aktive kørsels score. Se [docs/feedback.md](docs/feedback.md).
- **Login:**
  - Én bruger (`martin`). Ændringer af musikdata kræver login; læsning er åben.
  - Sessionen lever 30 dage i en cookie (HttpOnly, Secure, SameSite=Strict).
  - Højst 3 fejlede forsøg pr. klient pr. 5 minutter.
  - Se [docs/login.md](docs/login.md).
- **Søgning** i alle gemte udgivelser. Klik på logo/navn øverst til venstre åbner Discover på ny og rydder søgning og filtre.
- **TIDAL-links:** Én knap på kort og detaljer ved automatisk valideret, entydigt MusicBrainz-match til en konkret digital udgave. Ingen manuel albumgodkendelse. Ét TIDAL-album bruges (dato og salgsnote betyder ikke noget; en note om anden musik som "acoustic" kun, hvis alle udgaver har den). Flere: anden titel ("(Deluxe)") og udgaver kun til et land uden for Europa frasorteres, så explicit, umarkeret, clean, Dolby Atmos, hi-res, og af umarkerede fra forskellige pladeselskaber det, der også udgiver i Europa; ellers ambiguous. Windows bruger `tidal://album/ID` (bekræftet af Martin); Android bruger HTTPS (mobiltest udskudt). Nye fund prioriteres foran aktuelle 7-dagesanbefalinger og genforsøg, uden dagsgrænse eller historisk baggrundsindsamling. Godkendte links genkontrolleres ikke automatisk og bevares også efter tidligere midlertidige fejl; konkrete identitetskonflikter skjuler dem. Ingen TIDAL-API eller scoreændringer. Se [docs/tidal-links.md](docs/tidal-links.md).
  - **Fejlhåndtering:** Enkeltfejl stopper ikke køen. HTTP 429/5xx giver vedvarende backoff med respekt for `Retry-After`; fejl registreres særskilt fra manglende links. Atomiske checkpoints og midlertidige reservationer gør afbrudt behandling genoptagelig. Import og publiceret feed bevares uafhængigt af TIDAL-fejl.
  - **Engangsbackload:** Hele den aktive anbefalingskørsel registreres én gang i Tidal-køen (ved forberedelsen 341 Recommended og 33 Wildcards). Eksisterende links og genforsøgsfrister bevares; den gamle 7-dagesgennemgang holdes adskilt. Efter engangsopgaven fortsætter prioriteringen af nye udgivelser uden historisk kataloggennemgang.
  - **Gentjek af én status:** Samme workflow med `check` = `errors`, `ambiguous` eller `missing` tjekker alle udgivelser med netop den status fra datoen, uanset forsøgsdato, hver én gang pr. kørsel. `new` (standard) er den almindelige backfill.
  - **Datoopdelt backload:** Datoetaper via `scripts/backfill-tidal.ps1` og det separate workflow TIDAL backfill. Kører kun Tidal-berigelse af eksisterende albums/EP'er fra datoen til i dag, uden musikimport, Last.fm, profilopbygning, genberegning eller feedpublicering. Godkendte links springes over i etapen. Så længe `TidalLinkStore::BACKFILL_RETRIES` er `false`, tjekker backfill kun udgivelser, der aldrig er tjekket (ingen genforsøg); genforsøg sker kun i den daglige import (missing på dag 3 og 29 efter første tjek, derefter ikke mere; fejl efter 2 dage, højst 3 forsøg pr. album; kun udgivelser på højst 30 dage og med score mindst 0,35). Gentagne eller overlappende etaper nulstiller ikke fremdrift. Godkendte links undersøges aldrig automatisk igen, heller ikke under almindelig import. Identitetskonflikter skjuler fortsat linket uden nye opslag.
- **Bedøm albums** (`/bedoem-albums`, kun logget ind): dine mest spillede albums/EP'er på Last.fm (All Time), endnu ikke ratet, flest afspilninger først, med "X af N bedømt". Listen importeres manuelt med workflowet "Import top albums" (antal vælges, standard 100; tabel `top_albums`). En ny kørsel, fx top 200 eller efter en MusicBrainz-fejl, tilføjer kun albums, der mangler, og ændrer aldrig dem, der er der. Last.fm-navne med udgave i parentes ("(Deluxe)") søges også uden. En karakter er almindelig feedback, så motoren lærer af den; gamle albums får deres snapshot fra `top_albums`.
- **Deploy ved push til `main`:**
  - build og test, SFTP-upload med backup og en smoke-test;
  - ved manglende forbindelse genforsøges smoke-testen på op til 3 runnere;
  - grøn status kræver en bestået kontrol.
  - Se [README.md](README.md).

## Anbefalingsmodellen (Model v2) – nøgletal

Alt beregnes i `RecommendationEngine::assessMusic()`. Den fulde beskrivelse står i [docs/discovery-pipeline.md](docs/discovery-pipeline.md), og ratings er beskrevet i [docs/feedback.md](docs/feedback.md).

- **Genre:**
  - Ét signal, hvor hvert tag tæller én gang, vægtet efter information (`TagInformation`).
  - Et tag tæller fuldt fra profilvægt 0,015.
  - En undergenre af en familie, du kan lide, tæller 0,5.
  - Tag-overlap med en af dine kunstnere er en alternativ vej, ikke en bonus.
- **Last.fm Similar Artists** (`SimilarArtists`):
  - music = 1 − (1 − genre)(1 − 0,4 × Last.fm).
  - Identitetssikkerheden afgør, hvor meget et link tæller.
  - Et link vægtes efter, hvor meget du spiller profilkunstneren: lagvægt × (0,5 + 0,5 × play_weight) (`lastfm_play_floor`), og op til 1,5× når du har ratet kunstneren godt (`lastfm_rating_boost` × kunstnerens rating-lean, kun positiv).
- **fit** = (1 − w) × (music − uden for smagen 0,461) + w × personlig (0–1; 1 = det stærkest mulige match). Personlig = gennemsnit af dine ratings af kunstneren (1★ = 0 … 5★ = 1, Not interested = 1★); afspilninger tæller som 0,7 med vægt 1,5 × lytteværdi ÷ (1 + ratings). w = E ÷ (E + 3), E = ratings + 1,5 × lytteværdi (`personal` i recommender.php). Dansk, kendt kunstner og lyttehjælp er slået fra (vægt 0).
- **Ratings:**
  - Signaler: 1★ −1, 2★ −0,5, 3★ +0,1, 4★ +0,5, 5★ +1.
  - Samme kunstner: indgår i den personlige del (se fit), ikke i genresignalet for kunstnerens egne plader.
  - Pr. genre-tag: Σ ÷ (n + **10**) (`tag_prior = 10`), som flytter tagget inde i genresignalet. Én rating flytter et tag 1/11.
  - Beslægtede ikke-ratede undergenrer: ½ × Σ(slægtskab × signal) ÷ (Σ slægtskab + 10).
- **Recommended:**
  - Interested-kunstnere kommer med uanset score, når udgivelsen er kvalificeret og datoen bekræftet; Not interested skjuler stadig udgivelsen.
  - Ellers kræves fit + eventuel Listened-hjælp ≥ **0,495** og datasikkerhed ≥ 0,5. Hjælpen er 0,197 × lytteinteresse, kun ved fit ≥ 0,197, og ændrer ikke den viste score.
  - Egne 1–2★ udelukker den konkrete udgivelse fra Recommended og Wildcards, også ved Interested. Ikke-vurderede udgivelser og 3–5★ beholder garantien; kunstnerens andre udgivelser berøres ikke af dette fravalg. Rettelsen aktiveres ved første genberegning efter deploy.
- **Wildcards:** fit 0,29–0,592 med nyt territorium eller en usædvanlig blanding plus en konkret bro til smagen.
- **Genrefamilier:**
  - Familien findes ved første matchende nøgleord (`TagClassifier`).
  - Elektroniske hardcore-genrer er placeret efter MusicBrainz' genrerelationer.
  - `uk hardcore` og `digital hardcore` står bevidst under Hardcore, fordi de er tvetydige.

## Drift

- **GitHub secrets** (kun navne): `SFTP_*` (inkl. `SFTP_REMOTE_PATH`), `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `IMPORT_TOKEN`, `LASTFM_API_KEY`. Serveroplysninger (SFTP-adresse, sti, databasenavn/-bruger) ligger kun her, så de er maskeret i logs.
- **GitHub-variabler:** `DEPLOY_ENABLED`, `IMPORT_ENABLED`, `SITE_URL`, `FEED_SOURCE`, `IMPORT_BUDGET`.
- **Rollback:**
  - Git-tags: `model-v1-final`, `pre-current-score`, `pre-electronic-hardcore`, `pre-import-housekeeping`, `pre-date-recheck`, `pre-tag-prior-10`, `pre-rating-priority`.
  - De 7 seneste anbefalingskørsler gemmes i databasen.
- **one.com:**
  - Varnish cacher `/api/recommendations` i 5 min og svarer 429 ved bursts.
  - Enkelte GitHub-runnere kan ikke nå sitet.
  - MusicBrainz rate-limiter af og til den delte IP.

## Lokalt

Krav: Node 20+ og Docker Desktop. Arbejdsmaskinen er Windows med PowerShell og Git Bash.

- **Start:** `npm install`, `npm run api:up` (API på :8080) og `npm run dev` (:5173).
- **Frontend:** `npm run lint` og `npm run build` (inkluderer typecheck).
- **Backend:** `docker compose exec php php /var/www/api/bin/test-<navn>.php` for `discovery`, `feedback`, `import`, `search`, `auth`, `collaborations`, `artist-matcher`, `tidal-links`, `tidal-queue` og `top-albums`. MariaDB-testene kører mod en `_test`-database; køtestene bruger isoleret SQLite. I Git Bash skal `MSYS_NO_PATHCONV=1` stå foran.
- **TIDAL-linkvalg:** `node scripts/test-tidal-link.mjs` kontrollerer Windows-/mobil-URL'er og skjulte knapper uden at åbne apps.
- **Modelændringer** måles i `api/var/eval/` (git-ignoreret, kun lokalt) med `run.php` mod eval-databaserne (`music_radar_eval_now` m.fl.).

## Aktuel status (31/10-2026)

- **Senest verificeret før rating-prioritetsdeploy:** kørsel 24, 338 Recommended og 35 Wildcards. Næste import genberegner med rettelsen.
- **Data:** ca. 5.200 udgivelser og 4.700 kunstnere.
- **Feedback:** 28 ratings og 3 Not interested.
- **Seneste ændringer** (alle live 31/10):
  - Model v2 med Last.fm Similar Artists;
  - login;
  - aktuel score i My Music;
  - ratings af beslægtede undergenrer;
  - elektroniske hardcore-familier;
  - robust smoke-test;
  - oprydning af forældede importfejl;
  - genkontrol af udskudte udgivelser;
  - `tag_prior = 10`.
- **Holdes øje med:**
  - Northlane – *Anemoia* er udskudt til 27/11 ifølge MusicBrainz, men vises stadig med 30/10. Den bør forsvinde ved næste genindsamling af 30/10. Gør den ikke det, skal årsagen undersøges, før der foreslås en rettelse.
  - En indsamlingsfejl for 21/10 (MusicBrainz rate limiting) genforsøges automatisk efter 24 timers pause.
- **Kendte forhold:**
  - Før rating-prioritetsrettelsen kunne Interested inkludere egne 1–2★. Bekræftet for The Number Twelve Looks Like You – *Howls From the Holographic Heart* (2★). Rettelsen flytter rating-tjekket foran garantien og træder i kraft ved genberegning efter deploy. Sammenligning og testdækning: [docs/feedback.md](docs/feedback.md#release-rating-priority).
  - MusicBrainz' søgning springer af og til enkelte udgivelser over ved mange ens hits. Genkontrollen fanger datoændringer.
  - Motoren kender genre-tags, kunstneridentitet og Last.fm-links, ikke lyd.
- **Åbne beslutninger (Martins):**
  - Fjern `feed.json` som fallback.
  - `uk hardcore` og `digital hardcore`.
  - Fjern den midlertidige `diagnose-connectivity.yml`.
  - Android-test af TIDAL-links er udskudt.

## Teknisk dokumentation

| Fil | Indhold |
|---|---|
| [README.md](README.md) | Opsætning, struktur, deploy |
| [docs/daily-import.md](docs/daily-import.md) | Import, tabeller, overvågning, kontakter |
| [docs/discovery-pipeline.md](docs/discovery-pipeline.md) | Pipeline-stadier og anbefalingsmodellen |
| [docs/feedback.md](docs/feedback.md) | Ratings, My Music-score, målte effekter |
| [docs/login.md](docs/login.md) | Login og sikkerhed |
| [docs/tidal-links.md](docs/tidal-links.md) | Matchkontrol, cache, import og platformlinks |
| [docs/music-profile.md](docs/music-profile.md) | Musikprofilen |
| [docs/discovery-experiment.md](docs/discovery-experiment.md) | Tidlige forsøg (historisk) |
