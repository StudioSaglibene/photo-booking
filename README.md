# Photo Booking – prenotazioni, pagamenti e consegne

Plugin WordPress/WooCommerce su misura per uno studio fotografico.

- **Prenotazione step-by-step**: pacchetto → data e ora → dati → acconto o intero importo.
- **Pagamento obbligatorio** (almeno l'acconto) con Stripe o qualsiasi gateway WooCommerce.
- **Conferma manuale** della richiesta, con la possibilità di **proporre date alternative** o rifiutare e rimborsare.
- **Saldo** online dall'area privata oppure **in contanti in studio**, registrato dal fotografo.
- **Area privata** per il cliente: prenotazioni, pagamento del saldo, spostamento, disdetta, credito e consegne.
- **Calendario amministrativo** (FullCalendar): spostamento con drag & drop, blocco e sblocco di slot e intervalli, appuntamenti manuali.
- **Consegne foto su Cloudflare R2**: upload diretto dal browser (anche file da 5 GB), link di download temporanei, scadenze automatiche.
- **20 email** modificabili da WooCommerce → Impostazioni → Email.

Requisiti: WordPress 6.4+, WooCommerce 8.5+, PHP 7.4+. Compatibile con HPOS.

---

## Installazione

1. Carica `photo-booking.zip` da **Plugin → Aggiungi nuovo → Carica plugin** e attivalo.
2. Crea una pagina "Prenota" con lo shortcode `[prenotazione]` e selezionala in **Prenotazioni → Impostazioni → Pagina di prenotazione**.
3. Crea i pacchetti: **Prodotti → Aggiungi nuovo** → tipo **"Pacchetto fotografico"**. Nella scheda "Pacchetto fotografico" imposta acconto, durata, pausa, posti, cosa include, indicazioni e date dedicate.
   - Per creare i 4 pacchetti di Natale di esempio con WP-CLI: `wp photo-booking seed-natale --year=2026` (crea bozze; aggiungi `--publish` per pubblicarli subito).
4. Imposta gli orari di apertura e le regole in **Prenotazioni → Impostazioni**.
5. Installa e configura **WooCommerce Stripe Gateway**. Fino a quando non hai le chiavi, usa la modalità test.
6. Configura **Cloudflare R2** (vedi sotto).
7. Configura l'invio email (WP Mail SMTP + Brevo, Postmark, SES…).
8. Consigliato: un cron di sistema che chiami `wp-cron.php` ogni 5-15 minuti, impostando `DISABLE_WP_CRON` a `true`.

### Shortcode

| Shortcode | Uso |
|---|---|
| `[prenotazione]` | tutti i pacchetti pubblicati |
| `[prenotazione categoria="natale"]` | solo i pacchetti di una categoria prodotto |
| `[prenotazione pacchetto="123"]` oppure `pacchetto="natale-classico"` | un solo pacchetto, ad esempio sulla landing di Natale (lo step 1 viene saltato) |
| link `…/prenota/?pacchetto=123` | apre il modulo con il pacchetto già scelto |

---

## Come funziona

### Stati della prenotazione

| Stato | Significato | Occupa il calendario |
|---|---|---|
| In pagamento | slot riservato per 15 minuti durante il checkout | sì |
| In attesa di conferma | pagata, il fotografo deve confermare | sì |
| Proposta inviata | il fotografo ha proposto 1-3 date alternative (riservate per 48 ore) | solo le date proposte |
| Proposta senza risposta | il cliente non ha risposto in tempo | no |
| Da gestire | pagamento arrivato dopo la scadenza del blocco e slot nel frattempo occupato | no |
| Confermata / Svolta | — | sì |
| Rifiutata / Annullata / Scaduta | chiusa | no |

### Pagamenti

Ogni euro incassato è un **ordine WooCommerce**, quindi i report restano corretti:

- **Ordine principale**: acconto oppure intero importo pagato al checkout. Il suo stato è "Acconto versato" o "Completato".
- **Ordine di saldo**: creato quando il cliente clicca "Paga il saldo" nell'area privata, oppure quando generi un link di pagamento dalla scheda.
- **Ordine contanti**: creato con "Registra saldo in contanti". Metodo di pagamento "Contanti in sede", stato completato.

Rimborsi:

- I pagamenti con carta vengono rimborsati in automatico tramite il gateway (Stripe).
- I pagamenti in contanti vengono registrati come rimborso manuale, e ricevi un'email "rimborso manuale da gestire".
- La parte pagata con un credito viene restituita come nuovo credito.

**Credito**: è un coupon WooCommerce personale, monouso, legato all'email del cliente e valido 12 mesi.

- Viene applicato in automatico alla prenotazione successiva.
- Se il credito supera l'acconto, il sistema scala più dell'acconto, fino all'intero importo, così il credito non va sprecato.
- L'eventuale residuo viene riemesso come nuovo credito.

### Policy di disdetta (configurabile)

| Quando disdice il cliente | Esito |
|---|---|
| ≥ 7 giorni prima, oppure richiesta non ancora confermata | sceglie tra rimborso e credito |
| tra 7 giorni e 24 ore prima | solo credito (valido 12 mesi) |
| < 24 ore prima | importo trattenuto |

Il cliente ha **uno spostamento gratuito**, fino a 48 ore prima. Dall'admin puoi sempre scegliere l'esito che preferisci.

### Attività automatiche (ogni 15 minuti)

- Liberano gli slot non pagati.
- Fanno scadere le proposte senza risposta e avvisano te e il cliente.
- Inviano il promemoria il giorno prima (dalle 9:00).
- Gestiscono le consegne: avviso 3 giorni prima della scadenza, scadenza, cancellazione del file da R2 30 giorni dopo, pulizia degli upload interrotti.

---

## Cloudflare R2

1. Cloudflare → R2 → **Crea bucket**. Deve restare privato: non attivare l'accesso pubblico.
2. **Gestisci token API R2** → crea un token "Object Read & Write" limitato al bucket.
3. Bucket → Impostazioni → **CORS**. Al posto di `https://www.tuosito.it` metti il dominio del sito:
   ```json
   [{"AllowedOrigins":["https://www.tuosito.it"],"AllowedMethods":["PUT","GET","HEAD"],"AllowedHeaders":["*"],"ExposeHeaders":["ETag"],"MaxAgeSeconds":3600}]
   ```
4. In **Prenotazioni → Impostazioni → Consegne** inserisci Account ID, Access Key, Secret e bucket, poi clicca **Verifica connessione**.
5. Consigliato, come rete di sicurezza: crea una regola del ciclo di vita del bucket che elimini gli upload multipart incompleti dopo 2 giorni.

Come funziona il caricamento:

- Il file va dal browser **direttamente a R2**, a pezzi da 16 MB o più, con 3 upload in parallelo e retry automatici. Non passa dall'hosting.
- Il download avviene con un link firmato, valido 10 minuti, generato solo dopo il controllo del proprietario e della scadenza.

---

## Struttura del codice

```
photo-booking.php             bootstrap, autoload, compatibilità HPOS
includes/
  class-install.php           tabelle (wp_pb_*), capability, cron
  class-settings.php          impostazioni
  class-availability.php      motore degli slot (unica fonte di disponibilità)
  class-lock.php              mutex anti doppia prenotazione
  class-appointment.php       modello + query + storico
  class-bookings.php          flusso: hold, pagamento, conferma, proposte, spostamenti, disdette
  class-payments.php          ordini collegati, saldo, contanti, rimborsi, crediti
  class-policy.php            regole di disdetta e spostamento
  class-package.php           tipo prodotto "Pacchetto fotografico"
  class-cart.php              integrazione carrello, checkout e ordini
  class-booking-form.php      shortcode [prenotazione]
  class-account.php           area privata (Le mie prenotazioni / Le mie consegne)
  class-deliveries.php        consegne + download protetti + scadenze
  class-r2.php                client S3/R2 (AWS SigV4, verificato con i vettori ufficiali AWS)
  class-emails.php            registro delle 20 email
  class-rest.php              API REST photo-booking/v1
  class-cron.php, class-cli.php
  admin/class-admin.php       menu, pagine, azioni
admin-views/                  viste admin
templates/                    template sovrascrivibili dal tema (yourtheme/photo-booking/…)
assets/                       CSS/JS (JS senza dipendenze, FullCalendar da CDN o in assets/vendor)
```

Hook e filtri utili:

- `pb_delivery_part_size`: dimensione dei pezzi di upload.
- Template sovrascrivibili in `yourtheme/photo-booking/`.

Disinstallazione: i dati vengono conservati, perché sono registrazioni contabili. Per cancellare tutto, aggiungi `define('PB_REMOVE_ALL_DATA', true);` in wp-config prima di eliminare il plugin.
