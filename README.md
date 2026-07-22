# UNIPV Semantic Core

Plugin semantico core per i custom post type UNIPV.

Il plugin espone grafi JSON-LD pubblici sotto il namespace REST:

- `/wp-json/unipv/v1/graph`
- `/wp-json/unipv/v1/graph/persone`
- `/wp-json/unipv/v1/graph/strutture`
- `/wp-json/unipv/v1/graph/eventi`
- `/wp-json/unipv/v1/graph/progetti`
- `/wp-json/unipv/v1/graph/indirizzi-di-ricerca`
- `/wp-json/unipv/v1/graph/pubblicazioni`

In installazioni multisite espone inoltre l'indice network:

- `/wp-json/unipv/v1/network/graphs`

L'endpoint restituisce l'elenco dei siti pubblici non esclusi dal Network Admin, con tipologia del sito e rispettivo `/wp-json/unipv/v1/graph`. Le esclusioni sono salvate a livello Network e non vengono clonate con le opzioni di un sottosito; i nuovi siti pubblici sono inclusi automaticamente. L'indice è memorizzato in una cache Network con TTL `DESIITSE_CACHE_TTL`.

Restano riusati dal plugin originale: cache a transient + option, invalidazione su salvataggio contenuti, rebuild asincrono via WP-Cron, rate limiting e toggle di disponibilita API.

La protezione contro richieste massive si applica a tutte le route `/wp-json/unipv/v1/*`, incluso l'indice multisite `/wp-json/unipv/v1/network/graphs`, con limiti per IP, throttle globale e header `X-RateLimit-*`.
In multisite le soglie possono essere configurate dal Network Admin: i valori Network, quando presenti, hanno precedenza sui valori del singolo sito.

## Profilo dati iniziale

Il profilo privilegia modellazioni minime e difendibili sulle ontologie schema.gov.it:

- `persona` -> `cpv:Person`
- `struttura` -> `cov:Organization`
- `evento` -> `cpev:PublicEvent`
- `progetto` -> `her:PublicResearchProject`
- `indirizzo-di-ricerca` -> `skos:Concept` con proprieta Dublin Core, in attesa di una classe HER piu specifica verificata
- `pubblicazione` -> `foaf:Document` con proprieta `dct:*`, in attesa di una modellazione HER/research output piu precisa

La tabella tecnica dei campi letti e delle proprieta RDF e in [`docs/unipv-semantic-mapping.md`](docs/unipv-semantic-mapping.md).
