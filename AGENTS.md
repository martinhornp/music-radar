# AGENTS.md – fælles arbejdsregler for AI-assistenter

Gælder for alle assistenter i dette repo (Claude Code, OpenAI Codex m.fl.).
Læs [PROJECT_CONTEXT.md](PROJECT_CONTEXT.md) ved starten af en opgave. Den
beskriver arkitektur, anbefalingsmodel og aktuel status.

## Kommunikation

- Svar Martin på **dansk**. Kode, kodekommentarer og commit-beskeder skrives på engelsk, som i resten af repoet.
- Skriv kort og konkret, med tal og filhenvisninger. Ingen lange lister over muligheder, du ikke anbefaler.
- **Gør arbejdet selv.** Kør kommandoer, workflows og kontroller selv (fx `gh workflow run`, `gh secret set` fra stdin, opslag mod det live site). Giv aldrig Martin en trin-for-trin-liste, han selv skal udføre. Spørg kun, når der kræves en beslutning eller en adgang, du ikke har.
- Start ikke lange ventetider eller overvågning, Martin ikke har bedt om. Tilbyd det i stedet med et tidsestimat.
- Rapportér ærligt: fejlede tests, sprungne trin og usikkerheder nævnes eksplicit.

## Godkendelse

- **Commit, push og deploy kun med Martins godkendelse i den aktuelle opgave.** Push til `main` deployer automatisk til produktion.
- Ændr aldrig produktionsdatabasen uden godkendelse. Undgå skrivende test-kald mod det live site; hvis de er nødvendige, så ryd op bagefter.
- Arbejd direkte på `main`. Commit kun de filer, opgaven handler om, og tjek `git status` før commit.
- Sæt et rollback-tag (`pre-<emne>`) på den nuværende version før et deploy, der ændrer algoritmen eller importen.
- Afslut commit-beskeder med den attribution-linje, dit værktøj foreskriver.

## Faste begrænsninger

- **Ingen løbende omkostninger:** ingen betalte API'er, AI-kald i drift eller tjenester, der kan koste penge. Spørg før alt, der kan.
- **Hemmeligheder** må aldrig komme i Git, URL'er, logs eller output. `api/config/config.local.php`, `.env` og `api/var/` committes aldrig. `IMPORT_TOKEN` og andre secrets findes kun i GitHub Secrets og på serveren; send dem via stdin, aldrig som kommandolinjeargument.
- **one.com:** ingen SSH. Varnish svarer 429 ved bursts, så hold pauser mellem live-kald, og tilføj `?nc=<tid>` for at omgå 5-minutters cachen. Tilføj ikke offentlige debug-endpoints, og eksponér ikke serveroplysninger.
- **Workflows:** ændr ikke deployment-workflows uden at det er en del af opgaven.
- **Stop og rapportér ved fejl** i stedet for at gætte videre.

## Anbefalingsmodellen

- **Mål før du ændrer.** Sammenlign den aktive model og ændringen på samme data (`api/var/eval/run.php`, samme eval-database, dato, profil og feedback). Vis:
  - effekten på Recommended og Wildcards (7 og 31 dage);
  - effekten pr. genrefamilie;
  - konkrete udgivelser, der skifter kategori;
  - uventede ændringer.
- **Ingen særregler** for enkelte kunstnere eller udgivelser. Ændr ikke grænser (fx `min_fit` 0,495) for at få en bestemt udgivelse med.
- **Brug eksisterende struktur:** genrefamilier, `TagInformation` og MusicBrainz' genrerelationer. Ingen generelle regler af typen "alle tags med ordet X".
- **Ingen dobbelt belønning:** hvert tag tæller én gang. Last.fm Similar Artists er et uafhængigt signal.
- **Genberegning:** Ændringer i algoritmefilerne (se `ImportRunner::ALGORITHM_FILES`) giver ny algoritme-version, og næste import genberegner. Et deploy alene ændrer ikke feedet; start "Daily import" bagefter for at verificere.
- Hold `PROJECT_CONTEXT.md` og `docs/` i takt med koden, når en parameter eller funktion ændres.

## Tests før aflevering

- **Design før kode:** ved ændringer i importen eller algoritmen tænkes designet igennem og beskrives for Martin i 3–5 linjer, før der skrives kode. Ingen ombygning midt i opgaven.
- **Ét forsøg pr. fejlet test:** fejler en test, må du lave ét rettelsesforsøg. Virker det ikke, så stop og rapportér, hvad der fejler, i stedet for at fejlsøge videre.
- **Kør testene én gang** før aflevering, ikke løbende efter hver lille rettelse. Små, afgrænsede ændringer (fx en parameter eller én funktion): kun de tests, der rører området (find dem med grep). Hele testsættet kun ved ændringer i delt kode, importen eller databasen.
- Backend: alle `api/bin/test-*.php` i Docker (se PROJECT_CONTEXT.md). De kører mod en `_test`-database, aldrig den rigtige.
- Frontend: `npm run lint` og `npm run build` (inkluderer typecheck).
- Ændrede PHP-filer: `php -l`. Ændrede shell-scripts: `bash -n`. Workflows: YAML-validering.
- Windows / Git Bash: brug `MSYS_NO_PATHCONV=1` foran `docker compose exec ... /var/www/...`.

## Efter et deploy

Kontroller og rapportér:
1. Workflow-status: "Build & test", "Deploy to one.com" og smoke-test.
2. Start "Daily import" og tjek importens resumé: kørsel, antal Recommended og Wildcards, fejl.
3. Tjek feed, feedback og My Music via det offentlige API (med cache-bypass): at ratings og feedback er uændrede, og at feedet ser normalt ud.

## Kodestil

- Skriv som den omkringliggende kode: samme navngivning, kommentartæthed og idiomer. PHP 8.4 med `declare(strict_types=1)`, ingen Composer-afhængigheder. React og TypeScript uden nye biblioteker uden grund.
- Ingen nye funktioner eller abstraktioner, opgaven ikke kræver.
