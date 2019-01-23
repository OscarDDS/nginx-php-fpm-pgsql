<?php
include('Clases.php');
include('Funciones.php');

function codigoPizza($db, $pizza, $tamano, $sabor) {
	$codigo = trim($pizza->CodigoProducto);
	if ($codigo == '') {
        /*
        $extras = count($pizza->Extras);
        switch (count($pizza->Extras)) {
            case 0:
                $codigo = 'PIZZA';
                break;
            case 1:
                $codigo = 'PIZ1I';
                break;
            default:
                $codigo = 'PIZ2';
                break;
        }
        */
        $codigo = (count($pizza->Extras) > 0) ? 'PIZ1I' : 'PIZ1I';
	}
	$codigoPizza = '';
	$rsPizza = $db->prepare(
		'Select * From Productos '
		. "Where Estado = 'ACT' "
		. 'And TipoGrupoSeleccion = ? '
		. 'And Tamano = ? '
		. 'And CodigoSabor = ? '
		. 'Order By CodigoProducto');
	if ($rsPizza->execute(array($codigo, $tamano, $sabor))) {
		$datosPizza = $rsPizza->fetch(PDO::FETCH_ASSOC);
		if ($datosPizza)
			$codigoPizza = $datosPizza["CodigoProducto"];
	}
    $rsPizza = null;
	return $codigoPizza;
}

