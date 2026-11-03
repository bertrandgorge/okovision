"""Génère resources/okofen/catalog.yaml à partir de :
 - l'Excel de traductions (mesures CSV)
 - la configuration de l'interface web KEBA (config.min.js, analysée statiquement)
 - les fichiers de langue FR/EN/DE de la chaudière
 - les métadonnées lues sur l'API (unités, diviseurs, bornes, listes de choix)
"""
import json, re, sys
import yaml, openpyxl

OUT = sys.argv[1]
XLSX = sys.argv[2]


def lang(f):
    t = open(f, encoding='utf-8', errors='replace').read()
    return {m.group(1): m.group(2).strip().replace('\\"', '"') for m in re.finditer(r'"([^"]+)":\s*"((?:[^"\\]|\\.)*)"', t)}


L = {'fr': lang('language_fr.js'), 'en': lang('language.js'), 'de': lang('language_de.js')}
api = {r['name']: r for r in json.load(open('values.json')) + json.load(open('values2.json')) if r['status'] == 'OK'}
src = open('config.min.js').read()

# ---------------------------------------------------------------- mesures CSV
STATUS = {0: 'Fonctionnement permanent', 1: 'Démarrage', 2: 'Allumage', 3: 'Allumage stabilisé (softstart)',
          4: 'Combustion', 5: 'Fin de combustion (post-ventilation)', 6: 'Arrêt', 7: 'Aspiration',
          8: 'Défaut cendres', 9: 'Défaut granulés', 10: 'Commutation granulés', 11: 'Défaut',
          97: 'Arrêt', 98: 'Arrêt', 99: 'Arrêt', 100: 'Arrêt', 101: 'Arrêt'}
STATUS_DE = {0: 'Dauerlauf', 1: 'Start', 2: 'Zündung', 3: 'Softstart', 4: 'Leistungsbrand', 5: 'Nachlauf', 6: 'Aus',
             7: 'Saugen', 8: '! Asche !', 9: '! Pellets !', 10: 'Pell Switch', 11: 'Störung', 99: 'Aus'}
STATUS_EN = {0: 'Permanent operation', 1: 'Start', 2: 'Ignition', 3: 'Softstart', 4: 'Heating full power',
             5: 'Run on time', 6: 'Off', 7: 'Suction', 8: 'Ash!', 9: 'Pellets!', 10: 'Pellet switch', 11: 'Fault', 99: 'Off'}

