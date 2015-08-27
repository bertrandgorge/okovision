/*****************************************************
* Projet : Okovision - Supervision chaudiere OeKofen
* Auteur : Stawen Dronek
* Utilisation commerciale interdite sans mon accord
******************************************************/

$(document).ready(function() {
	

	function graphe_error(where,titre){
		var chart = new Highcharts.Chart({
			chart: {
				renderTo: where,
				type: 'line'
			},
			title: {
				text: titre
			},
			subtitle: {
				text: 'Problème lors de la récupération des données !'
			}
		});
	
	}

	function DecSepa(s){
		return s.replace(".",",");
	}
	
	function generer_graphic()	{
	
		var titre_histo = 'Historique temperatures / Consommation Pellet';	
		var div_histo_tempe = 'histo-temperature';	
		
	   $.getJSON("ajax.php?type=rendu&action=getHistoByMonth&month="+ $( "#mois" ).val() + "&year="+ $( "#annee" ).val(), function(json) {
				//Personnalisation des données
				//T°C max
				json[0].color = "red";
				json[0].zIndex= 3;
				//T°C min
				json[1].color = "blue";
				json[1].zIndex= 2;
				//Consommation Pellet Kg			
				json[2].type = "column";
				json[2].zIndex= 1;
				json[2].yAxis= 1;
				
				json[2].dataLabels =  {enabled: true, 
										rotation: -90,
										color: '#FFFFFF',
										align: 'right',
										x: 3,
										y: 10,
										style: {
											fontSize: '10px',
											fontFamily: 'Verdana, sans-serif' ,
											textShadow: '0 0 5px black'
											}
										}	
										
				//DJU
				//json[3].type = "column";
				//json[3].color = "#D1CFCB";
				json[3].color = "gray";
				json[3].zIndex= 4;
				json[3].yAxis= 1;
				
				//nb cycle
				json[4].type = "column";
				json[4].color = "#ECB962";
				json[4].yAxis= 2;
				
				json[4].dataLabels =  {enabled: true, 
										rotation: -90,
										//color: '#FFFFFF',
										align: 'right',
										x: 3,
										verticalAlign: 'bottom',
										style: {
											fontSize: '10px',
											fontFamily: 'Verdana, sans-serif' //,
											//textShadow: '0 0 5px black'
											}
										}	
										
				//json[4].zIndex= 5;
			
				
				//graphe(json,div_histo_tempe,titre_histo);
				
				var chart = new Highcharts.Chart({
													chart: {
														renderTo: div_histo_tempe,
														type: 'spline'//,
														//zoomType: 'x',
														//panning: true,
														//panKey: 'shift'
													},
													title: {
														text: titre_histo
													},
													legend:{
														align: 'right',
														verticalAlign: 'middle',
														layout: 'vertical'
													},
													xAxis: {
														categories: ['01', '02', '03','04','05','06','07','08','09','10',
																	 '11', '12', '13','14','15','16','17','18','19','20',
																	 '21', '22', '23','24','25','26','27','28','29','30',
																	'31'],
															max : 30,		
															title: {
																text: 'jour',
															}	
													},
													yAxis: [{
															title: {
																text: 'T°C'
															},
															min : -5 ,	max : 40 
														},{
															gridLineWidth: 0,
															title: {
																	text: 'Kg et DJU',
																	style: {
																		color: Highcharts.getOptions().colors[4]
																	}
																},
															min : 0 ,	max : 60,
															opposite: true
														},{
															gridLineWidth: 0, 
															title: {
																	text: 'Nb Cycle',
																	style: {
																		color: "#ECB962"
																	}
																},
															min : 0 ,	max : 50,
															opposite: true
														}],
													plotOptions: {
														line: {
															marker: {
																enabled: true
															}
														},
														column: {
															pointPadding: 0,
															borderWidth: 0.2
														}
													},
													series: json
												},function(chart){
										        
										            var bottom = chart.plotHeight - 20;
										            
										            $.each(chart.series[4].data,function(i,data){
										            
										                data.dataLabel.attr({
										                    y: bottom
										                });
										            });
										        
										        });
				
				
			})
			.error(function() { 
				graphe_error(div_histo_tempe,titre_histo);
				$.growlErreur("Probleme lors de la recuperation de la synthese du mois");
			});
		
		/*
		* Gestion des indicateurs du mois 
		*/
		$.getJSON("ajax.php?type=rendu&action=getIndicByMonth&month="+ $( "#mois" ).val() + "&year="+ $( "#annee" ).val(), function(json) {
				$( "#tcmax" ).text(DecSepa(json.tcExtMax + " °C"));
				$( "#tcmin" ).text(DecSepa(json.tcExtMin + " °C"));
				$( "#tcmoy" ).text(DecSepa( Math.round((json.tcExtMin+json.tcExtMax)*100/2)/100 + " °C")  );
				$( "#consoPellet" ).text(DecSepa( ((json.consoPellet===null)?0.0:json.consoPellet) + " Kg"));
				$( "#dju" ).text(DecSepa(json.dju+"" ));
				$( "#cycle" ).text(DecSepa(json.nbCycle+"" ));
				
				
			})
			.error(function() { 
				$.growlErreur("Probleme lors de la recuperation des indicateurs du mois");
			});		
			
			
	}
	
	function generer_synthese_saison(){
		
		$.getJSON("ajax.php?type=rendu&action=getTotalSaison&saison="+ $( "#saison" ).val(), function(json) {
				$( "#tcmaxSaison" ).text(DecSepa(json.tcExtMax + " °C"));
				$( "#tcminSaison" ).text(DecSepa(json.tcExtMin + " °C"));
				$( "#tcmoySaison" ).text(DecSepa( Math.round((json.tcExtMin+json.tcExtMax)*100/2)/100 + " °C")  );
				$( "#consoPelletSaison" ).text(DecSepa( ((json.consoPellet===null)?0.0:json.consoPellet) + " Kg"));
				$( "#djuSaison" ).text(DecSepa(json.dju+"" ));
				$( "#cycleSaison" ).text(DecSepa(json.nbCycle+"" ));
				
			})
			.error(function() { 
				$.growlErreur("Probleme lors de la recuperation des indicateurs de la saison");
			});	
		
		$.getJSON("ajax.php?type=rendu&action=getSyntheseSaison&saison=" + $( "#saison" ).val(), function(saison) {
					//console.log('Synthese success');	
					//console.log(json);
					var json = saison.grapheData;
					
					//Personnalisation des données
					//T°C max
					json[0].color = "red";
					json[0].zIndex= 3;
					//T°C min
					json[1].color = "blue";
					json[1].zIndex= 2;
					//Consommation Pellet Kg			
					json[2].type = "column";
					json[2].zIndex= 1;
					json[2].yAxis= 1;
					
					json[2].dataLabels =  {enabled: true, 
											rotation: -90,
											color: '#FFFFFF',
											align: 'right',
											x: 3,
											y: 10,
											style: {
												fontSize: '10px',
												fontFamily: 'Verdana, sans-serif' ,
												textShadow: '0 0 5px black'
												}
											}	
											
					//DJU
					//json[3].type = "column";
					//json[3].color = "#D1CFCB";
					json[3].color = "gray";
					json[3].zIndex= 4;
					json[3].yAxis= 1;
					
					//nb cycle
					json[4].type = "column";
					json[4].color = "#ECB962";
					json[4].yAxis= 2;
					
					json[4].dataLabels =  {enabled: true, 
											rotation: -90,
											//color: '#FFFFFF',
											align: 'right',
											x: 3,
											verticalAlign: 'bottom',
											style: {
												fontSize: '10px',
												fontFamily: 'Verdana, sans-serif' //,
												//textShadow: '0 0 5px black'
												}
											}
					
					var chart = new Highcharts.Chart({
													chart: {
														renderTo: "saison_graphic",
														type: 'spline'//,
														//zoomType: 'x',
														//panning: true,
														//panKey: 'shift'
													},
													title: {
														text: "Synthèse Saison " + $('#saison option:selected').text()
													},
													legend:{
														align: 'right',
														verticalAlign: 'middle',
														layout: 'vertical'
													},
													xAxis: {
														type: 'datetime',
										                dateTimeLabelFormats: { 
										                    month: '%B'
										                },
										                title: {
															text: 'Mois',
														}	
													},
													yAxis: [{
															title: {
																text: 'T°C'
															},
														//	min : -5 ,	max : 40 
														},{
															gridLineWidth: 0,
															title: {
																	text: 'Kg et DJU',
																	style: {
																		color: Highcharts.getOptions().colors[4]
																	}
																},
															//min : 0 ,	max : 120,
															opposite: true
														},{
															gridLineWidth: 0, 
															title: {
																	text: 'Nb Cycle',
																	style: {
																		color: "#ECB962"
																	}
																},
														//	min : 0 ,	max : 50,
															opposite: true
														}],
													credits: {
														enabled : true,
														text : 'OkoVision'
													},
													plotOptions: {
														line: {
															marker: {
																enabled: true
															}
														},
														column: {
															pointPadding: 0,
															borderWidth: 0.2
														}
													},
													series: json
												},function(chart){
										        
										            var bottom = chart.plotHeight - 20;
										            
										            $.each(chart.series[4].data,function(i,data){
										            
										                data.dataLabel.attr({
										                    y: bottom
										                });
										            });
										        
										        });
				})
				.error(function() { 
					//console.log('error graphe synthèse saison');	
					graphe_error("saison_graphic","Synthèse saison");
					$.growlErreur("Probleme lors de la recuperation de la synthese de la saison");
					
				});
	}
	
	
	$( "#mois" ).change(function() {
		//console.log('date change');
		generer_graphic();
	});
	$( "#annee" ).change(function() {
		//console.log('date change');
		generer_graphic();
	});
	
	$( "#bt_avant" ).click(function() {
	    
		if( $( "#mois"  ).val() == 1){
            $( "#annee" ).val( parseInt($( "#annee" ).val()) - 1 );   
            $( "#mois"  ).val( 12 );
        }else{
            $( "#mois"  ).val( $( "#mois"  ).val()-1 );
        }
        
        generer_graphic();					
	});
	
	$( "#bt_apres" ).click(function() {
		
		if( $( "#mois"  ).val() == 12){
            $( "#annee" ).val( parseInt($( "#annee" ).val()) + 1 );   
            $( "#mois"  ).val( 1 );
        }else{
            $( "#mois"  ).val( parseInt($( "#mois"  ).val()) + 1 );
        }
        
		generer_graphic();
	});
	
	$( "#saison" ).change(function() {
		console.log('date change');
		generer_synthese_saison();
	});
	
	
	generer_graphic();
	
	$.getJSON("ajax.php?type=admin&action=getSaisons", function(json) {
		$.each(json.data, function(key, val) {
    					$('#saison').append('<option value="' + val.id + '">' + val.saison + '</option>');
    				});
    	generer_synthese_saison();			
	});
	
	
	
	
	
	
	

	
	

});
  