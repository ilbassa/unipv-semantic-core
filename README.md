# Semantic University UNIPV

Fork del plugin "Semantic Italia" orientato ai custom post type UNIPV.

Il plugin espone grafi JSON-LD pubblici sotto il namespace REST:

- `/wp-json/unipv/v1/graph`
- `/wp-json/unipv/v1/graph/persone`
- `/wp-json/unipv/v1/graph/strutture`
- `/wp-json/unipv/v1/graph/eventi`
- `/wp-json/unipv/v1/graph/progetti`
- `/wp-json/unipv/v1/graph/indirizzi-di-ricerca`
- `/wp-json/unipv/v1/graph/pubblicazioni`

Restano riusati dal plugin originale: cache a transient + option, invalidazione su salvataggio contenuti, rebuild asincrono via WP-Cron, rate limiting e toggle di disponibilita API.

## Profilo dati iniziale

Il profilo privilegia modellazioni minime e difendibili sulle ontologie schema.gov.it:

- `persona` -> `cpv:Person`
- `struttura` -> `cov:Organization`
- `evento` -> `cpev:PublicEvent`
- `progetto` -> `her:PublicResearchProject`
- `indirizzo-di-ricerca` -> `skos:Concept` con proprieta Dublin Core, in attesa di una classe HER piu specifica verificata
- `pubblicazione` -> `foaf:Document` con proprieta `dct:*`, in attesa di una modellazione HER/research output piu precisa

La tabella tecnica dei campi letti e delle proprieta RDF e in [`docs/unipv-semantic-mapping.md`](docs/unipv-semantic-mapping.md).