M = [
    # csv header, column, type, unit, api, fr, en, de, description, extra
    ('AT [°C]', 'outside_temp', 'temperature', '°C', 'CAPPL:LOCAL.L_aussentemperatur_ist', 'T extérieure', 'Outside Temperature', 'Außentemperatur', 'Température extérieure mesurée par la sonde extérieure.', {}),
    ('KT Ist [°C]', 'boiler_temp', 'temperature', '°C', 'CAPPL:FA[0].L_kesseltemperatur', 'T chaudière', 'Boiler Temp', 'Kesseltemperatur', 'Température de l\'eau dans la chaudière (vue du régulateur de chauffage).', {}),
    ('KT Soll [°C]', 'boiler_temp_set', 'temperature', '°C', 'CAPPL:FA[0].L_kesseltemperatur_soll_anzeige', 'T chaudière consigne', 'Boiler Temp Set', 'Kesseltemperatur Soll', 'Température chaudière demandée par les circuits (max(T départ consigne + surélévation, T chaudière mini)). 8 °C = pas de demande.', {}),
    ('BR ', 'burner_request', 'bool', None, 'CAPPL:FA[0].L_br1', 'Demande brûleur', 'Burner Contact', 'Brennerkontakt', 'Contact BR1 : le régulateur demande de la chaleur au brûleur.', {}),
    ('HK1 VL Ist[°C]', 'hc1_flow_temp', 'temperature', '°C', 'CAPPL:LOCAL.L_hk[0].vorlauftemp_ist', 'Chf1 T départ', 'HC1 Flow Temp', 'HK1 Vorlauftemperatur', 'Température de départ mesurée du circuit de chauffage 1 (après vanne mélangeuse).', {}),
    ('HK1 VL Soll[°C]', 'hc1_flow_set', 'temperature', '°C', 'CAPPL:LOCAL.L_hk[0].vorlauftemp_soll', 'Chf1 T départ consigne', 'HC1 Flow Temp Set', 'HK1 Vorlauftemperatur Soll', 'Consigne de départ calculée par la loi d\'eau. 8 °C = circuit à l\'arrêt (limite de chauffe atteinte).', {}),
    ('HK1 RT Ist[°C]', 'hc1_room_temp', 'temperature', '°C', 'CAPPL:LOCAL.L_hk[0].raumtemp_ist', 'Chf1 T ambiante', 'HC1 Room Temp', 'HK1 Raumtemperatur', 'Température ambiante mesurée par la sonde d\'ambiance du circuit 1.', {}),
    ('HK1 RT Soll[°C]', 'hc1_room_set', 'temperature', '°C', 'CAPPL:LOCAL.L_hk[0].raumtemp_soll', 'Chf1 T ambiante consigne', 'HC1 Room Temp Set', 'HK1 Raumtemperatur Soll', 'Consigne d\'ambiance active (confort ou réduit selon le programme horaire).', {}),
    ('HK1 Pumpe', 'hc1_pump', 'bool', None, 'CAPPL:LOCAL.L_hk[0].pumpe', 'Chf1 Pompe', 'HC1 Pump', 'HK1 Pumpe', 'Circulateur du circuit de chauffage 1.', {}),
    ('WW1 EinT Ist[°C]', 'dhw1_temp_on', 'temperature', '°C', 'CAPPL:LOCAL.L_ww[0].einschaltfuehler_ist', 'ECS1 T démarrage', 'DHW1 On Temperature', 'WW1 Ein Temperatur', 'Température du ballon d\'eau chaude au niveau de la sonde d\'enclenchement.', {}),
    ('WW1 AusT Ist[°C]', 'dhw1_temp_off', 'temperature', '°C', 'CAPPL:LOCAL.L_ww[0].ausschaltfuehler_ist', 'ECS1 T arrêt', 'DHW1 Off Temperature', 'WW1 Aus Temperatur', 'Température du ballon au niveau de la sonde de coupure (identique à la sonde d\'enclenchement s\'il n\'y a qu\'une sonde).', {}),
    ('WW1 Soll[°C]', 'dhw1_set', 'temperature', '°C', 'CAPPL:LOCAL.L_ww[0].temp_soll', 'ECS1 consigne', 'DHW1 Set', 'WW1 Soll', 'Consigne ECS active (consigne ou T min ECS selon le programme horaire).', {}),
    ('WW1 Pumpe', 'dhw1_pump', 'bool', None, 'CAPPL:LOCAL.L_ww[0].pumpe', 'ECS1 Pompe', 'DHW1 Pump', 'WW1 Pumpe', 'Pompe de charge du ballon ECS : à 1, la chaudière produit de l\'eau chaude.', {}),
    ('Zubrp1 Pumpe', 'feed_pump1', 'bool', None, 'CAPPL:LOCAL.L_zubrp[0].pumpe', 'Pompe de charge (zubringer)', 'Feed Pump 1', 'Zubringerpumpe 1', 'Pompe de transfert/recirculation (Zubringerpumpe).', {}),
    ('PE1 KT[°C]', 'pe1_boiler_temp', 'temperature', '°C', 'CAPPL:FA[0].L_kesseltemperatur', 'PE1 T chaudière', 'PE1 Boiler Temp', 'PE1 Kesseltemperatur', 'Température chaudière mesurée par l\'automate du brûleur Pellematic 1.', {}),
    ('PE1 FRT Ist[°C]', 'pe1_flame_temp', 'temperature', '°C', 'CAPPL:FA[0].L_feuerraumtemperatur', 'PE1 T flamme', 'PE1 Comb Chamber T', 'PE1 Feuerraumtemperatur', 'Température du foyer (chambre de combustion).', {}),
    ('PE1 FRT Soll[°C]', 'pe1_flame_set', 'temperature', '°C', 'CAPPL:FA[0].L_feuerraumtemperatur_soll', 'PE1 T flamme consigne', 'PE1 Comb Chamber T Set', 'PE1 Feuerraumtemperatur Soll', 'Consigne de température de foyer (régulation de la modulation).', {}),
    ('PE1 Einschublaufzeit[zs]', 'pe1_auger_run', 'duration_cs', 'cs', 'CAPPL:FA[0].L_einschublaufzeit', 'PE1 t alim', 'PE1 Auger Run Time', 'PE1 Einschubzeit', 'Durée de marche de la vis brûleur par impulsion (en centisecondes). Avec le temps de pause, donne le taux d\'alimentation : c\'est la base du calcul de consommation.', {}),
    ('PE1 Pausenzeit[zs]', 'pe1_auger_pause', 'duration_cs', 'cs', 'CAPPL:FA[0].L_pausenzeit', 'PE1 t pause', 'PE1 Rest Time', 'PE1 Pausenzeit', 'Durée de pause de la vis brûleur entre deux impulsions (en centisecondes).', {}),
    ('PE1 Luefterdrehzahl[%]', 'pe1_fan', 'percent', '%', 'CAPPL:FA[0].L_luefterdrehzahl', 'PE1 vitesse V comb', 'PE1 Burner Fan Speed', 'PE1 Lüfter Drehzahl', 'Vitesse du ventilateur d\'air de combustion : bon indicateur de la puissance de modulation.', {}),
    ('PE1 Saugzugdrehzahl[%]', 'pe1_flue_fan', 'percent', '%', 'CAPPL:FA[0].L_saugzugdrehzahl', 'PE1 vitesse V fumées', 'PE1 Flue Gas Fan Speed', 'PE1 Abgasgebläse Drehzahl', 'Vitesse de l\'extracteur de fumées.', {}),
    ('PE1 Unterdruck Ist[Pa]', 'pe1_draft', 'pressure', 'Pa', 'CAPPL:FA[0].L_unterdruck', 'PE1 Dépression', 'PE1 Negative Draft', 'PE1 Unterdruck', 'Dépression mesurée dans le foyer.', {}),
    ('PE1 Unterdruck Soll[Pa]', 'pe1_draft_set', 'pressure', 'Pa', 'CAPPL:FA[0].unterdruck_sollwert', 'PE1 Dépression consigne', 'PE1 Negative Draft Set', 'PE1 Unterdruck Soll', 'Consigne de dépression.', {}),
    ('PE1 Status', 'pe1_status', 'enum', None, 'CAPPL:FA[0].L_kesselstatus', 'PE1 Etat chaudière', 'PE1 Boiler Mode', 'PE1 Kesselstatus', 'Phase de fonctionnement du brûleur.', {'options': {'fr': STATUS, 'en': STATUS_EN, 'de': STATUS_DE}}),
    ('PE1 Motor ES', 'pe1_motor_auger', 'bool', None, 'CAPPL:FA[0].ausgang_motor[11]', 'PE1 Moteur vis brûleur', 'PE1 Motor Delivery', 'PE1 Motor Einschub', 'Moteur de la vis d\'alimentation du brûleur (Einschub).', {}),
    ('PE1 Motor RA', 'pe1_motor_silo', 'bool', None, 'CAPPL:FA[0].ausgang_motor[8]', 'PE1 Moteur extraction silo', 'PE1 Motor RA1', 'PE1 Motor RA1', 'Moteur d\'extraction du silo (Raumaustragung : vis sans fin silo → trémie).', {}),
    ('PE1 Motor RES1', 'pe1_motor_res1', 'bool', None, 'CAPPL:FA[0].ausgang_motor[3]', 'PE1 Moteur Rés1', 'PE1 Motor Res1', 'PE1 Motor Res1', 'Sortie moteur de réserve 1 (selon l\'installation : trémie intermédiaire, 2e vis…).', {}),
    ('PE1 Motor TURBINE', 'pe1_motor_turbine', 'bool', None, 'CAPPL:FA[0].ausgang_motor[0]', 'PE1 Turbine', 'PE1 Turbine', 'PE1 Turbine', 'Turbine d\'aspiration (systèmes aspirés uniquement).', {}),
    ('PE1 Motor ZUEND', 'pe1_motor_ignition', 'bool', None, 'CAPPL:FA[0].ausgang_motor[1]', 'PE1 Allumeur', 'PE1 Ignition Stick', 'PE1 Glühstab', 'Allumeur électrique (bougie) : à 1 lors d\'un allumage à froid.', {}),
    ('PE1 Motor UW[%]', 'pe1_pump_uw', 'percent', '%', 'CAPPL:FA[0].L_drehzahl_uw_ist', 'PE1 vitesse pompe chaudière', 'PE1 UW Speed', 'PE1 Drehzahl UW', 'Pompe de circulation chaudière (Umwälzpumpe).', {}),
    ('PE1 Motor AV', 'pe1_motor_ash', 'bool', None, 'CAPPL:FA[0].ausgang_motor[2]', 'PE1 Moteur cendrier ext', 'PE1 Motor Ash Box', 'PE1 Motor Aschebox', 'Vis de décendrage vers le cendrier extérieur.', {}),
    ('PE1 Motor RES2', 'pe1_motor_res2', 'bool', None, None, 'PE1 Moteur Rés2', 'PE1 Motor Res2', 'PE1 Motor Res2', 'Sortie moteur de réserve 2.', {}),
    ('PE1 Motor MA', 'pe1_motor_ma', 'bool', None, None, 'PE1 Moteur électrovanne', 'PE1 Motor MA', 'PE1 Motor MA', 'Électrovanne / moteur MA (rinçage échangeur selon modèle).', {}),
    ('PE1 Motor RM', 'pe1_motor_cleaning', 'bool', None, 'CAPPL:FA[0].ausgang_motor[5]', 'PE1 Moteur ramonage', 'PE1 Motor Cleaning Sys', 'PE1 Motor Reinigung', 'Moteur de nettoyage automatique de l\'échangeur (ramonage).', {}),
    ('PE1 Motor SM', 'pe1_motor_sm', 'bool', None, None, 'PE1 Moteur SM', 'PE1 Motor SM', 'PE1 Motor SM', 'Sortie SM (non documentée).', {}),
]

