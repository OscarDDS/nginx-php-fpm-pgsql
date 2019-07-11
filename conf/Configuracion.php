<?php
$CONF = array(
	"TipoConexionBD" => getenv('CONNECTIONTYPE'),
	"ServidorBD" => getenv('HOST'),
	"UsuarioBD" => getenv('DBUSER'),
	"ClaveBD" => getenv('DBPASSWORD'),
	"BaseDatos" => getenv('DBNAME'),
	"PreciosAPI" => getenv('PRICEAPI'),
	"OrdenesAPI" => getenv('ORDERSAPI'),
	"InfraestructuraAPI" => getenv('INFRAESTRUCTUREAPI'),
    "NITAPI" => getenv('NITAPI'),
    "TokenFEL" => getenv('TOKENFEL'),
    "SimboloMoneda" => getenv('CURRENCY'),
	"PizzaIngredientes" => array(getenv('BASEPIZZA'), getenv('BASEPIZZA')),
    "ToppingsPizzaIngredientes" => array(array("H"), array("H")),
	"UsuarioWS" => getenv('WSUSER'),
	"ClaveWS" => getenv('WSPASSWORD'),
	"CantidadServidoresWS" => getenv('WSSERVERSQTY')
);
?>