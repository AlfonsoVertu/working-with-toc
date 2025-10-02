=== Working with TOC ===
Contributors: workingwithweb
Tags: sommario, indice dei contenuti, seo, accessibilità, dati strutturati, multisite
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Crea un sommario reattivo e fissato in basso con dati strutturati pronti per la SEO.

== Descrizione ==
Working with TOC genera un sommario (TOC) elegante e ottimizzato per i dispositivi mobili per articoli, pagine e prodotti WooCommerce.
Il plugin rispetta le convenzioni di WordPress, suddivide le responsabilità in cartelle dedicate e si integra con Rank Math e Yoast SEO.
Salva la configurazione utilizzando le opzioni standard di WordPress e i post meta, senza creare tabelle personalizzate o sistemi di versioning.

= Funzionalità principali =
* Sommario a fisarmonica fissato nella parte inferiore della finestra con controlli ottimizzati per il tocco.
* Generazione automatica degli anchor dei titoli (`<h2>`–`<h6>`) che mantiene sincronizzati i link del sommario con i titoli della pagina.
* Pagina di amministrazione con interruttori per abilitare il sommario e i dati Schema.org `ItemList` per tipo di contenuto.
* Output JSON-LD compatibile con gli schemi di Rank Math e Yoast SEO, evitando markup duplicati.
* Log condizionale che rispetta il flag `WP_DEBUG` per un debug sicuro negli ambienti di sviluppo.

= Permessi di amministrazione =
Il plugin registra la capacità personalizzata `manage_working_with_toc`. Gli amministratori la ricevono automaticamente e puoi modificare l'accesso tramite il filtro `working_with_toc_admin_capability`:

```
add_filter( 'working_with_toc_admin_capability', function ( $capability ) {
    return 'edit_others_posts';
} );
```

== Installazione ==
1. Carica la cartella `working-with-toc` in `/wp-content/plugins/` oppure installala dalla bacheca di WordPress.
2. Attiva il plugin dal menu **Plugin**.
3. Visita **Impostazioni → Working with TOC** per scegliere dove abilitare il sommario e i dati strutturati.

== Domande frequenti ==
= Quali tipi di contenuto sono supportati? =
Articoli, pagine e prodotti WooCommerce possono generare il sommario e i dati strutturati.

= Il plugin aggiunge markup schema? =
Sì. Il plugin genera un nodo Schema.org `ItemList` che si integra con i grafi di Rank Math o Yoast SEO.

= Quali sono i requisiti minimi? =
Working with TOC richiede WordPress 6.0 o superiore e PHP 7.4 o superiore ed è distribuito con licenza GPLv2 o successiva.

= Il plugin è compatibile con WordPress multisite? =
Sì. Il plugin è stato verificato nelle reti multisite. Puoi attivarlo per singolo sito per impostazioni indipendenti oppure attivarlo a livello di rete per rendere disponibile la pagina di amministrazione su tutti i siti, mantenendo comunque configurazioni indipendenti.

== Screenshot ==
1. Sommario a fisarmonica fissato sul frontend.
2. Pagina delle impostazioni con interruttori specifici per tipo di contenuto.

== Changelog ==
= 1.0.0 =
* Versione iniziale.

== Avviso di aggiornamento ==
= 1.0.0 =
Prima versione pubblica con licenza GPLv2 o successiva.
