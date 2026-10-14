# Gestionale Palestra

Piccolo gestionale per corsi di palestra: corsi, sottocorsi settimanali, iscritti e certificati medici.
PHP puro, nessuna dipendenza, dati su file JSON.

## Requisiti
- PHP 7.3 o superiore (estensioni standard: `mbstring`, `json`)
- Cartella `dati/` scrivibile dal web server

Il codice non usa nessuna sintassi introdotta dopo PHP 7.3, ed è stato collaudato su PHP 8.5:
gira senza modifiche sia su ea-php74 sia su ea-php82.

## Installazione
Il progetto è già disposto per essere copiato così com'è:

```
gest_pal/
    dati/            <- archivio: db.json e backup/. Fuori dalla parte web.
    public/          <- document root: qui dentro tutto il resto
    README.md
    start.ps1        <- avvio del server di prova in locale
```

**Su cPanel**
1. Carica `dati/` e `public/` dentro una cartella fuori da `public_html`,
   per esempio `/home/tuoutente/palestra/`.
2. Crea il sottodominio con **Document Root** su `/home/tuoutente/palestra/public`
   (togli la spunta "condividi la document root", altrimenti punta a WordPress).
3. `chmod 755 dati` (775 se il web server gira con un utente diverso dal tuo).
4. Apri il sottodominio: parte `setup.php` e crei l'amministratore.

Non c'è nulla da configurare: l'applicazione trova da sola `../dati` rispetto a `public/`.
Il percorso realmente in uso è scritto nell'area Admin, con l'indicazione se si trovi
dentro o fuori dalla document root.

**In locale** basta `start.ps1`, che serve `public/` su <http://localhost:8000>.

**Varianti**
- Se metti tutto in una cartella sola (`public_html/gestionale/`), l'applicazione ripiega su
  `data/` accanto ai file: funziona, ma l'archivio è dentro la parte web e lo protegge
  soltanto `.htaccess`.
- Per imporre un percorso qualsiasi, copia `inc/config.local.php.esempio` in
  `inc/config.local.php` e definisci `GESTPAL_DATA_DIR`.

Per popolare l'archivio con un palinsesto di prova apri `seed.php` da amministratore,
poi cancella quel file.

## Struttura
| File | Cosa fa |
|---|---|
| `index.php` | Dashboard: posti liberi, certificati da sistemare, lezioni di oggi, riempimento |
| `calendario.php` | Settimana tipo, un box per giorno, colore per corso |
| `iscritti.php` | Tabella di un sottocorso: una riga per posto, si compila e si salva tutta insieme |
| `persone.php` | Anagrafica: elenco con ricerca, creazione schede e scheda singola (dati, corsi frequentati, iscrivi/disiscrivi) |
| `corsi.php` | Creazione/modifica corsi e sottocorsi (solo admin) |
| `stampa.php` | Fogli da stampare o salvare in PDF: un elenco, tutti gli elenchi, il calendario |
| `utenti.php` | Gestione utenti e ruoli (solo admin) |
| `admin.php` | Area amministrazione: identità, backup, integrità (solo admin) |
| `inc/store.php` | Unico punto che tocca l'archivio dati |
| `dati/db.json` | Tutti i dati (fuori da `public/`) |

## Ruoli
- **Amministratore**: fa tutto.
- **Istruttore**: vede tutto, ma modifica gli iscritti solo dei sottocorsi in cui è indicato come istruttore.

## Modello dati
Un'unica anagrafica `persone` (nome, cognome, telefono, scadenza certificato).
Le `iscrizioni` collegano una persona a un sottocorso e a un posto numerato.
Se la stessa persona frequenta due corsi, la sua scheda è una sola: aggiornando il certificato
in un punto si aggiorna ovunque.

### Come viene riconosciuta una persona
**La chiave è `persona_id`**, un numero progressivo. Il cognome non è mai la chiave:
due persone possono chiamarsi uguale e nessuno dei dati anagrafici è affidabile come identificatore.
Ogni riga della tabella iscritti porta con sé un campo nascosto `persona_id`; è quello che dice
"questa riga è la scheda numero 12", indipendentemente da cosa c'è scritto nelle caselle.

