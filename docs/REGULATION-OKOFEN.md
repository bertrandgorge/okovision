# Régulation Pelletronic Touch : comment la chaudière décide

Référence pour écrire les guides, les recommandations et les alertes d'Okovision. Chaque règle indique sa source :
**[Manuel]** = manuel officiel, **[Mesuré]** = vérifié sur les mesures de l'installation, **[Forum]** = retour
d'expérience à confirmer.

Sources :
- **E1389FR** — *Manuel d'utilisation et d'installation Pelletronic Touch*, V2.00 (ÖkoFEN), 112 p.
  ([copie](https://forums.futura-sciences.com/attachments/habitat-bioclimatique-isolation-chauffage/424788d1604928346-retours-dexperience-chaudieres-okofen-pelletronic-touch-compr.pdf))
- **PE 567 USA 1.1** — *Operating Manual AutoPellet PE(S)(K)(B) 10–56*, partie installateur, 148 p.
  ([Maine Energy Systems](https://www.maineenergysystems.com/wp-content/uploads/2015/11/TechnicianStartUpTouch.pdf))
- **Guide pratique — Entretien annuel PE(S)(K)(B) 8-56**, ÖkoFEN France 2014, chap. « Contrôles de la régulation »
  ([copie](https://forums.futura-sciences.com/attachments/habitat-bioclimatique-isolation-chauffage/363141d1522074139-retours-dexperience-chaudieres-okofen-manuel_entretien-comparatif-touch.pdf))
- Fil [Retours d'expérience chaudières OKOFEN](https://forums.futura-sciences.com/habitat-bioclimatique-isolation-chauffage/568807-retours-dexperience-chaudieres-okofen.html) (Futura-Sciences)

## 1. Modes

**[Manuel]** Mode installation (`anlage_betriebsart`) : *Arrêt* (tout arrêté, hors-gel actif), *Auto* (chaque circuit
suit son mode), *ECS* (eau chaude seule, chauffage inactif quel que soit son réglage).

Mode du circuit de chauffage : *Auto* (programme horaire : confort pendant les plages, réduit en dehors),
*Confort* / *Réduit* permanents, *Arrêt* (hors-gel).

Mode ECS : *Auto* (consigne pendant les plages, T mini en dehors), *Marche* (consigne en permanence), *Arrêt*
(hors-gel > 8 °C).

## 2. Circuit de chauffage : quand le circulateur tourne-t-il ?

Le circuit est **arrêté** (consignes départ et ambiance relevées à 8 °C, pompe arrêtée) dès qu'une de ces conditions
s'applique, et ne repart que lorsqu'elle est levée :

| Condition | Arrêt | Redémarrage | Source |
|---|---|---|---|
| Limite de chauffe confort (`heizgrenze_heizen`) | T ext **moyenne** > limite, pendant une plage confort | T ext moyenne < limite − **2 °C** | [Manuel] p. 21 |
| Limite de chauffe réduit (`heizgrenze_absenken`) | idem pendant une plage réduit | limite − 2 °C | [Manuel] p. 21 |
| Hystérésis d'ambiance (`raumtemp_plus`, sonde d'ambiance) | T ambiante > consigne + hystérésis | T ambiante < consigne + hystérésis − **1 °C** | [Manuel] p. 23, [Mesuré] |
| Mode circuit / installation sur Arrêt ou ECS | — | — | [Manuel] p. 14 |

- La T ext moyenne est une **moyenne glissante** sur `aussentemperatur_mittelungszeit` minutes (0 = instantanée).
  Elle sert aux limites de chauffe et au calcul de la consigne de départ. [Manuel] p. 68
- **Hystérésis d'ambiance à 0 n'est pas « désactivée »** : le circuit s'arrête dès que la pièce atteint la consigne et
  ne repart que 1 °C en dessous (consigne 20 °C → arrêt à 20,0, relance sous 19,0). Le manuel la conseille à 0 seulement
  avec de forts apports (soleil, poêle) dans la pièce de la sonde, « une valeur supérieure est recommandée » sinon.
  [Manuel] p. 23
- **[Mesuré, 10/11/2026]** Une coupure de 30 min dans le programme (16:00–16:30) a basculé le circuit en réduit
  (consigne 18 °C, pièce à 19,6 °C → arrêt). Au retour en confort, la pièce (19,6 °C) était au-dessus du seuil de relance
  (19,0 °C) : le chauffage est resté arrêté toute la soirée et la nuit. Le programme soirée, qui ne fait que forcer le
  confort, n'y pouvait rien.
- **Conseil ÖkoFEN** : « À éviter : hacher les plages de confort et de réduit ». Plages d'au moins 6 h sur plancher
  chauffant. Écart confort/réduit ≤ 2 °C (plancher) ou ≤ 5 °C (radiateurs). Confort > 21 °C à éviter : +1 °C ≈ +7 % de
  consommation. [Guide entretien] p. 47

### Limite de réduit conseillée [Guide entretien] p. 48

« Une limite de réduit à 15 °C signifie que l'on chauffe presque toutes les nuits » ; la régulation redémarre de toute
façon avant la plage de confort (anticipation). Hors sonde d'ambiance :

| Habitat | Limite de réduit |
|---|---|
| fortement isolé, forte inertie | 0 °C |
| bien isolé | 5 °C |
| moyennement isolé | 10 °C |
| peu ou très peu isolé | 14 °C |

Réglages d'usine du manuel : limite confort 18 °C, limite réduit −5 °C. [Manuel] p. 107

## 3. Consigne de départ

- **Loi d'eau** : pente (0–4) et pied (départ à 15 °C extérieur, 20–45 °C). Affinage : un seul réglage par jour ; entre
  +5 et +15 °C on agit sur le pied (±5 °C), entre −20 et +5 °C sur la pente (±0,2). [Manuel] p. 21–22
- **Influence de la sonde d'ambiance** (`raumfuehler_einfluss`) : départ corrigé de *influence × (consigne − ambiance)*.
  Exemple du manuel : consigne 20, mesure 18, influence 3 → départ +6 °C. [Manuel] p. 23
- **Durée d'anticipation** (`vorhaltezeit`) : la chaudière démarre en avance, selon la T ext, pour que la consigne soit
  atteinte au **début** de la plage confort. Inutile donc d'avancer soi-même la plage. Usine : 180 min. [Manuel] p. 23
- **Départ max / min** bornent la loi d'eau ; **Augmentation** (`hk.ueberhoehung`) : réserve ajoutée à la consigne de
  départ pour la consigne chaudière ; la chaudière redémarre quand elle passe sous la consigne de départ. [Manuel] p. 56
- La formule complète identifiée sur 12 ans de mesures est dans `app/Analysis/HeatingCurve.php`.

## 4. Eau chaude sanitaire

- **Cible** : consigne ECS pendant les plages, **T mini** en dehors (mode Auto). [Manuel] p. 26
- **Relance** : une charge démarre quand le ballon descend de « Hyst ECS » sous la cible. [Manuel] p. 59 — le manuel
  ne le dit que pour les plages ; **[Mesuré]** hors plages, la relance se fait aussi à T mini − hystérésis.
- **Valeur relevée `dhw1_set`** (`L_ww[0].temp_soll`) = seuil de relance : cible − hystérésis au repos, cible pendant
  la charge. [Mesuré]
- **Priorité ECS** (`prioritaet`, Marche par défaut) : la chaudière charge d'abord le ballon et **coupe** le chauffage ;
  sur Arrêt, chauffage et ECS en parallèle. [Manuel] p. 59
- **Augmentation ECS** (`ww.ueberhoehung`) : consigne chaudière = consigne ECS + augmentation (60 + 5 = 65 °C).
  [Manuel] p. 59. Une augmentation chaudière (règles système) s'y ajoute encore. [Manuel] p. 68
- **Postfonctionnement ECS** (`nachlaufzeit`) : le circulateur du ballon continue de tourner pour y transférer
  l'énergie excédentaire de la chaudière ; il s'arrête plus tôt si la chaudière devient plus froide que le ballon.
  [Manuel] p. 59. **[Mesuré]** C'est ce qui porte le ballon à ~61 °C pour une consigne de 52 °C (≈ +9 K en 10 min avec
  une chaudière à 78–80 °C, le brûleur de 21 kW ne pouvant moduler assez bas).
- **Anti-légionelles** (`legionellen_wochentag`) : le jour choisi, le ballon monte à **65 °C**. Le manuel (version
  française) indique qu'en France la fonction peut être désactivée pour un stockage < 400 L. [Manuel] p. 60
- **Dérogation** (`einmal_aufbereiten`) : une charge unique hors plage. [Manuel] p. 26

## 5. Chaudière

- **Température mini chaudière** : protection contre la condensation ; **jamais sous 60 °C sans ballon tampon**.
  Les pompes des circuits ne démarrent qu'au-dessus de « T démarrage pompe ». [Manuel] p. 68
- **Température de coupure** (`pe_abschalttemperatur`, ex P202) : T maximale ; le brûleur s'arrête en l'atteignant,
  ou avant si la régulation coupe la demande. [Manuel] p. 81
- **T sécu installation** : au-delà, les pompes de chauffage démarrent pour dissiper l'excès d'énergie. [Manuel] p. 68
- **Hors-gel** : sous la T de protection (4 °C par défaut), circulation forcée cyclique (60 min d'attente / 5 min de
  marche) ; une sonde de départ sous 8 °C déclenche une demande brûleur. [Manuel] p. 69

## 6. Programmes

- 3 plages par jour, 2 programmes par circuit (choix du programme actif). [Manuel] p. 18
- **Programme soirée** : force le confort jusqu'à l'heure de fin puis revient tout seul sur Arrêt. [Manuel] p. 20.
  **[Mesuré]** L'heure de fin s'écrit en heure locale comptée comme de l'UTC, et doit être envoyée **avant** et
  **séparément** de l'activation.
- **Programme vacances** : remplace le programme par une T ambiante réduite entre deux dates ; finir 24 h avant le
  retour. [Manuel] p. 20

## Pistes pour le logiciel

1. Expliquer en direct **pourquoi le chauffage ne tourne pas** (limite de chauffe, hystérésis d'ambiance, plage,
   mode) à partir des règles du §2.
2. Recommandations : plages hachées, hystérésis d'ambiance à 0 avec coupures de programme, confort > 21 °C,
   écart confort/réduit, limite de réduit selon l'isolation.
3. Alerte : circuit arrêté pendant une plage confort alors que la pièce est sous la consigne depuis plus de 2 h.