# Traductions de l'Excel (contrôle / complément)
wb = openpyxl.load_workbook(XLSX)
ws = wb.active
xl = {}
for r in ws.iter_rows(min_row=4, values_only=True):
    if r[5]:
        xl[r[5].strip()] = {'en': r[0], 'fr': r[1], 'de': r[2]}
    if r[6] and r[6].strip() and '[' in r[6]:
        xl[r[6].strip()] = {'en': (r[0] or '') + ' (set)', 'fr': (r[1] or '') + ' consigne', 'de': (r[2] or '') + ' Soll'}

measurements = []
for csv, col, typ, unit, apiv, fr, en, de, desc, extra in M:
    item = {'column': col, 'csv': csv.strip(), 'type': typ}
    if unit:
        item['unit'] = unit
    if apiv:
        item['api'] = apiv
    item['labels'] = {'fr': fr, 'en': en, 'de': de}
    item['description'] = desc
    item.update(extra)
    measurements.append(item)

# ---------------------------------------------------------------- paramètres
GROUPS = {
    'menue_hc': ('hc', 'Circuit de chauffage'),
    'menue_hc_einstellungen': ('hc', 'Circuit de chauffage'),
    'menue_ww_einstellungen': ('dhw', 'Eau chaude sanitaire'),
    'menue_pellematic_einstellungen': ('boiler', 'Chaudière'),
    'menue_pellematic_zuendung': ('ignition', 'Allumage'),
    'menue_pellematic_unterdruck': ('draft', 'Dépression'),
    'menue_pellematic_frt_regelung': ('flame', 'Régulation T flamme'),
    'menue_pellematic_leistungsbrand': ('power', 'Combustion / puissance'),
    'menue_pellematic_nachlauf': ('runon', 'Fin de combustion'),
    'menue_pellematic_turbine': ('feed', 'Alimentation granulés'),
    'menue_pellematic_fuellstand': ('level', 'Niveau / pesée'),
    'menue_pellematic_reinigung': ('cleaning', 'Ramonage'),
    'menue_pellematic_uw': ('pump', 'Pompe chaudière'),
    'menue_pellematic_entaschung': ('ash', 'Décendrage'),
    'menue_pellematic_frischwasser': ('freshwater', 'Module eau fraîche'),
    'menue_pellematic_waschen': ('wash', 'Rinçage'),
    'menue_systemregelung': ('system', 'Système'),
    'menue_allgemeines': ('general', 'Général'),
    'menue': ('modes', 'Modes de fonctionnement'),
}

