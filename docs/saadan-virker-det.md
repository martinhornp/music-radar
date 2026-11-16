# Sådan virker Music Radar

En forklaring uden teknik. Tallene er dem, systemet bruger lige nu.

---

## Del 1: Import – hvordan nye plader bliver fundet

### Hvornår kører importen?

- Importen starter af sig selv **hver nat kl. 00:17, 02:17 og 04:17**.
  Den første, der kommer igennem, gør arbejdet. De to andre ser, at der ikke
  er noget at lave, og stopper med det samme. Der er tre, fordi GitHub ofte
  starter planlagte kørsler for sent eller springer én over.
- Arbejdet bliver delt op i små bidder på ca. 20 sekunder ad gangen, fordi
  webhotellet ikke tillader lange kørsler. Én nat kan derfor bestå af mange
  bidder.
- **Hver søndag kl. 23:41** opdateres din musikprofil fra Last.fm, før nattens
  import.

### Hvad sker der i en import?

1. **Nye plader hentes fra MusicBrainz.** For hver dag i de sidste 31 dage
   spørges der: "Hvilke albums og EP'er udkom første gang denne dag?"
   - Kun officielle albums og EP'er. Ikke singler.
   - Ikke live-plader, opsamlinger, remix-plader, DJ-mix, demoer eller
     soundtracks. **Mixtapes** kommer med.
   - Ikke plader, hvor flere forskellige kunstnere står som kunstner
     (opsamlinger).
   - Plader, hvis titel tyder på genudgivelse, springes over. Det gælder fx
     titler med remaster, reissue, deluxe, anniversary, live at/in, remix,
     greatest hits, best of, collection og lignende.
2. **Udvidede udgaver (deluxe med nye sange).** Fra 13/11 og frem søges der
   også efter nye digitale udgaver af ældre albums. Har den nye udgave en anden
   titel og flere numre end alle tidligere udgaver af albummet, tæller den som
   ny musik på sin egen dato. En remaster med samme antal numre tæller ikke.
   Hver dag søges ad gangen.
3. **Dine kunstnere tjekkes direkte.** Kunstnere, du følger eller er
   interesseret i, slås op enkeltvis højst hver 20. time, så deres nye plader
   ikke bliver overset.
4. **Oplysninger om kunstnerne** (genrer, land) hentes fra MusicBrainz og
   opdateres hver 30. dag.
5. **Last.fm-oplysninger** hentes kun, hvor de gør en forskel: dine egne
   kunstnere, plader der allerede ser relevante ud, kunstnere du har hørt, og
   plader uden genreoplysninger. De opdateres hver 30. dag.
6. **Last.fm "lignende kunstnere"** hentes for op til 800 kunstnere om dagen og
   opdateres hver 60. dag.
7. **Anbefalingerne beregnes** (se del 2) og lægges ud på siden.
8. **TIDAL-links** findes til sidst, hvis der er tid tilbage.

### Hvornår bliver anbefalingerne beregnet igen?

Kun når noget har ændret sig:
- en ny dag;
- nye eller ændrede plader;
- en ny musikprofil (søndag);
- du har givet karakterer eller markeret noget;
- reglerne for beregningen er blevet ændret (ved et deploy). Et deploy alene
  ændrer ikke siden. Det sker først ved næste import.

### Hvor ofte hentes de samme dage igen?

Plader bliver ofte registreret på MusicBrainz nogle dage for sent. Derfor:
- **De nyeste 7 dage** hentes igen, når de er over 20 timer gamle.
- **Ældre dage** hentes igen, når de er over 72 timer gamle.
- Er en plade flyttet til en anden dato på MusicBrainz, flyttes den også her.

### Når noget går galt – genforsøg

- **Fejler noget 3 gange**, sættes det på pause i 24 timer og prøves så igen.
  På den måde kan én fejlende ting ikke bruge hele nattens tid.
- **Kan GitHub slet ikke få forbindelse til webhotellet**, prøves der igen fra
  en ny maskine, op til 3 gange.
