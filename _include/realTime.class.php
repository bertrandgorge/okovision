<?php

/*****************************************************
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
* Utilisation commerciale interdite sans mon accord
******************************************************/

class realTime extends connectDb{
	
	public function __construct() {
		parent::__construct();
	}
	
	public function __destruct() {
		parent::__destruct();
	}
	
	private function sendResponse($t){
        header("Content-type: text/json; charset=utf-8");
		echo $t;
    }
	
	
	public function getOkoValue($data = array()){
		
		$o = new okofen();
        $o->requestBoilerInfo( $data );
        
		$r = array();
		
		$dataBoiler = json_decode($o->getResponseBoiler());
		
		if($o->isConnected()){
			foreach($dataBoiler as $capt){
				
				if($capt->formatTexts != ''){
					
					$shortTxt 	= 'ERROR';
					$value		= 'null';
					$s= array();
					
					
					if($capt->value != '???'){
						$s = explode ("|",$capt->formatTexts);
						$shortTxt 	= $capt->shortText;
						$value		= $s[$capt->value];
					}
					
					$r[$capt->name] = (object) array(
											"value" => $value,
											"unitText" => ''
											);
				}else{
					$r[$capt->name] = (object) array(
											"value" => ($capt->divisor != '' && $capt->divisor != '???' )?($capt->value / $capt->divisor):($capt->value),
											"unitText" => ($capt->unitText=='???')?'':(($capt->unitText=='K')?'°C':$capt->unitText),
											"divisor" => $capt->divisor,
											"lowerLimit" => $capt->lowerLimit,
											"upperLimit" => $capt->upperLimit
											);
				}
			}
		}
		
		return $r;
		
	}
	
	
	
	

    public function getIndic(){
    	$json['response'] = false;
    	
    	$indic = array( "CAPPL:FA[0].L_mittlere_laufzeit" 			, // temps moyen du bruleur
						"CAPPL:FA[0].L_brennerstarts" 				, // nb demarrage bruleur
						"CAPPL:FA[0].L_brennerlaufzeit_anzeige" 	, //fonct brûleur
						"CAPPL:FA[0].L_anzahl_zuendung" 			, //nb allumage
						"CAPPL:LOCAL.touch[0].version" 				, // version
						//chauffage -> T°C ambiamte
						"CAPPL:LOCAL.hk[0].raumtemp_heizen"			,//T°C ambiant confort
						"CAPPL:LOCAL.hk[0].raumtemp_absenken"		,//T°C ambiant reduit
						"CAPPL:LOCAL.hk[0].heizkurve_steigung"		,//pente
						"CAPPL:LOCAL.hk[0].heizkurve_fusspunkt"		,//pied de courbe
						"CAPPL:LOCAL.hk[0].heizgrenze_heizen"		,//T°c ext de coupure (Confort)
						"CAPPL:LOCAL.hk[0].heizgrenze_absenken"		,//T°c ext de coupure (Reduit)
						//Chauffage -> Gestion Eau dans Radiateur
						"CAPPL:LOCAL.hk[0].vorlauftemp_max"			,//T°C depart Max
						"CAPPL:LOCAL.hk[0].vorlauftemp_min"			,//T°C depart Min
						"CAPPL:LOCAL.hk[0].ueberhoehung"			,//Augmentation
						"CAPPL:LOCAL.hk[0].mischer_max_auf_zeit"	,//V3V Ouverture
						"CAPPL:LOCAL.hk[0].mischer_max_aus_zeit"	,//V3V Pause
						"CAPPL:LOCAL.hk[0].mischer_max_zu_zeit"		,//V3V Fermeture
						"CAPPL:LOCAL.hk[0].mischer_regelbereich_quelle",//Plage réglage TC
						"CAPPL:LOCAL.hk[0].mischer_regelbereich_vorlauf",//Plage réglage TD
						"CAPPL:LOCAL.hk[0].quellentempverlauf_anstiegstemp",//Hausse ETC
						"CAPPL:LOCAL.hk[0].quellentempverlauf_regelbereich",//Correction réglage ETC (Evolution Température Chaudière)
						//	Parametrage bruleur :
						"CAPPL:FA[0].pe_kesseltemperatur_soll"		,//T°C Consigne
						"CAPPL:FA[0].pe_abschalttemperatur"			,//T°C Coupure
						"CAPPL:FA[0].pe_einschalthysterese_smart" 	,// Hysteresis marche
						"CAPPL:FA[0].pe_kesselleistung" 			 //Puissance chaudiere
                   	) ;
    	
    	
    	$r = $this->getOkoValue($indic);
	
		
		if(!empty($r)){	
			$tmp = array();
			
			foreach($indic as $key){
				$tmp[$key] = trim($r[$key]->value.' '.$r[$key]->unitText);
			}
			$json['data'] = $tmp;
			$json['response'] = true;	
		}
		
		//var_dump($resp);		                
		$this->sendResponse(json_encode($json));
    }
    
    
    public function setOkoLogin($user,$pass){
		
		$pass = base64_encode( $this->realEscapeString($pass) );
		$userId = session::getInstance()->getVar("userId");
		$r['response'] = false;
		
		$q = "update oko_user set login_boiler='$user', pass_boiler='$pass' where id=$userId";
		$this->log->debug("Class ".__CLASS__." | ".__FUNCTION__." | ".$q);
		
		if($this->query($q)){
			$o = new okofen();
			$o->boilerDisconnect();
			$r['response'] = true;
		}
		
		$this->sendResponse(json_encode($r));
	}
	