# Paramètres « essentiels » (niveau utilisateur) + aide détaillée
HELP = {
    'CAPPL:LOCAL.hk[0].heizkurve_steigung': ("Pente de la loi d'eau : nombre de °C de départ en plus pour 1 °C extérieur en moins. "
        "Convecteurs à ailettes : typiquement 1,2 à 1,8. Ajuster par pas de 0,1–0,2, au plus une fois par jour, lorsque la T ext est entre -20 et +5 °C.", 'basic'),
    'CAPPL:LOCAL.hk[0].heizkurve_fusspunkt': ("Pied de courbe : température de départ pour une T ext de 15 °C (avec 20 °C de consigne ambiante). "
        "Il décale la courbe parallèlement. À ajuster (par pas de 2 à 5 °C) quand il fait trop chaud ou trop froid par T ext de +5 à +15 °C.", 'basic'),
    'CAPPL:LOCAL.hk[0].raumtemp_heizen': ("Consigne d'ambiance en période de confort. Chaque °C modifie la consigne de départ de (1 + pente) °C.", 'basic'),
    'CAPPL:LOCAL.hk[0].raumtemp_absenken': ("Consigne d'ambiance en période réduite (nuit/absence).", 'basic'),
    'CAPPL:LOCAL.hk[0].raumfuehler_einfluss': ("Influence de la sonde d'ambiance : °C de départ ajoutés par °C d'écart entre consigne et ambiance mesurée. "
        "0 = loi d'eau pure. 2–4 recommandé si la sonde est dans une pièce représentative sans autre source de chaleur.", 'basic'),
    'CAPPL:LOCAL.hk[0].heizgrenze_heizen': ("Limite de chauffe en confort : au-dessus de cette T ext moyennée, le circuit s'arrête. Pour une maison isolée : 15–17 °C.", 'basic'),
    'CAPPL:LOCAL.hk[0].heizgrenze_absenken': ("Limite de chauffe en réduit : au-dessus de cette T ext moyennée, le circuit s'arrête en période réduite.", 'basic'),
    'CAPPL:LOCAL.hk[0].vorlauftemp_max': ("Température de départ maximale du circuit (sécurité émetteurs).", 'basic'),
    'CAPPL:LOCAL.hk[0].vorlauftemp_min': ("Température de départ minimale lorsque le circuit chauffe.", 'basic'),
    'CAPPL:LOCAL.hk[0].ueberhoehung': ("Surélévation : écart ajouté à la consigne de départ pour obtenir la consigne chaudière (compense la vanne mélangeuse).", 'basic'),
    'CAPPL:LOCAL.hk[0].vorhaltezeit': ("Temps d'anticipation : démarrage du chauffage avant le début de la plage confort.", 'basic'),
    'CAPPL:LOCAL.hk[0].raumtemp_plus': ("Hystérésis d'ambiance : la pompe du circuit s'arrête lorsque T ambiante > consigne + hystérésis. 0 = désactivé.", 'basic'),
    'CAPPL:LOCAL.ww[0].temp_heizen': ("Consigne d'eau chaude pendant les plages horaires ECS. Recommandé ≥ 50 °C (légionelles).", 'basic'),
    'CAPPL:LOCAL.ww[0].temp_absenken': ("Température ECS minimale hors plages horaires : le ballon est rechargé s'il descend sous cette valeur.", 'basic'),
    'CAPPL:LOCAL.ww[0].hysterese': ("Hystérésis ECS : la charge démarre quand la T du ballon descend de cette valeur sous la consigne. Une valeur plus grande espace les démarrages.", 'basic'),
    'CAPPL:LOCAL.ww[0].ueberhoehung': ("Surélévation ECS : écart chaudière/ballon pendant la charge.", 'basic'),
    'CAPPL:LOCAL.ww[0].prioritaet': ("Priorité ECS : le circuit de chauffage est réduit pendant la charge du ballon.", 'basic'),
    'CAPPL:LOCAL.ww[0].legionellen_wochentag': ("Jour du cycle anti-légionelles hebdomadaire (montée à ~65 °C).", 'basic'),
    'CAPPL:LOCAL.ww[0].einmal_aufbereiten': ("Charge ECS unique immédiate (dérogation).", 'basic'),
    'CAPPL:FA[0].pe_kesseltemperatur_soll': ("Température de régulation de la chaudière : le brûleur module pour la maintenir. Si un circuit demande plus, c'est la demande qui s'applique.", 'basic'),
    'CAPPL:FA[0].pe_abschalttemperatur': ("Température de coupure : le brûleur s'arrête quand la chaudière l'atteint.", 'basic'),
    'CAPPL:FA[0].pe_einschalthysterese_smart': ("Hystérésis de redémarrage : le brûleur redémarre quand T chaudière < T coupure − hystérésis (s'il y a une demande). "
        "Augmenter cette valeur réduit le nombre de démarrages (cycles plus longs).", 'basic'),
    'CAPPL:FA[0].pe_kesselleistung': ("Puissance nominale paramétrée (kW). Réduire la puissance abaisse aussi la puissance minimale de modulation : utile si la chaudière est surdimensionnée. Réglage technicien.", 'expert'),
    'CAPPL:FA[0].pe_kesselmindestlaufzeit': ("Durée minimale de fonctionnement du brûleur.", 'expert'),
    'CAPPL:FA[0].pe_minimum_stillstandszeit': ("Durée d'arrêt minimale avant un nouvel allumage par allumeur.", 'expert'),
    'CAPPL:FA[0].betriebsart_fa': ("Mode du brûleur Pellematic.", 'basic'),
    'CAPPL:LOCAL.anlage_betriebsart': ("Mode général de l'installation : Arrêt, Auto (chauffage + ECS) ou ECS seule (été).", 'basic'),
    'CAPPL:LOCAL.hk[0].betriebsart[1]': ("Mode du circuit de chauffage 1 (installation en Auto) : Arrêt, Auto (programme horaire), Confort permanent, Réduit permanent.", 'basic'),
    'CAPPL:LOCAL.ww[0].betriebsart[1]': ("Mode ECS (installation en Auto).", 'basic'),
    'CAPPL:LOCAL.ww[0].betriebsart[2]': ("Mode ECS (installation en ECS seule).", 'basic'),
    'CAPPL:LOCAL.ke_temp_min': ("Température chaudière minimale demandée dès qu'un circuit chauffe (protection contre la condensation).", 'basic'),
    'CAPPL:LOCAL.aussentemperatur_mittelungszeit': ("Durée de moyennage de la T extérieure utilisée pour les limites de chauffe.", 'basic'),
    'CAPPL:LOCAL.hk[0].urlaubsprg_aktiviert': ("Programme vacances (température vacances).", 'basic'),
    'CAPPL:LOCAL.hk[0].raumtemp_urlaub': ("Consigne d'ambiance pendant le programme vacances.", 'basic'),
    'CAPPL:LOCAL.hk[0].partyprg_aktiviert': ("Programme soirée : prolonge le confort.", 'basic'),
    'CAPPL:FA[0].uw_freigabetemperatur': ("T chaudière à partir de laquelle la pompe chaudière démarre.", 'expert'),
    'CAPPL:FA[0].leistung_brennstoffkorrektur': ("Correction combustible (qualité des granulés).", 'expert'),
}