- **Last.fm nede:** "lignende kunstnere" holder pause i 6 timer, og resten af
  importen fortsætter.
- **Når tiden løber ud** (højst 50 minutter eller 150 bidder pr. kørsel),
  fortsætter importen næste nat, hvor den slap. Kun TIDAL-arbejde tilbage
  tæller ikke som en fejl.
- **TIDAL-links** prøves igen efter disse regler:

  | Resultat | Prøves igen |
  |---|---|
  | Link fundet | Aldrig |
  | Mangler (intet TIDAL-link på MusicBrainz endnu) | På dag 3, 5, 7 og 29 efter første tjek, derefter ikke mere |
  | Fejl (fx timeout eller MusicBrainz overbelastet) | Næste dag, eller når MusicBrainz siger, vi må |
  | Tvetydig (flere mulige TIDAL-albums) | Efter 7 dage |

  Udgivelser, der aldrig er tjekket, kommer altid før genforsøg, og de nyeste
  først. Den daglige import tjekker aldrig plader, der er over 30 dage gamle –
  heller ikke dem fra "TIDAL backfill". Ældre plader tjekkes kun, når du selv
  starter workflowet "TIDAL backfill". Plader med en score under 0,35 får kun
  deres første tjek og prøves ikke igen – heller ikke hvis de vises på siden.

### Bedøm albums (dine mest spillede albums)

- Startes kun manuelt med workflowet "Import top albums". Du vælger antallet
  (fx 100 eller 150).
- Henter dine mest spillede albums fra Last.fm (hele tiden) og finder dem på
  MusicBrainz. Singler og albums, MusicBrainz ikke kender, springes over. Står
  der fx "(Deluxe)" i navnet, prøves der også uden.
- En ny kørsel **tilføjer kun manglende albums** og ændrer aldrig dem, der er der.

---

## Del 2: Anbefalinger og ratings – reglerne

### De tre slags kunstnere

- **Following:** kunstnere, du selv har valgt at følge. Deres nye plader kommer
  **altid** i Recommended.
- **Interested:** dine top 100-kunstnere på Last.fm (pr. periode) og kunstnere,
  der tidligere har været det. Deres nye plader kommer **altid** i Recommended,
  også selvom du ikke kunne lide deres forrige plade. Kun en plade, du selv har
  markeret Not interested eller givet 1–2 stjerner, forsvinder igen.
- **Listened:** andre kunstnere, du har hørt på Last.fm. De kan få et lille
  skub (se nedenfor), men kommer ikke automatisk med.

### Scoren – hvordan en plade bliver vurderet

Hver plade får en score mellem 0 og 1, hvor 1,00 er det stærkest mulige match.
Den blander to ting: hvad **musikken** siger (generelle antagelser), og hvad
**du selv** har fortalt om kunstneren (dine karakterer og afspilninger):

**score = (1 − w) × musik + w × din holdning**

Jo mere vi ved om din holdning til kunstneren, jo større er **w**, og jo mindre
fylder de generelle antagelser. Se "Din holdning til kunstneren" nedenfor.

**1. Musikken (0 til 1)**, som består af to dele:
- **Genrer:** passer pladens genrer til det, du lytter til? Hver genre tæller
  kun én gang.
  - Brede genrer som "rock", "pop", "indie", "metal" og "hip hop" siger ikke
    meget og tæller højst 35 %.
  - En undergenre, du ikke selv har i profilen, men som hører til en genre, du
    kan lide, tæller halvt.
- **Last.fm "lignende kunstnere":** er pladens kunstner forbundet med kunstnere,
  du lytter til? Dette kan højst udgøre 40 % af musik-delen, så Last.fm alene
  kan aldrig give en anbefaling.
  - En forbindelse til en kunstner, du spiller meget, tæller fuldt. En
    kunstner, du spiller lidt, tæller mindst det halve.
  - Har du givet kunstneren gode karakterer, tæller forbindelsen op til 1,5
    gange.

- **Uden for din smag:** musikken trækkes op til 0,461 ned, hvis pladen mest
  består af genrer, du ikke lytter til.