Una scheda può esistere senza nessuna iscrizione: si crea da **Anagrafica → Nuova scheda**
e si iscrive a un corso più avanti, dalla scheda stessa o dalla pagina Iscritti.
In creazione, se esiste già un omonimo esatto l'applicazione si ferma e chiede conferma,
mostrando il link alla scheda esistente.

Il nome serve solo quando l'id non c'è ancora, cioè su una riga nuova. In quel caso:
1. se esiste **un solo** omonimo esatto (nome + cognome, ignorando maiuscole e spazi) la riga
   viene collegata a quella scheda e telefono/certificato si compilano da soli;
2. se il telefono digitato è diverso da quello della scheda trovata, l'applicazione **chiede**
   se è la stessa persona: rispondendo di no si crea una scheda separata;
3. se non trova nessuno, crea una scheda nuova.

Cambiare nome o cognome su una riga già collegata **rinomina la scheda**, quindi il nome cambia
in tutti i corsi. Prima di farlo compare un avviso che elenca gli altri corsi coinvolti.
Se invece al posto di una persona ne è arrivata un'altra, la strada giusta è svuotare la riga
con il tasto ✕ e riscrivere: così la scheda precedente resta intatta in anagrafica.

La capienza è rigida: il numero di righe della tabella è il numero di posti.
Per aggiungere un iscritto oltre il limite bisogna prima alzare i posti nel sottocorso.

## Stampa e PDF
Non serve nessuna libreria PDF: `stampa.php` produce fogli A4 gia' impaginati e apre da solo
il dialogo di stampa del browser, dove si sceglie **Salva come PDF** come destinazione.
I pulsanti sono in Calendario (rispetta i filtri attivi) e in Iscritti (elenco singolo o tutti,
uno per pagina). Il calendario esce in orizzontale, gli elenchi in verticale con tutte le righe,
comprese quelle libere, cosi' il foglio si puo' completare a mano.

## Area amministrazione
Voce **Admin** nel menu, visibile solo agli amministratori (gli altri ricevono 403).

**Identità** — il nome della palestra sostituisce "Gestionale Palestra" ovunque: intestazione,
titolo della scheda del browser, fogli di stampa. Accanto: una riga di contatti stampata in calce
alle pagine e in testa ai fogli, il colore dell'interfaccia e i giorni di preavviso per la scadenza
dei certificati (prima erano fissi a 30). Le impostazioni stanno in `dati/db.json` sotto
`impostazioni` e diventano costanti PHP (`APP_NAME`, `CONTATTI`, `ACCENTO`, `CERT_WARN_DAYS`).

**Archivio** — conteggi, dimensione, data dell'ultimo salvataggio e un controllo esplicito che la
cartella dei dati sia scrivibile (il guasto più comune dopo un trasloco di hosting). Da qui si scarica
il backup completo `.json` e l'anagrafica in `.csv` (separatore `;` e BOM, si apre in Excel senza
toccare niente).

**Controllo integrità** — cerca iscrizioni che puntano a schede o sottocorsi non più esistenti,
posti oltre la capienza attuale e doppioni della stessa persona nello stesso sottocorso, e le rimuove
su richiesta. Le schede anagrafiche non vengono mai toccate.

**Ripristino** — ricarica un backup `.json`. Il file viene validato prima di essere accettato, i dati
attuali finiscono in `dati/backup/` e la sessione viene chiusa (anche gli utenti sono quelli del file).

## Backup
Dall'area Admin, oppure copiando `dati/db.json`. È tutto lì dentro.

## Passare a MySQL
Le pagine non conoscono il formato di archiviazione: parlano solo con `store_read()` e
`store_update()` in `inc/store.php`. Riscrivendo quelle due funzioni (più `next_id`) su PDO
il resto dell'applicazione resta invariato.

## Privacy
L'archivio contiene nomi, telefoni e scadenze di certificati medici.

Nell'installazione piatta la protezione è `data/.htaccess` (e `inc/.htaccess`), che però vale
solo su Apache: basta un passaggio a nginx o una direttiva `AllowOverride` diversa e cade.
Con il layout `public/` descritto sopra l'archivio è irraggiungibile **per costruzione**,
perché non sta dentro l'albero servito dal web server: è la disposizione da preferire.