LABELS_FR = {
    'CAPPL:LOCAL.hk[0].heizkurve_steigung': 'Pente de la loi d\'eau',
    'CAPPL:LOCAL.hk[0].heizkurve_fusspunkt': 'Pied de courbe',
    'CAPPL:LOCAL.hk[0].heizgrenze_heizen': 'Limite de chauffe (confort)',
    'CAPPL:LOCAL.hk[0].heizgrenze_absenken': 'Limite de chauffe (réduit)',
    'CAPPL:LOCAL.hk[0].raumtemp_heizen': 'Consigne ambiance confort',
    'CAPPL:LOCAL.hk[0].raumtemp_absenken': 'Consigne ambiance réduit',
    'CAPPL:LOCAL.hk[0].raumtemp_urlaub': 'Consigne ambiance vacances',
    'CAPPL:LOCAL.hk[0].raumfuehler_einfluss': 'Influence de la sonde d\'ambiance',
    'CAPPL:LOCAL.hk[0].raumtemp_plus': 'Hystérésis d\'ambiance',
    'CAPPL:LOCAL.hk[0].ueberhoehung': 'Surélévation chaudière / départ',
    'CAPPL:LOCAL.hk[0].vorlauftemp_max': 'Température de départ maxi',
    'CAPPL:LOCAL.hk[0].vorlauftemp_min': 'Température de départ mini',
    'CAPPL:LOCAL.hk[0].vorhaltezeit': 'Temps d\'anticipation',
    'CAPPL:LOCAL.hk[0].betriebsart[1]': 'Mode du circuit de chauffage',
    'CAPPL:LOCAL.ww[0].temp_heizen': 'Consigne eau chaude',
    'CAPPL:LOCAL.ww[0].temp_absenken': 'Eau chaude : température mini',
    'CAPPL:LOCAL.ww[0].hysterese': 'Hystérésis eau chaude',
    'CAPPL:LOCAL.ww[0].ueberhoehung': 'Surélévation eau chaude',
    'CAPPL:LOCAL.ww[0].prioritaet': 'Priorité eau chaude',
    'CAPPL:LOCAL.ww[0].legionellen_wochentag': 'Jour du cycle anti-légionelles',
    'CAPPL:LOCAL.ww[0].einmal_aufbereiten': 'Charge d\'eau chaude immédiate',
    'CAPPL:LOCAL.ww[0].betriebsart[1]': 'Mode eau chaude (installation en Auto)',
    'CAPPL:LOCAL.ww[0].betriebsart[2]': 'Mode eau chaude (installation en ECS seule)',
    'CAPPL:LOCAL.ke_temp_min': 'Température chaudière mini',
    'CAPPL:LOCAL.aussentemperatur_mittelungszeit': 'Durée de moyennage de la T extérieure',
    'CAPPL:FA[0].pe_kesseltemperatur_soll': 'Température de régulation chaudière',
    'CAPPL:FA[0].pe_abschalttemperatur': 'Température de coupure',
    'CAPPL:FA[0].pe_kesselleistung': 'Puissance paramétrée de la chaudière',
    'CAPPL:FA[0].betriebsart_fa': 'Mode du brûleur',
    'CAPPL:LOCAL.anlage_betriebsart': 'Mode de l\'installation',
}

