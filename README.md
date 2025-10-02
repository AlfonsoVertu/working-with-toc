# Working with TOC

## Sommario
- [Panoramica](#panoramica)
- [Caratteristiche principali](#caratteristiche-principali)
- [Struttura del plugin](#struttura-del-plugin)
- [Impostazioni di backend](#impostazioni-di-backend)
- [Funzionalità frontend](#funzionalità-frontend)
- [Compatibilità SEO](#compatibilità-seo)
- [Log e debug](#log-e-debug)
- [Guida rapida](#guida-rapida)

## Panoramica

**Working with TOC** è un plugin WordPress pensato per creare automaticamente un indice dei contenuti elegante e mobile-friendly per articoli, pagine e prodotti. Il progetto dimostra una suddivisione del codice secondo le convenzioni WordPress (cartelle `includes/`, `admin/`, `frontend/`, `assets/`) e include strumenti per generare dati strutturati compatibili con Rank Math e Yoast SEO.

## Caratteristiche principali

- Accordion TOC fisso nella parte inferiore dello schermo con design moderno e supporto touch.
- Generazione automatica degli anchor ID sui titoli `<h2>-<h6>` e sincronizzazione con gli URL della TOC.
- Pannello di amministrazione personalizzato con interruttori per attivare TOC e dati strutturati per articoli, pagine e prodotti WooCommerce.
- Output JSON-LD dedicato al tipo `TableOfContents` per articoli, pagine e prodotti, integrato nel grafo schema di Yoast SEO e riconosciuto da Rank Math.
- Logging condizionato sul flag `WP_DEBUG` per agevolare il debug senza inquinare l'ambiente di produzione.

## Struttura del plugin

```
working-with-toc/
├── working-with-toc.php
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── frontend.css
│   └── js/
│       ├── admin.js
│       └── frontend.js
├── includes/
│   ├── admin/
│   │   └── class-admin-page.php
│   ├── frontend/
│   │   └── class-frontend.php
│   ├── structured-data/
│   │   └── class-structured-data-manager.php
│   ├── class-autoloader.php
│   ├── class-heading-parser.php
│   ├── class-logger.php
│   ├── class-plugin.php
│   └── class-settings.php
└── README.md
```

## Impostazioni di backend

Nel menu di amministrazione viene aggiunta una pagina “Working with TOC” con tre card dedicate ai diversi tipi di contenuto. Ogni card include uno switch moderno per attivare o disattivare la TOC e i relativi dati strutturati. Il layout utilizza gradienti, ombre morbide e micro-animazioni per un aspetto premium.

### Permessi personalizzati per la pagina impostazioni

Il plugin utilizza la capability `manage_options` per impostazione predefinita, ma è possibile modificarla tramite il filtro `working_with_toc_admin_capability`. Ad esempio, per concedere l’accesso anche agli editor è sufficiente aggiungere al proprio tema o plugin:

```php
add_filter( 'working_with_toc_admin_capability', function ( $capability ) {
    return 'edit_others_posts';
} );
```

In questo modo la pagina **Working with TOC** sarà visibile a tutti gli utenti con la capability `edit_others_posts` (inclusi gli editor) mantenendo il comportamento originale per gli altri ruoli.

## Funzionalità frontend

La TOC viene generata e mostrata in un accordion fissato al bordo inferiore della finestra. Gli utenti possono aprirla o chiuderla rapidamente; quando è aperta, il contenuto scorre all'interno di un pannello a scomparsa con evidenziazione dinamica della sezione in lettura grazie a `IntersectionObserver`.

## Compatibilità SEO

- **Rank Math**: il plugin si registra tra i TOC supportati, evitando l’avviso “Nessun plugin TOC installato”.
- **Yoast SEO**: i dati strutturati vengono aggiunti al grafo esistente tramite il filtro `wpseo_schema_graph` senza conflitti.
- **Schema.org**: viene generato un nodo `TableOfContents` con riferimenti diretti alle intestazioni del contenuto, con URL coerenti con l’anchor ID generato nel markup.

## Log e debug

Le operazioni principali (inizializzazione, salvataggio impostazioni, rendering schema) vengono tracciate attraverso `error_log` quando `WP_DEBUG` è impostato su `true`, fornendo informazioni utili senza influenzare l’ambiente live.

## Guida rapida

1. Copia la cartella del plugin all’interno di `wp-content/plugins/` e attivalo da WordPress.
2. Visita **Impostazioni → Working with TOC** per scegliere dove abilitare la TOC e i dati strutturati.
3. Modifica o crea un articolo/prodotto: il plugin aggiungerà automaticamente l’indice dei contenuti in un accordion sticky in fondo alla pagina.
4. Verifica con Rank Math o Yoast SEO che l’analisi riconosca la TOC e i dati strutturati.
