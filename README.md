# Plugin Wordpress per la pubblicazione semantica dei siti di Comuni e Scuole (WIP)

Il plugin permette di generare, a partire dai dati che sono presenti nei siti di Comuni e Scuole realizzati utilizzando i temi wordpress di Designers Italia:

* [Design Comuni Wordpress Theme](https://github.com/italia/design-comuni-wordpress-theme)
* [Design Scuole Wordpress Theme](https://github.com/italia/design-scuole-wordpress-theme)

un grafo JSON-LD che fa riferimento alle ontologie e vocabolari controllati pubblicati dal Catalogo [schema.gov.it](https://schema.gov.it)).

Dopo l'installazione, all'indirizzo
* `https://{base_url}/wp-json/comuni/v1/graph` o
* `https://{base_url}/wp-json/scuole/v1/graph`

sarà disponibile il grafo in formato JSON-LD con tutto il contenuto informativo del sito.

## Installazione

Il plugin è disponibile sul catalogo Wordpress come [Semantic Italia](https://wordpress.org/plugins/design-italia-semantic).