**2. Din holdning til kunstneren (0 til 1)**
- **Dine karakterer** af kunstnerens andre plader: 1★ = 0, 2★ = 0,25,
  3★ = 0,5, 4★ = 0,75, 5★ = 1. Not interested tæller som 1★.
- **Dine afspilninger** tæller som "du kan lide det" (0,7), men vejer mindre og
  mindre, jo flere karakterer du har givet. Karakterer går altid forud.

**Hvor meget din holdning vejer (w)** vokser glidende:

| Det vi ved | w |
|---|---|
| Intet (ukendt kunstner) | 0 % – kun musikken |
| Kun mange afspilninger | op til 33 % |
| 1 karakter | 25 % |
| 3 karakterer | 50 % |
| 3 karakterer + mange afspilninger | 60 % |
| 9 karakterer | 75 % |

### Grænserne

- **Recommended:** score mindst **0,495** og nok oplysninger om pladen
  (datasikkerhed mindst 0,5). Ingen øvre grænse for antallet.
- **Wildcards (væddemål):** score mellem 0,29 og 0,592, gode data, og noget nyt
  for dig: en genre du næsten aldrig hører, eller en usædvanlig blanding. Der
  skal dog være en tydelig forbindelse tilbage til din smag. Ingen øvre grænse
  for antallet.
- **Rækkefølge:** for at give variation trækkes 0,13 fra for hver ekstra plade
  af samme kunstner og 0,04 for hver plade forbundet til samme kunstner, du
  lytter til. Det påvirker kun rækkefølgen.

### Sådan bruges dine karakterer

- **Kunstneren:** dine karakterer for en kunstner indgår i din holdning til
  kunstneren (se ovenfor) og påvirker kunstnerens **andre** plader. Én
  karakter vejer 25 %, flere karakterer vejer mere.
- **Genrerne (andre kunstnere):** hver karakter flytter også genrerne en smule
  for **andre** kunstneres plader – sådan lærer ukendte kunstnere af dine
  karakterer. Én karakter flytter en genre ca. 1/11, fem ens karakterer ca.
  1/3. For kunstnerens egne plader tæller karakteren kun én gang (i din
  holdning), ikke også i genrerne.
- **Beslægtede genrer:** gode karakterer til fx hardcore punk kan give et lille
  skub til beslægtede undergenrer, du ikke selv har rated.
- **Pladen selv:** en karakter ændrer aldrig pladens egen score. Men giver du
  en plade 1–2 ★, vises den ikke længere i Recommended eller Wildcards.

### Not interested og Gem til senere

- **Not interested:** pladen skjules. Det tæller også som 1★ for kunstneren
  (kun kunstneren, ikke genrerne), så kunstnerens fremtidige plader får en
  lavere score. Kunstneren blokeres ikke.
- **Gem til senere:** et bogmærke. Påvirker ikke beregningen.

---

## Regler, du måske ikke selv har valgt

Disse regler er bygget ind i modellen undervejs. Sig til, hvis nogen af dem
skal ændres eller fjernes:

1. Plader med ord som "deluxe", "remaster", "live at", "best of" i titlen
   springes over i den almindelige søgning.
2. Fradrag for "uden for din smag" (op til −0,461).
3. Brede genrer tæller højst 35 %.
4. Last.fm "lignende kunstnere" kan højst være 40 % af musik-delen.
5. Fradrag i rækkefølgen for samme kunstner (−0,13) og samme forbindelse (−0,04).
6. En plade, du har givet 1–2 ★, skjules fra anbefalingerne.
7. Nyere lytning tæller mere end gammel: dine top-kunstnere vægtes efter, hvor
   aktivt du lytter til dem nu (1,0 / 0,9 / 0,7 / 0,5).

Fjernet: tillægget for dansk kunstner (var op til +0,092), tillægget for kendt
kunstner (var +0,263) og lyttehjælpen (var op til +0,197) – afspilninger indgår
nu i din holdning til kunstneren.
