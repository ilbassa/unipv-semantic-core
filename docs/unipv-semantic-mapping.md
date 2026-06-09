# Mappatura tecnica UNIPV JSON-LD

Questa tabella descrive il primo profilo semantico implementato. I campi arrivano dagli ACF registrati nei plugin fratelli `ct-*-unipv`; i plugin sorgente sono stati letti ma non modificati.

| CPT | Campi/meta letti | Classe RDF | Proprieta RDF |
| --- | --- | --- | --- |
| opzioni sito | `nome_sito`, `tagline_sito`, `descrizione_presentazione`, `indirizzo_sito`, `email_sito`, `telefono_sito`, `logo_sito`, `facebook`, `youtube`, `instagram`, `twitter`, `linkedin`, `tipologia_sito`, `dipartimento`, `bloginfo(name)` | `cov:PublicOrganization`, `cov:Organization`, `foaf:Document` | `dct:title`, `dct:alternative`, `l0:description`, `sm:URL`, `sm:email`, `sm:telephone`, `sm:hasImage`, `owl:sameAs`, `clv:hasAddress`, `cov:hasOrganization`, `dct:hasPart` |
| `persona` | `nome`, `cognome`, `email`, `telefono`, `sito_web`, `titolo`, `post_content` | `cpv:Person` | `cpv:givenName`, `cpv:familyName`, `sm:email`, `sm:telephone`, `sm:URL`, `ro:withRole`, `l0:description` |
| `struttura` | `post_title`, `descrizione_breve`, `post_content`, `strumentazione`, `software`, `persone-struttura`, `progetti-struttura`, `pubblicazioni-struttura` | `cov:Organization` | `dct:title`, `cov:legalName`, `l0:description`, `dct:relation` |
| `evento` | `descrizione_breve`, `data_inizio`, `data_fine`, `orario_inizio`, `luogo`, `telefono`, `email`, `sitoweb`, `persone`, `progetto`, `indirizzo_di_ricerca`, `post_content` | `cpev:PublicEvent` | `dct:title`, `cpev:eventTitle`, `ti:startTime`, `ti:endTime`, `sm:URL`, `cpev:takesPlaceIn`, `l0:description`, `dct:contributor`, `dct:relation`, `dct:subject` |
| `progetto` | `descrizione_breve`, `data_inizio`, `data_fine`, `url`, `responsabile_del_progetto`, `persone`, `elenco_indirizzi_di_ricerca_correlati`, `pubblicazioni`, `post_content` | `her:PublicResearchProject` | `dct:title`, `ti:startTime`, `ti:endTime`, `sm:URL`, `l0:description`, `dct:contributor`, `dct:subject`, `dct:relation` |
| `indirizzo-di-ricerca` | `descrizione_breve`, `email`, `telefono`, `sitioweb`, `responsabile_attivita_di_ricerca`, `post_content` | `skos:Concept` | `dct:title`, `skos:prefLabel`, `sm:URL`, `sm:email`, `sm:telephone`, `l0:description`, `dct:contributor` |
| `pubblicazione` | `anno`, `autori`, `autori-interni`, `pubblicato-in`, `url`, `url_immagine` | `foaf:Document` | `dct:title`, `dct:issued`, `dct:creator`, `dct:isPartOf`, `sm:URL`, `sm:hasImage` |

## Note aperte

- Il nodo principale del grafo UNIPV e sempre `Università degli studi di Pavia` come `cov:PublicOrganization`. La tipologia del sito determina i nodi intermedi: struttura di Ateneo -> nome sito; evento/progetto di ricerca -> dipartimento se presente -> nome sito; laboratorio di ricerca -> dipartimento -> nome sito.
- `indirizzo-di-ricerca` e trattato come `skos:Concept`: e una scelta prudente per rappresentare un tema/linea di ricerca senza forzare classi HER non ancora verificate.
- `pubblicazione` usa un fallback document-like (`foaf:Document` + `dct:*`). La classe/proprieta HER piu adatta per research output va verificata prima di irrigidire il profilo.
- Le relazioni persona/ruolo sono minime. Il namespace `ro:` e disponibile, ma una reificazione completa dei ruoli richiede campi piu espliciti di quelli attuali.