if($db) {
    include('Configuracion.php');
    $maxWSServers = $CONF["CantidadServidoresWS"] + 0;
    $wsServerNo = 1;
    if ($maxWSServers > 1) {
        $wsServerNo = rand(1, $maxWSServers);
    }
    if ($wsServerNo < 10) {
        $wsServerNo = '0' . $wsServerNo;
    }
    $WSServer = getenv("WSSERVER$wsServerNo");

    $cupones = array();
    $orden = json_decode($_POST["orden"]);
    foreach ($orden as $linea) {
        if ($linea->Tipo == 'A') {
            $productos[] = array(
                "Cantidad" => $linea->Cantidad,
                "CodigoProducto" => $linea->CodigoProducto,
                "CodigoCupon" => $linea->CodigoCupon,
                "InstruccionesCoccion" => $linea->InstruccionesCoccion
            );
        } else if ($linea->Tipo == 'P') {
            $pizza = array("Cantidad" => $linea->Cantidad, "CodigoCupon" => $linea->CodigoCupon, "InstruccionesCoccion" => $linea->InstruccionesCoccion);
	        if ($linea->EnMitades) {
                $codigoPizza1 = codigoPizza($db, $linea->Productos[1], $linea->CodigoTamano, $linea->CodigoSabor);
                $codigoPizza2 = codigoPizza($db, $linea->Productos[2], $linea->CodigoTamano, $linea->CodigoSabor);

		        if ($codigoPizza1 == $codigoPizza2)
			        $pizza["CodigoProducto"] = $codigoPizza1;
		        else
			        $pizza["CodigoProducto"] = "$codigoPizza1/$codigoPizza2";
            } else {
		        $codigoPizza1 = '';
		        $codigoPizza2 = '';
                $pizza["CodigoProducto"] = codigoPizza($db, $linea->Productos[0], $linea->CodigoTamano, $linea->CodigoSabor);
            }
	        $extras = array();
	        if ($linea->Productos) {
		        for ($i = 0; $i < 3; $i++) {
			        $pizzaIngredientes = -1;
			        if (count($linea->Productos[$i]->Toppings) == 0) {
				        $pizzaIngredientes = count($linea->Productos[$i]->Extras) - 1;
				        if ($pizzaIngredientes > 0)
					        $pizzaIngredientes = 1;
			        }

                    if (count($linea->Productos[$i]->Extras) > 0) {
                        foreach ($linea->Productos[$i]->Extras as $e) {
	                        $agregarTopping = true;
	                        if ($pizzaIngredientes >= 0 && intval($e->Cantidad) > 0) {
                                $agregarTopping = !in_array($e->Codigo, $CONF["ToppingsPizzaIngredientes"][$pizzaIngredientes]);
                            }
	                        if ($agregarTopping) {
                                $extras[] = array("CantidadAdicional" => $e->Cantidad, "CodigoAdicional" => $e->Codigo, "Mitad" => $e->Lado);
                            }
                        }
                        if ($pizzaIngredientes >= 0) {
                            foreach ($CONF["ToppingsPizzaIngredientes"][$pizzaIngredientes] as $t) {
                                $eliminarTopping = true;
                                foreach ($linea->Productos[$i]->Extras as $e) {
                                    if ($e->Codigo == $t) {
                                        $eliminarTopping = false;
                                    }
                                }
                                if ($eliminarTopping) {
                                    $extras[] = array("CantidadAdicional" => 0, "CodigoAdicional" => $t, "Mitad" => $i);
                                }
                            }
                        }
                    }
                }
	        }
	        $pizza["AdicionalesProducto"] = $extras;
            $productos[] = $pizza;
        } else if (in_array($linea->Tipo, array("C", "L", "R"))) {
            $cupones[] = $linea->CodigoProducto;
        }
    }
	if ($_POST["Funcion"] == 'Calcular') {
        try {
            $pulse = new SoapClientPulse($WSServer . $CONF["PreciosAPI"], array('trace' => 1));
            $r = $pulse->obtenerPrecioOrden(
                array(
                    "p" => array(
                        "Origen" => 'C3',
                        "Usuario" => $CONF["UsuarioWS"],
                        "Clave" => $CONF["ClaveWS"],
                        "ElementosOrden" => array(
                            "CodigosCupon" => $cupones,
                            "CodigosProducto" => $productos
                        )
                    )
                )
            );
            if ($r->obtenerPrecioOrdenResult->Estado > 0) {
                $mensajeError = str_replace("\n", '<br>', $r->obtenerPrecioOrdenResult->MensajeError);
                error($r->obtenerPrecioOrdenResult->Estado . ': ' . $mensajeError . "<br>$wsServerNo: $WSServer");
            }
            else
                echo json_encode(array("success" => true, "SimboloMoneda" => $CONF["SimboloMoneda"], "orden" => $r->obtenerPrecioOrdenResult));
        } catch (Exception $e) {
            error($e->getMessage() . "<br>$wsServerNo: $WSServer");
        }
	}
	else if ($_POST["Funcion"] == 'ColocarOrden') {
        $datosOrden = json_decode($_POST["datosOrden"]);
        $nombreCliente = utf8_decode($datosOrden->DatosCliente->nombreCliente);
        $usuario = $datosOrden->Usuario;
        $rs = $db->prepare(
            'Select Count(*) As Conteo From TelefonoNombres '
            . 'Where NumeroTelefono = ? '
            . 'And Nombre = ?');
        if ($rs->execute(array($datosOrden->DatosCliente->telefono, $nombreCliente))) {
            $conteo = $rs->fetch(PDO::FETCH_ASSOC);
            if ($conteo["Conteo"] == 0) {
                $rsInsert = $db->prepare('Insert Into TelefonoNombres (NumeroTelefono, Nombre, TelefonosReferencia, CorreoElectronico) Values (?, ?, ?, ?)');
                $rsInsert->execute(array($datosOrden->DatosCliente->telefono, $nombreCliente, $datosOrden->Direccion->TelefonoReferencia, ''));
                $rsInsert = null;
            } else {
                $rsUpdate = $db->prepare(
                    'Update TelefonoNombres Set TelefonosReferencia = ? '
                    . 'Where NumeroTelefono = ? '
                    . 'And Nombre = ?'
                );
                $rsUpdate->execute(array($datosOrden->Direccion->TelefonoReferencia, $datosOrden->DatosCliente->telefono, $nombreCliente));
                $rsUpdate = null;
            }
        }
        $rs = null;

        $streetName = $datosOrden->Direccion->StreetName;
        $rsTienda = $db->prepare(
            'Select Case When L.Descripcion Is Null Then 0 Else 1 End As BloqueoStreetName From Tiendas T '
            . 'Left Join Listas L '
            . 'On L.Codigo = T.CodigoTienda '
            . "And L.CodigoLista = 'TIENDASCORRECCIONSTREETNAME' "
            . 'Where T.CodigoTienda = ?'
        );
        if ($rsTienda->execute(array($datosOrden->Tienda->CodigoTienda))) {
            $tienda = $rsTienda->fetch(PDO::FETCH_ASSOC);
            if ($tienda) {
                if ($tienda["BloqueoStreetName"] == 1) {
                    $rsDireccion = $db->prepare(
                        'Select StreetName, Coalesce(Corregida, 0) As Corregida '
                        . 'From TelefonoDirecciones '
                        . 'Where NumeroTelefono = ? '
                        . 'And Correlativo = ?'
                    );
                    if ($rsDireccion->execute(array($datosOrden->DatosCliente->telefono, $datosOrden->Direccion->Correlativo))) {
                        $direccion = $rsDireccion->fetch(PDO::FETCH_ASSOC);
                        if ($direccion) {
                            if ($direccion["Corregida"] == 0  && $direccion["StreetName"] != '00') {
                                $streetName = "00";
                                $rsUpdateDireccion = $db->prepare('Exec spInsertarDireccion ?, ?, ?, ?, ?, ?, ?');
                                $rsUpdateDireccion->execute(array(
                                    $datosOrden->DatosCliente->telefono,
                                    $datosOrden->Direccion->Correlativo,
                                    utf8_decode($datosOrden->Direccion->Direccion),
                                    $datosOrden->Tienda->CodigoTienda,
                                    $streetName,
                                    $datosOrden->Direccion->TelefonoReferencia,
                                    utf8_decode($datosOrden->Direccion->InstruccionesDelivery)));
                                $rsUpdateDireccion = null;
                            }
                        }
                    }
                    $rsDireccion = null;
                }
            }
        }
        $rsTienda = null;
        $datosOrden->Direccion->StreetName = trim($streetName);

		$pulse = new SoapClientPulse($WSServer . $CONF["OrdenesAPI"], array('trace' => 1));
        $formaPago = array();
        if (floatval($datosOrden->Efectivo->valor) > 0)
            $formaPago[] = array(
                "FormaPago" => 'EFE01',
                "Valor" => floatval($datosOrden->Efectivo->valor),
                "DatosTarjeta" => array("CodigoSeguridad" => "", "FechaVencimiento" => "", "NombreTarjeta" => "", "NumeroTarjeta" => "")
            );
        if (floatval($datosOrden->Tarjeta1->valor) > 0)
            $formaPago[] = array(
                "FormaPago" => 'TC03',
                "Valor" => floatval($datosOrden->Tarjeta1->valor),
                "DatosTarjeta" => array(
                    "CodigoSeguridad" => $datosOrden->Tarjeta1->cvv,
                    "FechaVencimiento" => $datosOrden->Tarjeta1->vencimientoTarjeta,
                    "NombreTarjeta" => $datosOrden->Tarjeta1->nombreTarjeta,
                    "NumeroTarjeta" => $datosOrden->Tarjeta1->numeroTarjeta
                )
            );
        if (floatval($datosOrden->Tarjeta2->valor) > 0)
            $formaPago[] = array(
                "FormaPago" => 'TC03',
                "Valor" => floatval($datosOrden->Tarjeta2->valor),
                "DatosTarjeta" => array(
                    "CodigoSeguridad" => $datosOrden->Tarjeta2->cvv,
                    "FechaVencimiento" => $datosOrden->Tarjeta2->vencimientoTarjeta,
                    "NombreTarjeta" => $datosOrden->Tarjeta2->nombreTarjeta,
                    "NumeroTarjeta" => $datosOrden->Tarjeta2->numeroTarjeta
                )
            );
        if (floatval($datosOrden->Tarjeta3->valor) > 0)
            $formaPago[] = array(
                "FormaPago" => 'TC03',
                "Valor" => floatval($datosOrden->Tarjeta3->valor),
                "DatosTarjeta" => array(
                    "CodigoSeguridad" => $datosOrden->Tarjeta3->cvv,
                    "FechaVencimiento" => $datosOrden->Tarjeta3->vencimientoTarjeta,
                    "NombreTarjeta" => $datosOrden->Tarjeta3->nombreTarjeta,
                    "NumeroTarjeta" => $datosOrden->Tarjeta3->numeroTarjeta
                )
            );
        if (floatval($datosOrden->PagoMovil->valor) > 0)
            $formaPago[] = array(
                "FormaPago" => 'TC00',
                "Valor" => floatval($datosOrden->PagoMovil->valor),
                "DatosTarjeta" => array("CodigoSeguridad" => "", "FechaVencimiento" => "", "NombreTarjeta" => "", "NumeroTarjeta" => "")
            );
        $detalleOrden = array(
            "p" => array(
                "Clave" => $datosOrden->Token,
                "ClubBI" => $datosOrden->ClubBI->numeroClubBI,
                "ClubBICupones" => $datosOrden->CuponesClubBI,
                "CodigoDepartamento" => $datosOrden->Tienda->CodigoDepartamento,
                "CodigoMunicipio" => $datosOrden->Tienda->CodigoMunicipio,
                "CodigoPais" => $datosOrden->Tienda->CodigoPais,
                "CodigoTienda" => $datosOrden->Tienda->CodigoTienda,
                "Comentarios" => wordwrap(substr($datosOrden->Direccion->InstruccionesDelivery, 0, 153), 40, "\n\r"),
                "DetallePago" => $formaPago,
                "DireccionDespacho" => $datosOrden->Direccion->Direccion,
                "ElementosOrden" => array(
                    "CodigosCupon" => $cupones,
                    "CodigosProducto" => $productos
                ),
                "ExtensionTelefono" => " ",
                "InicioOrden" => $datosOrden->HoraInicio,
                "NombreCliente" =>  $datosOrden->DatosCliente->nombreCliente,
                "NumeroTelefono" => $datosOrden->DatosCliente->telefono,
                "Origen" => ($datosOrden->Origen ? $datosOrden->Origen : 'C3'),
                "StreetName" => $datosOrden->Direccion->StreetName,
                "StreetNumber" => "0",
                "Suite" => "",
                "TelefonosReferencia" => $datosOrden->Direccion->TelefonoReferencia,
                "TotalAPagar" => floatval($datosOrden->TotalOrden),
                "Usuario" => $usuario,
                "ZipCode" => $datosOrden->Tienda->CodigoTienda
            )
        );
        if ($datosOrden->Direccion->programarOrden != '') {
            $detalleOrden["p"]["OrdenProgramada"] = $datosOrden->FechaProgramada;
        }
        try {
            ini_set('default_socket_timeout', 150);
            $r = $pulse->colocarOrdenC3($detalleOrden);
            echo json_encode(array("success" => true, "SimboloMoneda" => $CONF["SimboloMoneda"], "orden" => $r->colocarOrdenC3Result));
        } catch (Exception $e) {
            error($e->getMessage());
        }
	}
}
?>