	public function getdata($id){
		
		$q = "select capteur.boiler as boiler, capteur.name as name, capteur.id as id, asso.correction_effect as coeff from oko_asso_capteur_graphe as asso ".
	            "LEFT JOIN oko_capteur as capteur ON capteur.id = asso.oko_capteur_id  ".
	            "WHERE asso.oko_graphe_id=".$id." AND capteur.boiler <> '' ORDER BY asso.position";
	            
	    $this->log->debug("Class ".__CLASS__." | ".__FUNCTION__." | ".$q);
	   
	    $result = $this->query($q);
		
		$sensor = array();
		
		while($c = $result->fetch_object()){
			$sensor[$c->boiler] = array(
									'name'  => $c->name,
									'coeff'	=> $c->coeff
									);
		}
		
		
		
		
		$r = $this->getOkoValue($sensor);
		$resultat = '';
		
		foreach($sensor as $boiler => $param){
			$resultat .= '{ "name": "'.$param['name'].'",';
			$data= '['.substr($r['CAPPL:LOCAL.L_fernwartung_datum_zeit_sek']->value,0,-7).'000,'.$r[$boiler]->value * $param['coeff'].']';
			$resultat .= '"data": '.$data.'},';
		}
		
		//on retire la derniere virgule qui ne sert à rien
		$resultat = substr($resultat,0,strlen($resultat)-1);
		$this->sendResponse('['.$resultat.']');		                
	}
	
	
	
	public function getSensorInfo($sensor){
		
		$sensor = session::getInstance()->getSensorName($sensor);
		
		$this->log->debug("Class ".__CLASS__." | ".__FUNCTION__." | ".$sensor);	
		
		$r = $this->getOkoValue(
						array($sensor)
					);
		
		$this->sendResponse(json_encode($r[$sensor]));
	}
	
	
    
    public function saveBoilerConfig($config, $description, $dateChoisen = ''){
    	
    	if($dateChoisen <> ''){
    		$date = DateTime::createFromFormat('d/m/Y H:i:s', $dateChoisen);
    	}else{
    		$date = new DateTime();	
    	}
    	
    	$utc = ($date->getTimestamp() + $date->getOffset());
    	
    	$config = json_encode($config);
    	$this->log->debug("Class ".__CLASS__." | ".__FUNCTION__." | ".$config);
    	
    	$config =$this->realEscapeString($config);
    	$this->log->debug("Class ".__CLASS__." | ".__FUNCTION__." | ".$config);	
    		
    	
    	$description = $this->realEscapeString($description);
    	
    	$q="INSERT INTO oko_boiler set timestamp=$utc, description='$description', config='$config' ;";
    	
    	$this->log->debug("Class ".__CLASS__." | ".__FUNCTION__." | ".$q);	
    	
    	$r['response'] = $this->query($q);
		
		$this->sendResponse(json_encode($r));
    	
    }
    
