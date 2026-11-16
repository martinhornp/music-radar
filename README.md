# Martins Music Radar

Personlig webapp til at opdage nye albums og EP'er, med anbefalinger baseret på musiksmag.

| Del      | Teknologi                       | Mappe        |
| -------- | ------------------------------- | ------------ |
| Frontend | React + TypeScript + Vite       | `frontend/`  |
| Backend  | PHP 8.4 (ingen Composer-krav)   | `api/`       |
| Database | MariaDB (ikke integreret endnu) | –            |

Produktion: one.com (Begynder) via SFTP – ingen SSH, så alt der skal køre på
serveren skal kunne uploades som almindelige filer.

## Kom i gang lokalt

Krav: Node.js 20+ og Docker Desktop.

```bash
npm install          # installerer også frontend/
npm run api:up       # PHP 8.4 + Apache + MariaDB i Docker
npm run dev          # Vite på http://localhost:5173
```

- Frontend (med hot reload): http://localhost:5173 – `/api` proxies til Docker.
- API direkte: http://localhost:8080/api/health
- Produktionslignende build: kør `npm run build`, og åbn http://localhost:8080
  (Apache serverer `frontend/dist` med `/api` ved siden af, som på one.com).
- MariaDB: `localhost:3306`, db/bruger/kode `music_radar` (se `.env.example`).

Stop med `npm run api:down`.

**Uden Docker:** `npm run api:local` starter PHP's indbyggede server på :8080
(kræver `php` i PATH – helst 8.4, så det matcher produktion).

## Struktur

```
api/
  index.php              Front controller – alle /api/*-kald ender her
  bootstrap.php          Autoloader, config, router
  routes.php             Rutedefinitioner
  config/config.php      Standardconfig (+ config.local.php / env-variabler)
  src/Http/              Request, Response, Router
  src/Controllers/       Controllere
frontend/
  src/                   React-app
  public/.htaccess       SPA-fallback på Apache (kopieres til dist/)
docker/                  Lokalt Apache/PHP-image
scripts/build-deploy.mjs Samler deploy-pakken
```

### Tilføj et API-endpoint

1. Opret en controller i `api/src/Controllers/` (namespace `MusicRadar\Controllers`).
2. Registrér ruten i `api/routes.php`, fx `$router->get('/artists/{id}', [$artists, 'show']);`
   – parametre ligger i `$request->params['id']`.
3. Returnér `Response::json([...])` eller `Response::error('Besked', 404)`.

## Deploy (one.com)

Deploy sker automatisk via GitHub Actions ([.github/workflows/deploy.yml](.github/workflows/deploy.yml))
ved push til `main` – men kun når repository-variablen `DEPLOY_ENABLED` er `true`.

1. **Build & test** (alle pushes og PR'er): lint, build, `php -l`, test af API'et.
2. **Deploy**: uploader pakken via SFTP med [scripts/sftp-deploy.sh](scripts/sftp-deploy.sh).
   - Pakken tjekkes først af [scripts/verify-package.sh](scripts/verify-package.sh):
     frontend og API skal være med, og ingen følsomme filer må være med.
   - Sletter aldrig filer på serveren; filer der kun findes dér bliver stående.
   - Serverfiler der overskrives, gemmes først som artifact `server-backup-<nr>` (30 dage).
   - Uploader aldrig til login-mappen (`SFTP_REMOTE_PATH` må ikke være `.` eller `/`).
   - `config.local.php`, `.env`, `.user.ini`, `*.sql` m.fl. uploades aldrig og
     overskrives derfor aldrig. Deploy afbrydes, hvis pakken indeholder dem.
   - Opretter aldrig målmappen – forkert sti giver fejl, ikke en upload et forkert sted.
   - Tjekker serverens host key (`SFTP_KNOWN_HOSTS`).
   - `index.html` uploades sidst.
3. **Smoke test** ([scripts/smoke-test.sh](scripts/smoke-test.sh)) mod det live site:
   forside, SPA-ruter, `/api/health`, 404 fra API'et, at det er *dette* build der
   serveres, og at config/PHP-kildefiler ikke kan hentes.

Manuel kørsel: "Run workflow" på *Build & deploy* laver som standard en dry run
(det virker også før `DEPLOY_ENABLED` er sat).
*Check SFTP connection* ([sftp-check.yml](.github/workflows/sftp-check.yml))
logger ind og lister målmappen uden at uploade noget.

Lokalt: `npm run package` bygger `deploy/`, og
`bash scripts/smoke-test.sh http://localhost:8080 deploy/index.html` tester Docker-sitet.

**Databaseoplysninger** lægges kun på serveren: opret
`api/config/config.local.php` manuelt via SFTP ud fra
`api/config/config.local.example.php`.

Bemærk: gamle filer i `assets/` fjernes ikke automatisk (de har unikke navne og
er harmløse). Ryd dem evt. op manuelt en gang imellem.
