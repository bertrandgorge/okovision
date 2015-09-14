/*****************************************************
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Auteur : Stawen Dronek
 * Utilisation commerciale interdite sans mon accord
 ******************************************************/
/* global lang, Highcharts, sessionToken */
$(document).ready(function() {

	


	$.growlValidate = function(text) {
		$.notify({
			icon: 'glyphicon glyphicon-save',
			message: text
		}, {
			z_index: 9999,
			type: 'success'
		});
	}

	$.growlErreur = function(text) {
		$.notify({
			icon: 'glyphicon glyphicon-exclamation-sign',
			message: text
		}, {
			z_index: 9999,
			type: 'danger'
		});
	}

	$.growlWarning = function(text) {
		$.notify({
			icon: 'glyphicon glyphicon-exclamation-sign',
			message: text
		}, {
			z_index: 9999,
			type: 'warning'
		});
	}
	
	$.api = function(mode, cmd, tab, typeSync) {

		var tmp = cmd.split('.');
		var urlFinal = 'type=' + tmp[0] + '&action=' + tmp[1];
		//gestion si pas d'arguments supplementaires
		tab = typeof tab !== 'undefined' ? tab : {};
		typeSync = typeof typeSync !== 'undefined' ? typeSync : true;

		var urlFinal = 'ajax.php?sid=' + sessionToken + '&' + urlFinal;

		var jxhr =  $.ajax({
			url: urlFinal,
			type: mode,
			data: $.param(tab),
			async: typeSync
		}).error(function() {
			var msg = lang.error.communication + ' : ' + cmd
				//console.log(msg);
			$.growlErreur(msg);
		});
		//console.log(jxhr);
		jxhr.done(function(json){
			//console.log(json);
			if (!json.response){
				if(json.sessionToken === 'invalid') {
					//$.growlErreur('Session expirée');
					//setTimeout(function(){},2500);
					window.location.replace("index.php");
				}
			}
		});
		
		return jxhr;
	}


	function activeTab() {
		var url = document.location.toString();
		if (url.match('#')) {
			$('.nav-tabs a[href=#' + url.split('#')[1] + ']').tab('show');
		}
	}

	// Change hash for page-reload
	$('.nav-tabs a').on('shown.bs.tab', function(e) {
		//console.log("active tab:" + e.target.hash);
		window.location.hash = e.target.hash;
	});



	$(window).on('hashchange', function() {
		activeTab();
	});

	activeTab();

	$.matriceComplet = function() {

		var r = false;
		/*
    	$.ajax({
			url: 'ajax.php?type=admin&action=statusMatrice',
			type: 'GET',
			async: false,
			success: function(json) {
				//console.log(json);
				r =  json.response;
			},
            error: function () {
                $.growlErreur(lang.error.save);
              }
		});
		*/
		$.api('GET', 'admin.statusMatrice', {}, false).done(function(json) {
			r = json.response;
		}).error(function() {
			$.growlErreur(lang.error.save);
		});
		return r;


	}

	$.validateDate = function(dtValue) {
		var dtRegex = new RegExp(/\b\d{1,2}[\/]\d{1,2}[\/]\d{4}\b/);
		return dtRegex.test(dtValue);
	}

	$.errorDate = function() {
		$.growlWarning(lang.error.date);
		return;
	}

	$.DecSepa = function(s) {
		return s.replace(".", ",");
	}

	Highcharts.setOptions({
		lang: {
			thousandsSep: lang.graphic.thousandsSep,
			decimalPoint: lang.graphic.decimalPoint,
			months: lang.graphic.months,
			shortMonths: lang.graphic.shortMonths,
			weekdays: lang.graphic.weekdays
		},
		credits: {
			enabled: true,
			text: 'OkoVision',
			href: 'http://okovision.dronek.com'
		}
	});




});