NUM_TYPES = {'Temperatur': 'temperature', 'Zahl': 'number', 'Auswahl': 'enum', 'Text': 'text', 'Uhrzeit': 'time',
             'DatumUndZeit': 'datetime', 'Passwort': 'password'}


def clean_options(ft):
    if not ft:
        return None
    parts = ft.split('|')
    opts = {}
    for i, p in enumerate(parts):
        p = p.strip()
        if p and p != '1':
            opts[i] = p
    return opts or None


def num(x):
    try:
        f = float(x)
        return int(f) if f.is_integer() else f
    except Exception:
        return None


pat = re.compile(r'([A-Za-z0-9_]+):\{titel:Lang\.([A-Za-z0-9_]+)((?:[^{}]|\{[^{}]*\})*)\}')
parts = re.split(r'(?:^|;)var ([A-Za-z_]+)\s*=', src)
params = {}
for i in range(1, len(parts), 2):
    sec, body = parts[i], parts[i + 1]
    if sec not in GROUPS:
        continue
    for m in pat.finditer(body):
        key, tok, rest = m.groups()
        w = re.search(r'wert:"([^"]*)"', rest)
        if not w or not w.group(1).startswith('CAPPL'):
            continue
        tmpl = w.group(1)
        name = re.sub(r'\[\$\d?\]', '[0]', tmpl)
        a = api.get(name)
        if not a:
            continue  # composant absent de l'installation
        if 'kaskade' in name or 'LOCAL.sk[' in name or 'LOCAL.pu[' in name or 'bestke' in name:
            continue
        if name in params:
            continue
        wt = re.search(r'wertTyp:WertTyp\.(\w+)', rest)
        typ = NUM_TYPES.get(wt.group(1) if wt else '', 'number')
        div = num(a.get('divisor')) or 1
        p = {'name': name, 'group': GROUPS[sec][0], 'type': typ}
        unit = a.get('unitText')
        if unit and unit != '???':
            p['unit'] = unit.replace('zs', 'cs')
        if div != 1:
            p['divisor'] = div
        lo, hi = num(a.get('lowerLimit')), num(a.get('upperLimit'))
        if lo is not None and hi is not None:
            p['min'] = round(lo / div, 3)
            p['max'] = round(hi / div, 3)
        dec = re.search(r'kommastellen:(\d+)', rest)
        if dec:
            p['decimals'] = int(dec.group(1))
        clean = lambda t: re.sub(r'\s+', ' ', re.sub(r'<br\s*/?>', ' – ', re.sub(r'</?strong>', '', t))).replace('$', '1').strip()
        p['labels'] = {
            'fr': LABELS_FR.get(name, clean(L['fr'].get(tok, tok))),
            'en': clean(L['en'].get(tok, tok)),
            'de': clean(L['de'].get(tok, tok)),
        }
        if a.get('shortText') and a['shortText'] != '???':
            p['short_fr'] = a['shortText']
        opts = clean_options(a.get('formatTexts'))
        if opts and typ in ('enum', 'number'):
            p['type'] = 'enum'
            p['options'] = opts
        h = HELP.get(name)
        p['level'] = h[1] if h else 'expert'
        if h:
            p['description'] = h[0]
        p['writable'] = typ not in ('text',) and not name.split('.')[-1].startswith('L_')
        # Valeurs instantanées / commandes (horloge de l'automate, acquittement…) : pas des réglages
        if name.split('.')[-1].startswith('L_'):
            p['snapshot'] = False
        params[name] = p