    public function deleteConfigBoiler($timestamp){
    	$q = "DELETE FROM oko_boiler where timestamp=$timestamp;";
    	
    	$this->log->debug("Class ".__CLASS__." | ".__FUNCTION__." | ".$q);	
    	
    	$r['response'] = $this->query($q);
		
		$this->sendResponse(json_encode($r));
    	
    }
    
    public function getListConfigBoiler(){
    	$q = "SELECT timestamp as timestamp, DATE_FORMAT(FROM_UNIXTIME(timestamp), '%d/%m/%Y %H:%i:%s') as date, description, config FROM oko_boiler order by timestamp desc; ";
    	
    	$this->log->debug("Class ".__CLASS__." | ".__FUNCTION__." | ".$q);
	    
	    $result = $this->query($q);
	    
	    if($result){
	    	$r['response'] = true;
	    	$tmp = array();
	    	while($res = $result->fetch_object()){
				array_push($tmp,$res);
			}
	    	$r['data']=$tmp;
	    }else{
	    	$r['response'] = false;
	    }
	    
	    $this->sendResponse(json_encode($r));
    	
    }
    
    public function getConfigBoiler($timestamp){
    	
    	$q = "SELECT config FROM oko_boiler where timestamp=$timestamp; ";
    	
    	$this->log->debug("Class ".__CLASS__." | ".__FUNCTION__." | ".$q);
	    
	    $result = $this->query($q);
	    $r = null;
	    
	    if($result){
	    	$r .= '"response":true';
	    	$res = $result->fetch_object();
	    	$r .= ',"data":'.$res->config;
	    	$this->log->debug("Class ".__CLASS__." | ".__FUNCTION__." | ".$res->config);
	    }else{
	    	$r .= '"response":false';
	    }
	    
	    $this->sendResponse('{'.$r.'}');
    	
    }
    
    public function applyBoilerConfig($config){
    
    	//$config = json_decode($config);
    	$sensors 	= array();
    	$param		= array();
    	
    	foreach($config as $key => $value ){
    		$t = explode(" ",$value);
    		$name = session::getInstance()->getSensorName($key);
    		
    		$param[$name] = $t[0];
    		$sensors[] = $name;
    	}
    	//var_dump($sensors);exit;
    	//preparation du message a transmettre au boiler
    	//recuperation des informations de chaque capteur pour le mettre au bon format
    	$sensorsInfo = $this->getOkoValue($sensors);
    	
    	//var_dump($sensorsInfo); exit;
    	
    	foreach($param as $name => $value){
    		$c = $sensorsInfo[$name];
    		$param[$name] = $value * $c->divisor;
    		
    	}
    	
    	$this->log->debug("Class ".__CLASS__." | ".__FUNCTION__." | ".json_encode($param));
    	
    	$o = new okofen();
    	$o->applyConfiguration($param);
    	
    	$this->sendResponse($o->getResponseBoiler());
    	
    	
    }
    
    public function getBoilerMode($way = 1){
    	$json['response'] = false;
    	
    	$sensor = array( "CAPPL:LOCAL.hk[0].betriebsart[$way]", // temps moyen du bruleur
				) ;
    	
    	
    	$r = $this->getOkoValue($sensor);
	
		
		if(!empty($r)){	
			$tmp = array();
			
			foreach($sensor as $key){
				$tmp[$key] = trim($r[$key]->value.' '.$r[$key]->unitText);
			}
			$json['data'] = $tmp;
			$json['response'] = true;	
		}
		
		$this->sendResponse(json_encode($json));
    }
    
    public function setBoilerMode($mode = 0,$way = 1){
    	
    	$o = new okofen();
    	$o->applyConfiguration(
    			array(
    				"CAPPL:LOCAL.hk[0].betriebsart[$way]" => $mode	
    			)
    		);
    	
    	$this->sendResponse($o->getResponseBoiler());
    	
    }
    
    
    
    
    
}

?>