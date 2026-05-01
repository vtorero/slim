<?php
header('Access-Control-Allow-Origin:*');
header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
$method = $_SERVER['REQUEST_METHOD'];
if($method == "OPTIONS") {
    die();
}


require (__DIR__ .'/vendor/autoload.php');
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

$app = AppFactory::create();
//$db = new mysqli("lh-cjm.com","aprendea_erp","erp2023*","aprendea_erp")
$dsn = "mysql:host=lh-cjm.com;dbname=aprendea_erp;port=3306;charset=utf8";

try {
    $pdo = new PDO($dsn, "aprendea_erp", "erp2023*", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
    ]);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

$pdo->exec("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");

// 🔥 SOLUCIÓN
$app->setBasePath('/slim/reportes.php');

$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->post('/reporte', function (Request $request, Response $response) use ($pdo) {

    $data = json_decode($request->getBody()->getContents(), true);

    $arraymeses = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $arraynros  = ['01','02','03','04','05','06','07','08','09','10','11','12'];

    $mes1 = substr($data['ini'], 3,3);
    $mes2 = substr($data['fin'], 3,3);
    $dia1 = substr($data['ini'], 0,2);
    $dia2 = substr($data['fin'], 0,2);
    $ano1 = substr($data['ini'], 7,4);
    $ano2 = substr($data['fin'], 7,4);

    $fmes1 = str_replace($arraymeses,$arraynros,$mes1);
    $fmes2 = str_replace($arraymeses,$arraynros,$mes2);

    $ini = "$ano1-$fmes1-$dia1";
    $fin = "$ano2-$fmes2-$dia2";

    $params = [
        ':ini1' => "$ini 00:00:00",
        ':fin1' => "$fin 23:59:59"
    ];

    // 🔹 Función helper
    $run = function($sql, $params) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    };

    // 🔹 Consultas
    $infoboleta = $run("SELECT SUM(valor_total) total FROM ventas WHERE estado=1 AND fecha_registro BETWEEN :ini1 AND :fin1", $params);

    $infopendiente = $run("SELECT SUM(monto_pendiente) pendiente FROM ventas WHERE estado=1 AND fecha_registro BETWEEN :ini1 AND :fin1", $params);

    $infogasto = $run("SELECT TRUNCATE(SUM(cantidad*precio),2) gasto
        FROM compras c JOIN compra_detalle d ON c.id=d.id_compra
        WHERE c.fecha_registro BETWEEN :ini1 AND :fin1", $params);

    $infoproducto = $run("SELECT p.nombre, COUNT(id_producto) total
        FROM ventas v
        JOIN venta_detalle vd ON v.id=vd.id_venta
        JOIN productos p ON vd.id_producto=p.id
        WHERE v.estado=1 AND vd.fecha_registro BETWEEN :ini1 AND :fin1
        GROUP BY p.nombre ORDER BY total DESC LIMIT 5", $params);

    $infoclientes = $run("SELECT c.nombre,
        SUM(v.valor_total) total,
        SUM(v.monto_pendiente) pendiente,
        COUNT(id_cliente) pedidos
        FROM ventas v
        JOIN clientes c ON v.id_cliente=c.id
        WHERE v.estado=1 AND v.fecha_registro BETWEEN :ini1 AND :fin1
        GROUP BY c.nombre ORDER BY total DESC LIMIT 5", $params);

    $infoclientestabla = $run("SELECT c.id,c.nombre,
        SUM(v.valor_total) total,
        SUM(v.monto_pendiente) pendiente,
        COUNT(id_cliente) pedidos
        FROM ventas v
        JOIN clientes c ON v.id_cliente=c.id
        WHERE v.estado=1 AND v.fecha_registro BETWEEN :ini1 AND :fin1
        GROUP BY c.id,c.nombre ORDER BY total DESC", $params);

    $infosucursales = $run("SELECT s.nombre, COUNT(id_sucursal) total
        FROM ventas v
        JOIN sucursales s ON v.id_sucursal=s.id
        WHERE v.estado=1 AND v.fecha_registro BETWEEN :ini1 AND :fin1
        GROUP BY s.nombre ORDER BY total DESC LIMIT 5", $params);

    $infocompras = $run("SELECT SUM(cantidad*precio) gasto,
        DATE_FORMAT(c.fecha_registro,'%Y-%m-%d') fecha
        FROM compras c
        JOIN compra_detalle d ON c.id=d.id_compra
        WHERE c.fecha_registro BETWEEN :ini1 AND :fin1
        GROUP BY fecha ORDER BY fecha", $params);

    $infoventas = $run("SELECT SUM(cantidad*precio) venta,
        DATE_FORMAT(v.fecha_registro,'%Y-%m-%d') fecha
        FROM ventas v
        JOIN venta_detalle d ON v.id=d.id_venta
        WHERE v.estado=1 AND v.fecha_registro BETWEEN :ini1 AND :fin1
        GROUP BY fecha ORDER BY fecha", $params);

    // 🔹 SQL grandes (solo cambiamos fechas dinámicas)
    $sql_reporte = str_replace(
        ["{$ini}","{$fin}"],
        [$ini,$fin],
        "SELECT v.id,cl.num_documento,v.fecha,vp.fecha_registro,'Venta' AS tipo_movimiento,u.nombre usuario,cl.nombre as cliente,cl.direccion,cl.telefono, s.nombre sucursal, tp.nombre tipopago,c.nombre, valor_total,vp.monto, vp.monto_pendiente,v.observacion
FROM aprendea_erp.venta_pagos vp,ventas v,usuarios u,sucursales s,tipoPago tp,cajas c ,clientes cl where vp.tipoPago=tp.id and vp.cuentaPago=c.id and v.id_sucursal=s.id
and v.id=vp.id_venta and v.id_cliente=cl.id and vp.usuario=u.id and vp.fecha_registro between '{$ini} 00:00:01' and '{$fin} 23:59:59' and vp.monto>=0 and v.estado='1'
union all
SELECT v.id,cl.num_documento,v.fecha,vp.fecha_registro,'Compra'as tipo_movimiento,u.nombre usuario ,cl.razon_social as cliente,cl.direccion,cl.telefono, s.nombre sucursal, tp.nombre tipopago,c.nombre,valor_total,vp.monto, vp.monto_pendiente,v.observacion
FROM aprendea_erp.compra_pagos vp,compras v,usuarios u,sucursales s,tipoPago tp,cajas c,proveedores cl where vp.tipoPago=tp.id and vp.cuentaPago=c.id and v.id_sucursal=s.id
and v.id=vp.id_compra and v.id_proveedor=cl.id and vp.usuario=u.id and vp.fecha_registro between '{$ini} 00:00:01' and '{$fin} 23:59:59' and vp.monto>=0 ORDER BY `fecha_registro` DESC"
    );

    $infoventas_reporte = $pdo->query($sql_reporte)->fetchAll();

    $sql_caja = str_replace(
        ["{$ini}","{$fin}"],
        [$ini,$fin],
        "SELECT v.id,cl.num_documento,v.fecha,vp.fecha_registro,'Venta' AS tipo_movimiento,u.nombre usuario,cl.nombre as cliente,cl.direccion,cl.telefono, s.nombre sucursal, tp.nombre tipopago,c.nombre, valor_total,vp.monto, vp.monto_pendiente,v.observacion
FROM aprendea_erp.venta_pagos vp,ventas v,usuarios u,sucursales s,tipoPago tp,cajas c ,clientes cl where vp.tipoPago=tp.id and vp.cuentaPago=c.id and v.id_sucursal=s.id
and v.id=vp.id_venta and v.id_cliente=cl.id and vp.usuario=u.id and vp.fecha_registro between '{$ini} 00:00:01' and '{$fin} 23:59:59' and vp.monto>=0 and v.estado='1'
union all
SELECT v.id,cl.num_documento,v.fecha,vp.fecha_registro,'Compra'as tipo_movimiento,u.nombre usuario ,cl.razon_social as cliente,cl.direccion,cl.telefono, s.nombre sucursal, tp.nombre tipopago,c.nombre,valor_total,vp.monto, vp.monto_pendiente,v.observacion
FROM aprendea_erp.compra_pagos vp,compras v,usuarios u,sucursales s,tipoPago tp,cajas c,proveedores cl where vp.tipoPago=tp.id and vp.cuentaPago=c.id and v.id_sucursal=s.id
and v.id=vp.id_compra and v.id_proveedor=cl.id and vp.usuario=u.id and vp.fecha_registro between '{$ini} 00:00:01' and '{$fin} 23:59:59' and vp.monto>=0 ORDER BY `fecha_registro` DESC"
    );

    $info_reporte_caja = $pdo->query($sql_caja)->fetchAll();

    // 🔹 Respuesta final
    $resp = [
        "status"=>200,
        "boletas"=>$infoboleta,
        "pendiente"=>$infopendiente,
        "gasto"=>$infogasto,
        "productos"=>$infoproducto,
        "clientes"=>$infoclientes,
        "clientes_tabla"=>$infoclientestabla,
        "sucursales"=>$infosucursales,
        "compras"=>$infocompras,
        "ventas"=>$infoventas,
        "reporte_caja"=>$info_reporte_caja,
        "reporte"=>$infoventas_reporte,
        "inicio"=>$ini,
        "final"=>$fin
    ];

    $response->getBody()->write(json_encode($resp));

    return $response->withHeader('Content-Type','application/json');
});


$app->post('/login', function (Request $request, Response $response) use ($pdo) {

    $data = json_decode($request->getBody()->getContents(), true);

    $sql = "SELECT u.*, s.id id_sucursal, s.nombre sucursal, s.direccion, s.telefono
            FROM usuarios u
            INNER JOIN permisos p ON u.id = p.id_usuario
            INNER JOIN sucursales s ON p.id_sucursal = s.id
            WHERE p.estado = 1
            AND u.nombre = :usuario
            AND u.contrasena = :password limit 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':usuario' => $data['usuario'],
        ':password' => $data['password']
    ]);

    $usuario = $stmt->fetchAll();

    $resp = (count($usuario) > 0)
        ? ["status" => true, "rows" => count($usuario), "data" => $usuario]
        : ["status" => false, "rows" => 0, "data" => null];

    $response->getBody()->write(json_encode($resp));

    return $response->withHeader('Content-Type', 'application/json');
});




$app->get('/contacto', function($request, $response, $args){
    $response->getBody()->write('Pagina contacto');
    return $response;
});

$app->get('/blog', function($request, $response, $args){
    $response->getBody()->write('Pagina blog');
    return $response;
});


$app->run();