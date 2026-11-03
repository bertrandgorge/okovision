# Génération du catalogue `resources/okofen/catalog.yaml`

`gen_yaml.py` construit le catalogue des champs à partir de :

1. l'Excel `Traductions Valeurs chaudière.xlsx` (colonnes CSV, libellés FR/EN/DE) ;
2. la configuration de l'interface web du KEBA Touch (`config.min.js`, analysée statiquement, jamais exécutée) et
   les fichiers de langue `language*.js` (FR/EN/DE) ;
3. les métadonnées lues sur l'API (`values.json`, `values2.json`, `get.json` : unités, diviseurs, bornes, listes de choix).

Récupération des sources (lecture seule, depuis le réseau local) :

```bash
B=http://192.168.1.41
curl -c ck -d "username=oekofen&password=oekofen&language=fr&submit=Login" $B/index.cgi
curl -b ck -o language_fr.js $B/lang/language.cgi        # idem en/de en se reconnectant avec language=en|de
curl -o config.min.js http://fernwartung.pelletsheizung.at/res/remotecontrol/v02.00j/js/config.min.js
# values*.json : POST $B/index.cgi?action=get&attr=1 avec un tableau JSON de variables,
#                en-tête « Accept-Language: fr » obligatoire, 25 variables max par requête
```

Puis : `python gen_yaml.py chemin/vers/catalog.yaml "chemin/vers/Traductions Valeurs chaudière.xlsx"`
(dépendances : `pyyaml`, `openpyxl`). Le YAML produit peut ensuite être édité à la main.