# Paramètres absents de l'interface web mais utilisés (métadonnées lues sur l'API)
for r in json.load(open('../get.json')):
    n = r['name']
    if n == 'CAPPL:FA[0].pe_einschalthysterese_smart':
        params[n] = {'name': n, 'group': 'boiler', 'type': 'temperature', 'unit': 'K', 'divisor': 10, 'min': 2.0, 'max': 15.0,
                     'labels': {'fr': 'Hystérésis de redémarrage', 'en': 'Switch On Hysteresis', 'de': 'Einschalthysterese'},
                     'short_fr': 'Hyst enclench', 'level': 'basic', 'description': HELP[n][0], 'writable': True}

COUNTERS = [
    ('CAPPL:FA[0].L_brennerstarts', 'burner_starts', 'Démarrages brûleur', 'Burner Starts', 'Brennerstarts', None),
    ('CAPPL:FA[0].L_anzahl_zuendung', 'ignitions', 'Nombre d\'allumages', 'Number of Ignitions', 'Anzahl Zündungen', None),
    ('CAPPL:FA[0].L_brennerlaufzeit_anzeige', 'burner_hours', 'Heures de fonctionnement brûleur', 'Burner Run Time', 'Brennerlaufzeit', 'h'),
    ('CAPPL:FA[0].L_mittlere_laufzeit', 'mean_run_minutes', 'Durée moyenne de fonctionnement', 'Average Run Time', 'Mittlere Laufzeit', 'min'),
    ('CAPPL:FA[0].L_sillstandszeit', 'standby_hours', 'Durée d\'arrêt cumulée', 'Down Time', 'Stillstandszeit', 'h'),
    ('CAPPL:FA[0].L_kesselstatus', 'status', 'État chaudière', 'Boiler Status', 'Kesselstatus', None),
    ('CAPPL:FA[0].L_kesseltemperatur', 'boiler_temp', 'T chaudière', 'Boiler Temp', 'Kesseltemperatur', '°C'),
    ('CAPPL:LOCAL.L_aussentemperatur_ist', 'outside_temp', 'T extérieure', 'Outside Temp', 'Außentemperatur', '°C'),
    ('CAPPL:LOCAL.L_hk[0].raumtemp_ist', 'hc1_room_temp', 'T ambiante', 'Room Temp', 'Raumtemperatur', '°C'),
    ('CAPPL:LOCAL.L_hk[0].vorlauftemp_ist', 'hc1_flow_temp', 'T départ', 'Flow Temp', 'Vorlauftemperatur', '°C'),
    ('CAPPL:LOCAL.L_hk[0].vorlauftemp_soll', 'hc1_flow_set', 'T départ consigne', 'Flow Temp Set', 'Vorlauftemperatur Soll', '°C'),
    ('CAPPL:LOCAL.L_ww[0].einschaltfuehler_ist', 'dhw1_temp', 'T ECS', 'DHW Temp', 'Warmwassertemperatur', '°C'),
    ('CAPPL:FA[0].L_feuerraumtemperatur', 'flame_temp', 'T flamme', 'Comb. Chamber Temp', 'Feuerraumtemperatur', '°C'),
    ('CAPPL:FA[0].L_luefterdrehzahl', 'fan', 'Ventilateur combustion', 'Burner Fan', 'Lüfterdrehzahl', '%'),
    ('CAPPL:FA[0].L_pelletsfuellstand', 'hopper_full', 'Niveau trémie', 'Pellet Level', 'Pelletsfüllstand', None),
    ('CAPPL:LOCAL.touch[0].version', 'touch_version', 'Version Touch', 'Touch Version', 'Software Bedienteil', None),
]
live = []
for n, key, fr, en, de, unit in COUNTERS:
    a = api.get(n) or {}
    it = {'key': key, 'name': n, 'labels': {'fr': fr, 'en': en, 'de': de}}
    div = num(a.get('divisor'))
    if div and div != 1:
        it['divisor'] = div
    if unit:
        it['unit'] = unit
    live.append(it)

