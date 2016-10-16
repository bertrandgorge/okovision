/*****************************************************
 * Projet : Okovision - Supervision chaudiere OeKofen
 * Auteur : Stawen Dronek
 * Utilisation commerciale interdite sans mon accord
 ******************************************************/
/* global lang, $ */
$(document).ready(function() {

	$("#bt_testConnection").click(function() {
		//$('#formConnect').submit(function() {
		//if ( $('#db_adress').val() !== "" && $('#db_user').val() !== "" && $('#db_password').val() !== "" ){

		//$(this).text("....");

		var tab = {
			db_adress: $('#db_adress').val(),
			db_user: $('#db_user').val(),
			db_password: $('#db_password').val(),
			db_schema: $('#db_schema').val()
		};

		$.ajax({
			url: 'setup.php?type=connect',
			type: 'POST',
			data: $.param(tab),
			async: false,
			success: function(a) {
				//console.log(a.response);
				if (a.response) {

					$.growlValidate(lang.valid.communication);

				}
				else {
					$.growlErreur(lang.error.bddFail);
				}

			}
		});

		//	}		

	});

	$("#oko_typeconnect").change(function() {

		if ($(this).val() === 1) {
			$("#form-ip").show();
		}
		else {
			$("#form-ip").hide();
		}
	});

	$("#bt_install").click(function() {
		//$("form-horizontal").validate();
		//.element("#db_adress");
		//  $("#db_adress").validate();
		/*
		$(".form-horizontal").validate().element("#db_user");
		$(".form-horizontal").validate().element("#db_password");
		$(".form-horizontal").validate().element("#oko_ip");
		$(".form-horizontal").validate().element("#param_tcref");
		$(".form-horizontal").validate().element("#param_poids_pellet");
		$(".form-horizontal").validate().element("#surface_maison");
		*/
		var tab = {
			db_adress: $('#db_adress').val(),
			db_user: $('#db_user').val(),
			db_password: $('#db_password').val(),
			db_schema: $('#db_schema').val(),
			createDb: $('#createDb').val(),
			oko_ip: $('#oko_ip').val(),
			param_tcref: $('#param_tcref').val(),
			param_poids_pellet: $('#param_poids_pellet').val(),
			surface_maison: $('#surface_maison').val(),
			oko_typeconnect: $('#oko_typeconnect').val(),
			send_to_web: $('#send_to_web').val()
		};

		$.ajax({
			url: 'setup.php?type=install',
			type: 'POST',
			data: $.param(tab),
			async: false,
			success: function(a) {
				//console.log(a);
				window.location.replace("index.php");
			}
		});


	});




});