ORDER = ['modes', 'hc', 'dhw', 'boiler', 'system', 'power', 'flame', 'draft', 'ignition', 'runon', 'feed', 'level',
         'ash', 'cleaning', 'pump', 'wash', 'freshwater', 'general']
plist = sorted(params.values(), key=lambda p: (ORDER.index(p['group']), p['level'] != 'basic', p['name']))

doc = {
    'version': 1,
    'source': "Généré depuis l'Excel « Traductions Valeurs chaudière.xlsx », l'interface web KEBA Touch V02.00j et l'API de la chaudière. Ce fichier peut être édité à la main.",
    'groups': {v[0]: v[1] for v in GROUPS.values()},
    'measurements': measurements,
    'live': live,
    'parameters': plist,
}


class Dumper(yaml.SafeDumper):
    pass


def str_presenter(dumper, data):
    return dumper.represent_scalar('tag:yaml.org,2002:str', data, style='"' if any(c in data for c in ':#[]{}') or data != data.strip() else None)


Dumper.add_representer(str, str_presenter)
with open(OUT, 'w', encoding='utf-8') as f:
    f.write('# Catalogue des champs de la chaudière Okofen Pellematic / KEBA Touch\n')
    f.write('# - measurements : colonnes des fichiers CSV (touch_YYYYMMDD.csv) → table measurements\n')
    f.write('# - parameters   : réglages lisibles/modifiables via l\'API (index.cgi?action=get|set)\n')
    f.write('#   valeur affichée = valeur brute API / divisor ; level basic = écran réglages, expert = onglet avancé\n\n')
    yaml.dump(doc, f, Dumper=Dumper, allow_unicode=True, sort_keys=False, width=140)
print(len(live),'live',len(measurements), 'measurements', len(plist), 'parameters', sum(p['level'] == 'basic' for p in plist), 'basic')
missing = [h for h in HELP if h not in params]
print('help non trouvés:', missing)
