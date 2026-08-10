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
/*Produccion

$usuario="aprendea_erp";
$clave="erp2023*";
*/
/*Local dev*/
$dsn = "mysql:host=localhost;dbname=erp;port=3306;charset=utf8";
$usuario="root";
$clave= "";


try {
    $pdo = new PDO($dsn, $usuario, $clave, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
    ]);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

$pdo->exec("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
// 🔥 SOLUCIÓN
$app->setBasePath('/slim/api.php');

$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->get('/ventas', function (Request $request, Response $response) use ($pdo) {

    $sql = "SELECT
                v.id,
                c.num_documento,
                c.telefono,
                c.direccion,
                c.id as id_cliente,
                c.nombre as cliente,
                u.nombre,
                v.tipoDoc,
                v.id_vendedor,
                v.id_sucursal,
                DATE_FORMAT(v.fecha_registro, '%d-%m-%Y') as fechaPago,
                IF(v.pendientes=0,'No','Si') as pendientes,
                v.igv,
                v.monto_igv,
                v.descuento,
                v.valor_neto,
                v.valor_total,
                v.monto_pendiente,
                CASE
                    WHEN v.estado ='1' THEN 'Registrado'
                    WHEN v.estado = '2' THEN 'Anulado'
                END as estado,
                v.observacion
            FROM ventas v
            INNER JOIN clientes c ON v.id_cliente = c.id AND v.estado = 1
            INNER JOIN usuarios u ON v.id_usuario = u.id
            WHERE DATE_FORMAT(v.fecha_registro, '%d-%m-%Y') = DATE_FORMAT(NOW(), '%d-%m-%Y')
            ORDER BY v.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $payload = json_encode($prods);

    $response->getBody()->write($payload);

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

$app->post('/consulta-ventas', function (Request $request, Response $response) use ($pdo) {
       $body = $request->getBody()->getContents();
    $j = json_decode($body, true);

    // En tu código original viene doble JSON
    $dat = json_decode($j['json']);

    $arraymeses = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $arraynros  = ['01','02','03','04','05','06','07','08','09','10','11','12'];

    $mes1 = substr($dat->ini, 0, 3);
    $mes2 = substr($dat->fin, 0, 3);
    $dia1 = substr($dat->ini, 3, 2);
    $dia2 = substr($dat->fin, 3, 2);
    $ano1 = substr($dat->ini, 5, 4);
    $ano2 = substr($dat->fin, 5, 4);

    $fmes1 = str_replace($arraymeses, $arraynros, $mes1);
    $fmes2 = str_replace($arraymeses, $arraynros, $mes2);

    $ini = $ano1 . '-' . $fmes1 . '-' . $dia1;
    $fin = $ano2 . '-' . $fmes2 . '-' . $dia2;

    // 🔐 QUERY SEGURA CON PDO
    $sql = "SELECT
                v.id,
                v.estado,
                c.num_documento,
                c.telefono,
                c.direccion,
                c.id as id_cliente,
                c.nombre as cliente,
                u.nombre,
                v.tipoDoc,
                v.id_vendedor,
                v.id_sucursal,
                DATE_FORMAT(v.fecha_registro, '%d-%m-%Y') as fechaPago,
                IF(v.pendientes=0,'No','Si') as pendientes,
                v.igv,
                v.monto_igv,
                v.descuento,
                v.valor_neto,
                v.valor_total,
                v.monto_pendiente,
                CASE
                    WHEN v.estado ='1' THEN 'Registrado'
                    WHEN v.estado = '2' THEN 'Anulado'
                END as estado,
                v.observacion
            FROM ventas v
            INNER JOIN clientes c ON v.id_cliente = c.id
            INNER JOIN usuarios u ON v.id_usuario = u.id
            WHERE v.fecha_registro BETWEEN :ini AND :fin
              AND v.estado = :estado
            ORDER BY v.id DESC";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':ini'    => $ini . ' 00:00:01',
        ':fin'    => $fin . ' 23:59:59',
        ':estado' => $dat->estado
    ]);

    $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response->getBody()->write(json_encode($prods));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
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



$app->get('/articulos', function (Request $request, Response $response) use ($pdo) {

    $sql = "SELECT
                p.id,
                p.codigo,
                p.codigobarras,
                p.nombre,
                c.nombre AS categoria,
                sc.nombre AS subcategoria,
                fa.nombre AS familia,
                p.unidad,
                p.precio,
                p.precio_compra,
                p.imagen
            FROM productos p
            LEFT JOIN categorias c ON p.id_categoria = c.id
            LEFT JOIN sub_categorias sc ON p.id_subcategoria = sc.id
            LEFT JOIN sub_sub_categorias fa ON p.id_sub_sub_categoria = fa.id
            ORDER BY p.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response->getBody()->write(json_encode($prods));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

$app->get('/categorias', function (Request $request, Response $response) use ($pdo) {

    $sql = "SELECT id, nombre FROM categorias ORDER BY id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response->getBody()->write(json_encode($categorias));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

$app->post('/subcategoria', function (Request $request, Response $response) use ($pdo) {

    $body = $request->getBody()->getContents();
    $j = json_decode($body, true);

    // Tu doble JSON original
    $data = json_decode($j['json']);

    try {
        $sql = "INSERT INTO sub_categorias (id_categoria, nombre,usuario)
                VALUES (:id_categoria, :nombre,'admin')";

        $stmt = $pdo->prepare($sql);

        $proceso = $stmt->execute([
            ':id_categoria' => $data->id_categoria,
            ':nombre'       => $data->nombre
        ]);

        if ($proceso) {
            $result = [
                "STATUS"  => true,
                "messaje" => "Subcategoría creada correctamente"
            ];
        } else {
            $result = [
                "STATUS"  => false,
                "messaje" => "Ocurrió un error en la creación"
            ];
        }

    } catch (PDOException $e) {
        $result = [
            "STATUS"  => false,
            "messaje" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

$app->post('/familia', function ($request, $response) use ($pdo) {

    header("Content-Type: application/json; charset=utf-8");

    $body = $request->getBody()->getContents();

    $j = json_decode($body, true);

    $data = json_decode($j['json']);

    try {

        $sql = "INSERT INTO sub_sub_categorias (
                    id_subcategoria,
                    nombre,
                    usuario
                ) VALUES (
                    :id_subcategoria,
                    :nombre,
                    'admin'
                )";

        $stmt = $pdo->prepare($sql);

        $proceso = $stmt->execute([
            ':id_subcategoria' => $data->id_subCategoria,
            ':nombre' => $data->nombre
        ]);

        if ($proceso) {

            $result = [
                "STATUS" => true,
                "messaje" => "Familia creada correctamente"
            ];

        } else {

            $result = [
                "STATUS" => false,
                "messaje" => "Ocurrió un error en la creación"
            ];
        }

    } catch (PDOException $e) {

        $result = [
            "STATUS" => false,
            "messaje" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});


$app->get('/subcategoria/{criterio}', function (Request $request, Response $response, $args) use ($pdo) {

    $criterio = $args['criterio'];

    $sql = "SELECT
                sc.nombre,
                p.id_subcategoria AS id,
                p.id_categoria
            FROM productos p
            INNER JOIN sub_categorias sc ON p.id_subcategoria = sc.id
            WHERE p.id_categoria = :criterio
            GROUP BY sc.nombre, p.id_subcategoria, p.id_categoria
            ORDER BY sc.nombre ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':criterio' => $criterio
    ]);

    $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response->getBody()->write(json_encode($prods));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});


$app->post('/buscargeneral', function (Request $request, Response $response) use ($pdo) {

    $body = $request->getBody()->getContents();
    $j = json_decode($body, true);

    // Tu doble JSON original
    $data = json_decode($j['json']);

    try {

        $sql = "SELECT * FROM productos WHERE id_categoria = :cat";
        $params = [
            ':cat' => $data->cat
        ];

        if ($data->tipo === 'subcategoria') {
            $sql .= " AND id_subcategoria = :sub";
            $params[':sub'] = $data->sub;
        }

        if ($data->tipo === 'familia') {
            $sql .= " AND id_subcategoria = :sub AND id_sub_sub_categoria = :fam";
            $params[':sub'] = $data->sub;
            $params[':fam'] = $data->fam;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($prods));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);

    } catch (PDOException $e) {

        $error = [
            "STATUS"  => false,
            "message" => $e->getMessage()
        ];

        $response->getBody()->write(json_encode($error));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(500);
    }
});

$app->get('/familia/{criterio}', function (Request $request, Response $response, $args) use ($pdo) {

    $criterio = $args['criterio'];

    $sql = "SELECT
                f.nombre,
                p.id_sub_sub_categoria AS id,
                p.id_subcategoria
            FROM productos p
            INNER JOIN sub_sub_categorias f ON p.id_sub_sub_categoria = f.id
            WHERE p.id_subcategoria = :criterio
            GROUP BY f.nombre, p.id_sub_sub_categoria, p.id_subcategoria
            ORDER BY f.nombre ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':criterio' => $criterio
    ]);

    $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response->getBody()->write(json_encode($prods));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

$app->post('/buscaarticulos', function (Request $request, Response $response) use ($pdo) {

    $body = $request->getBody()->getContents();
    $j = json_decode($body, true);

    // Tu doble JSON original
    $data = json_decode($j['json']);

    try {

        // Separar palabras de búsqueda
        $palabras = explode(" ", trim($data));

        $whereParts = [];
        $params = [];

        foreach ($palabras as $index => $palabra) {
            $key = ":palabra" . $index;
            $whereParts[] = "p.nombre LIKE $key";
            $params[$key] = "%" . $palabra . "%";
        }

        // Construcción dinámica segura
        $whereNombre = implode(" AND ", $whereParts);

        $sql = "SELECT
                    p.id,
                    p.codigo,
                    p.nombre,
                    c.nombre AS categoria,
                    sc.nombre AS subcategoria,
                    fa.nombre AS familia,
                    p.unidad,
                    p.precio,
                    p.precio_compra,
                    p.imagen
                FROM productos p
                LEFT JOIN categorias c ON p.id_categoria = c.id
                LEFT JOIN sub_categorias sc ON p.id_subcategoria = sc.id
                LEFT JOIN sub_sub_categorias fa ON p.id_sub_sub_categoria = fa.id
                WHERE ($whereNombre)
                   OR p.codigo LIKE :codigo
                   OR p.codigobarras LIKE :codigobarras";

        $params[':codigo'] = "%" . $data . "%";
        $params[':codigobarras'] = "%" . $data . "%";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($prods));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);

    } catch (PDOException $e) {

        $error = [
            "STATUS" => false,
            "message" => $e->getMessage()
        ];

        $response->getBody()->write(json_encode($error));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(500);
    }
});


$app->get('/clientes', function (Request $request, Response $response) use ($pdo) {

    $sql = "SELECT * FROM clientes ORDER BY nombre ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response->getBody()->write(json_encode($clientes));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});


$app->get('/compras', function (Request $request, Response $response) use ($pdo) {

    $sql = "SELECT
                v.id,
                c.id AS id_proveedor,
                c.telefono,
                c.num_documento,
                c.razon_social AS cliente,
                u.nombre,
                v.tipoDoc,
                v.serie_documento,
                v.nro_documento,
                v.id_sucursal,
                DATE_FORMAT(v.fecha, '%d-%m-%Y') AS fecha,
                DATE_FORMAT(v.fecha_registro, '%d-%m-%Y') AS fechaPago,
                IF(v.pendientes=0,'No','Si') AS pendientes,
                CASE
                    WHEN v.estado ='1' THEN 'Registrado'
                    WHEN v.estado = '2' THEN 'Anulado'
                END AS estado,
                v.igv,
                v.monto_igv,
                v.descuento,
                v.valor_neto,
                v.valor_total,
                v.monto_pendiente,
                v.observacion
            FROM compras v
            INNER JOIN proveedores c ON v.id_proveedor = c.id
            INNER JOIN usuarios u ON v.id_usuario = u.id AND v.estado = 1
            ORDER BY v.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response->getBody()->write(json_encode($compras));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});


$app->get('/proveedores', function (Request $request, Response $response) use ($pdo) {

    $sql = "SELECT * FROM proveedores ORDER BY id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response->getBody()->write(json_encode($proveedores));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

$app->get('/inventario', function (Request $request, Response $response) use ($pdo) {

    $sql = "SELECT
                i.producto_id,
                a.nombre,
                a.codigo,
                i.id_almacen,
                s.nombre AS almacen,
                i.cantidad,
                i.fecha_actualizacion
            FROM inventario i
            INNER JOIN productos a ON a.id = i.producto_id
            INNER JOIN sucursales s ON i.id_almacen = s.id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $inventario = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response->getBody()->write(json_encode($inventario));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});


$app->get('/tabla/{tabla}', function (Request $request, Response $response, $args) use ($pdo) {

    $tabla = $args['tabla'];

    // ✅ LISTA BLANCA (obligatorio)
    $tablasPermitidas = [
        'clientes',
        'proveedores',
        'productos',
        'categorias',
        'sub_categorias',
        'sub_sub_categorias',
        'sucursales',
        'tipoPago',
        'cajas'
    ];

    if (!in_array($tabla, $tablasPermitidas)) {
        $error = [
            "STATUS" => false,
            "message" => "Tabla no permitida"
        ];

        $response->getBody()->write(json_encode($error));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(400);
    }

    try {

        // ⚠️ Aquí NO se puede usar :tabla como parámetro
        $sql = "SELECT * FROM {$tabla} ORDER BY id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($data));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);

    } catch (PDOException $e) {

        $error = [
            "STATUS" => false,
            "message" => $e->getMessage()
        ];

        $response->getBody()->write(json_encode($error));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(500);
    }
});


$app->get('/tabla/{tabla}/{id}', function (Request $request, Response $response, $args) use ($pdo) {

    $tabla = $args['tabla'];
    $id    = $args['id'];

    // ✅ Lista blanca de tablas permitidas
    $tablasPermitidas = [
        'clientes',
        'proveedores',
        'productos',
        'categorias',
        'sub_categorias',
        'sub_sub_categorias',
        'sucursales'
    ];

    if (!in_array($tabla, $tablasPermitidas)) {
        $error = [
            "STATUS"  => false,
            "message" => "Tabla no permitida"
        ];

        $response->getBody()->write(json_encode($error));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    // Validar ID (evita cosas raras tipo "1 OR 1=1")
    if (!is_numeric($id)) {
        $error = [
            "STATUS"  => false,
            "message" => "ID inválido"
        ];

        $response->getBody()->write(json_encode($error));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    try {

        // ⚠️ El nombre de tabla NO se puede parametrizar en PDO
        $sql = "SELECT * FROM {$tabla} WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id
        ]);

        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($data));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);

    } catch (PDOException $e) {

        $error = [
            "STATUS"  => false,
            "message" => $e->getMessage()
        ];

        $response->getBody()->write(json_encode($error));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(500);
    }
});


$app->get('/movimientos', function (Request $request, Response $response) use ($pdo) {

    try {

        $sql = "SELECT
                    p.id,
                    p.codigo,
                    p.nombre,
                    p.categoria
                FROM movimiento_articulos m
                INNER JOIN productos p ON m.codigo_prod = p.id
                WHERE (m.cantidad_ingreso > 0 OR m.cantidad_salida < 0)
                GROUP BY p.id
                ORDER BY p.id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $prods = [];

        foreach ($productos as $fila) {

            $id = $fila['id'];

            // 🔹 DETALLE
            $sqlDetalle = "SELECT
                                m.id,
                                m.tipo_movimiento,
                                s.nombre AS almacen,
                                m.id_compra,
                                m.id_venta,
                                m.cantidad_acumulada,
                                u.nombre AS unidad,
                                m.cantidad_movimiento,
                                ROUND(m.cantidad_acumulada * m.promedio, 2) AS p_total,
                                m.cantidad_ingreso,
                                m.cantidad_salida,
                                m.precio,
                                m.promedio,
                                ROUND(m.cantidad_acumulada * m.precio, 2) AS costo,
                                m.comentario,
                                DATE_FORMAT(m.fecha_registro,'%d-%m-%Y') AS fecha_registro
                            FROM movimiento_articulos m
                            INNER JOIN sucursales s ON s.id = m.id_sucursal
                            INNER JOIN productos p ON m.codigo_prod = p.id
                            INNER JOIN unidad u ON p.unidad = u.codigo
                            WHERE m.codigo_prod = :id
                              AND NOT (m.cantidad_ingreso = 0 AND m.cantidad_salida = 0)
                              AND m.precio <> 0
                            ORDER BY m.id DESC";

            $stmtDetalle = $pdo->prepare($sqlDetalle);
            $stmtDetalle->execute([':id' => $id]);
            $fila['detalle'] = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

            // 🔹 PROMEDIO
            $sqlProm = "SELECT promedio, cantidad_acumulada, u.nombre AS unidad
                        FROM movimiento_articulos m
                        INNER JOIN productos p ON m.codigo_prod = p.id
                        INNER JOIN unidad u ON p.unidad = u.codigo
                        WHERE m.codigo_prod = :id
                        ORDER BY m.id DESC
                        LIMIT 1";

            $stmtProm = $pdo->prepare($sqlProm);
            $stmtProm->execute([':id' => $id]);
            $fila['promedio'] = $stmtProm->fetchAll(PDO::FETCH_ASSOC);

            // 🔹 STOCK
            $sqlStock = "SELECT
                            codigo_prod,
                            SUM(cantidad_ingreso) - SUM(cantidad_salida) AS cantidad
                         FROM movimiento_articulos
                         WHERE codigo_prod = :id
                         GROUP BY codigo_prod";

            $stmtStock = $pdo->prepare($sqlStock);
            $stmtStock->execute([':id' => $id]);
            $fila['stock'] = $stmtStock->fetch(PDO::FETCH_ASSOC);

            // 🔹 TOTALES
            $sqlTotales = "SELECT
                                SUM(cantidad_ingreso * precio) AS total_entrada,
                                SUM(cantidad_salida * precio) AS total_salida,
                                SUM((cantidad_salida * precio) - (cantidad_ingreso * precio)) AS costo_venta
                           FROM movimiento_articulos
                           WHERE codigo_prod = :id";

            $stmtTot = $pdo->prepare($sqlTotales);
            $stmtTot->execute([':id' => $id]);
            $totales = $stmtTot->fetch(PDO::FETCH_ASSOC);

            $fila['total_entrada'] = $totales['total_entrada'];
            $fila['total_salida']  = $totales['total_salida'];
            $fila['costo_venta']   = $totales['costo_venta'];

            $prods[] = $fila;
        }

        $response->getBody()->write(json_encode($prods));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);

    } catch (PDOException $e) {

        $error = [
            "STATUS" => false,
            "message" => $e->getMessage()
        ];

        $response->getBody()->write(json_encode($error));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
});


$app->get('/buscarproducto/{criterio}', function (Request $request, Response $response, $args) use ($pdo) {

    $criterio = $args['criterio'];

    try {

        $sql = "SELECT *
                FROM productos
                WHERE nombre LIKE :criterio
                   OR id LIKE :criterio
                   OR codigo LIKE :criterio";

        $stmt = $pdo->prepare($sql);

        $like = "%" . $criterio . "%";

        $stmt->execute([
            ':criterio' => $like
        ]);

        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($prods));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);

    } catch (PDOException $e) {

        $error = [
            "STATUS" => false,
            "message" => $e->getMessage()
        ];

        $response->getBody()->write(json_encode($error));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(500);
    }
});


$app->get('/kardex', function (Request $request, Response $response) use ($pdo) {

    header("Content-Type: application/json; charset=utf-8");

    // Fecha actual
    $ini = date('Y-m-d') . ' 00:00:00';
    $fin = date('Y-m-d') . ' 23:59:59';



    try {

        /*
        |--------------------------------------------------------------------------
        | PRODUCTOS + STOCK + ÚLTIMO PROMEDIO
        |--------------------------------------------------------------------------
        */

        $sqlProductos = "
            SELECT DISTINCT
                p.id,
                p.codigo,
                p.nombre,
                p.categoria,

                (
                    SELECT ma.promedio
                    FROM movimiento_articulos ma
                    WHERE ma.codigo_prod = p.id
                    ORDER BY ma.id DESC
                    LIMIT 1
                ) AS promedio,

                (
                    SELECT ma.cantidad_acumulada
                    FROM movimiento_articulos ma
                    WHERE ma.codigo_prod = p.id
                    ORDER BY ma.id DESC
                    LIMIT 1
                ) AS cantidad_acumulada,

                (
                    SELECT COALESCE(SUM(ma.cantidad_ingreso) - SUM(ma.cantidad_salida),0)
                    FROM movimiento_articulos ma
                    WHERE ma.codigo_prod = p.id
                ) AS stock

            FROM movimiento_articulos m
            INNER JOIN productos p ON p.id = m.codigo_prod

            WHERE m.fecha_registro BETWEEN :ini AND :fin


            ORDER BY p.id DESC";

        $stmt = $pdo->prepare($sqlProductos);
        $stmt->execute([
            ':ini' => $ini,
            ':fin' => $fin
        ]);

        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($productos)) {

            $response->getBody()->write(json_encode([]));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);
        }

        $ids = array_column($productos, 'id');

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sqlDetalle = "
            SELECT
                m.id,
                m.codigo_prod,
                m.tipo_movimiento,
                m.estado,
                s.id AS id_almacen,
                s.nombre AS almacen,
                m.id_compra,
                m.id_venta,
                m.cantidad_acumulada,
                u.nombre AS unidad,
                m.cantidad_movimiento,
                ROUND(m.cantidad_acumulada * m.promedio,2) AS p_total,
                m.cantidad_ingreso,
                m.cantidad_salida,
                m.precio,
                m.promedio,
                ROUND(m.cantidad_acumulada * m.precio,2) AS costo,
                m.comentario,
                m.fecha_registro

            FROM movimiento_articulos m

            INNER JOIN sucursales s
                ON s.id = m.id_sucursal

            INNER JOIN productos p
                ON p.id = m.codigo_prod

            INNER JOIN unidad u
                ON u.codigo = p.unidad

            WHERE m.codigo_prod IN ($placeholders)

            AND m.fecha_registro BETWEEN ? AND ?

            AND NOT (
                m.cantidad_ingreso = 0
                AND m.cantidad_salida = 0
            )

            AND m.precio <> 0

            ORDER BY
                m.codigo_prod,
                m.id DESC";

        $detalleParams = $ids;
        $detalleParams[] = $ini;
        $detalleParams[] = $fin;

        $stmtDetalle = $pdo->prepare($sqlDetalle);
        $stmtDetalle->execute($detalleParams);

        $detalles = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

        $detallePorProducto = [];

        foreach ($detalles as $detalle) {

            $detallePorProducto[$detalle['codigo_prod']][] = [
                'id'                 => $detalle['id'],
                'tipo_movimiento'    => $detalle['tipo_movimiento'],
                'estado'             => $detalle['estado'],
                'id_almacen'         => $detalle['id_almacen'],
                'almacen'            => $detalle['almacen'],
                'id_compra'          => $detalle['id_compra'],
                'id_venta'           => $detalle['id_venta'],
                'cantidad_acumulada' => $detalle['cantidad_acumulada'],
                'unidad'             => $detalle['unidad'],
                'cantidad_movimiento'=> $detalle['cantidad_movimiento'],
                'p_total'            => $detalle['p_total'],
                'cantidad_ingreso'   => $detalle['cantidad_ingreso'],
                'cantidad_salida'    => $detalle['cantidad_salida'],
                'precio'             => $detalle['precio'],
                'promedio'           => $detalle['promedio'],
                'costo'              => $detalle['costo'],
                'comentario'         => $detalle['comentario'],
                'fecha_registro'     => date(
                    'd-m-Y H:i:s',
                    strtotime($detalle['fecha_registro'])
                )
            ];
        }

        foreach ($productos as &$producto) {

            $producto['promedio'] = [
                'promedio' => $producto['promedio'],
                'cantidad_acumulada' => $producto['cantidad_acumulada']
            ];

            $producto['stock'] = [
                'cantidad' => $producto['stock']
            ];

            $producto['detalle'] =
                $detallePorProducto[$producto['id']] ?? [];
        }

        unset($producto);

        $response->getBody()->write(
            json_encode($productos, JSON_UNESCAPED_UNICODE)
        );

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);

    } catch (PDOException $e) {

        $response->getBody()->write(json_encode([
            'STATUS' => false,
            'message' => $e->getMessage()
        ]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(500);
    }
});




$app->post('/kardex', function (Request $request, Response $response) use ($pdo) {

    header("Content-Type: application/json; charset=utf-8");

    $body = $request->getBody()->getContents();
    $j = json_decode($body, true);
    $data = json_decode($j['json'], true);

    $arraymeses = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $arraynros  = ['01','02','03','04','05','06','07','08','09','10','11','12'];

    $mes1 = substr($data['inicio'], 0, 3);
    $mes2 = substr($data['fin'], 0, 3);

    $dia1 = substr($data['inicio'], 3, 2);
    $dia2 = substr($data['fin'], 3, 2);

    $ano1 = substr($data['inicio'], 5, 4);
    $ano2 = substr($data['fin'], 5, 4);

    $ini = $ano1 . '-' . str_replace($arraymeses, $arraynros, $mes1) . '-' . $dia1 . ' 00:00:01';
    $fin = $ano2 . '-' . str_replace($arraymeses, $arraynros, $mes2) . '-' . $dia2 . ' 23:59:59';

    try {

        /*
        |--------------------------------------------------------------------------
        | PRODUCTOS + STOCK + ÚLTIMO PROMEDIO
        |--------------------------------------------------------------------------
        */

        $sqlProductos = "
    SELECT
        p.id,
        p.codigo,
        p.nombre,
        p.categoria,

        ult.promedio,
        ult.cantidad_acumulada,

        ult.cantidad_acumulada AS stock

    FROM productos p

    INNER JOIN (

        SELECT
            m1.codigo_prod,
            m1.promedio,
            m1.cantidad_acumulada

        FROM movimiento_articulos m1

        INNER JOIN (

            SELECT
                codigo_prod,
                MAX(id) AS id

            FROM movimiento_articulos

            WHERE fecha_registro <= :fin

            GROUP BY codigo_prod

        ) mx ON mx.id = m1.id

    ) ult ON ult.codigo_prod = p.id

    WHERE EXISTS (


    SELECT 1
    FROM movimiento_articulos ma
    WHERE ma.codigo_prod = p.id
      AND fecha_registro BETWEEN :ini AND :fin


    )
";

        $params = [
            ':ini' => $ini,
            ':fin' => $fin
        ];

        if (!empty($data['producto'])) {
            $sqlProductos .= " AND codigo_prod = :producto";
            $params[':producto'] = $data['producto'];
        }

        $sqlProductos .= " ORDER BY p.id DESC";

        //echo $sqlProductos;
        //exit;

        $stmt = $pdo->prepare($sqlProductos);
        $stmt->execute($params);

        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($productos)) {

            $response->getBody()->write(json_encode([]));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);
        }

        /*
        |--------------------------------------------------------------------------
        | IDs DE PRODUCTOS
        |--------------------------------------------------------------------------
        */

        $ids = array_column($productos, 'id');

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        /*
        |--------------------------------------------------------------------------
        | DETALLES (UNA SOLA CONSULTA)
        |--------------------------------------------------------------------------
        */

        $sqlDetalle = "
            SELECT
                m.id,
                m.codigo_prod,
                m.tipo_movimiento,
                m.estado,
                s.nombre AS almacen,
                s.id AS id_almacen,
                m.id_compra,
                m.id_venta,
                m.cantidad_acumulada,
                u.nombre AS unidad,
                m.cantidad_movimiento,
                ROUND(m.cantidad_acumulada * m.promedio,2) AS p_total,
                m.cantidad_ingreso,
                m.cantidad_salida,
                m.precio,
                m.promedio,
                ROUND(m.cantidad_acumulada * m.precio,2) AS costo,
                m.comentario,
                m.fecha_registro

            FROM movimiento_articulos m

            INNER JOIN sucursales s
                ON s.id = m.id_sucursal

            INNER JOIN productos p
                ON p.id = m.codigo_prod

            INNER JOIN unidad u
                ON u.codigo = p.unidad

          WHERE m.codigo_prod IN ($placeholders)

            AND m.fecha_registro BETWEEN ? AND ?

            AND NOT (
                m.cantidad_ingreso = 0
                AND m.cantidad_salida = 0
                )

            AND m.precio <> 0
        ";

        $detalleParams = $ids;

        $detalleParams[] = $ini;
        $detalleParams[] = $fin;

        if (!empty($data['sucursal']) && $data['sucursal'] != "0") {
            $sqlDetalle .= " AND m.id_sucursal = ?";
            $detalleParams[] = $data['sucursal'];
        }

        if (!empty($data['movimiento']) && $data['movimiento'] != "0") {
            $sqlDetalle .= " AND m.tipo_movimiento = ?";
            $detalleParams[] = $data['movimiento'];
        }

        if (!empty($data['compra'])) {
            $sqlDetalle .= " AND m.id_compra = ?";
            $detalleParams[] = $data['compra'];
        }

        if (!empty($data['venta'])) {
            $sqlDetalle .= " AND m.id_venta = ?";
            $detalleParams[] = $data['venta'];
        }

        $sqlDetalle .= "
            ORDER BY
                m.codigo_prod,
                m.id DESC
        ";

        $stmtDetalle = $pdo->prepare($sqlDetalle);
        $stmtDetalle->execute($detalleParams);

        $detalles = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

        /*
        |--------------------------------------------------------------------------
        | AGRUPAR DETALLES POR PRODUCTO
        |--------------------------------------------------------------------------
        */

        $detallePorProducto = [];

        foreach ($detalles as $detalle) {

            $detallePorProducto[$detalle['codigo_prod']][] = [
                'id'                 => $detalle['id'],
                'tipo_movimiento'    => $detalle['tipo_movimiento'],
                'estado'             => $detalle['estado'],
                'id_almacen'         => $detalle['id_almacen'],
                'almacen'            => $detalle['almacen'],
                'id_compra'          => $detalle['id_compra'],
                'id_venta'           => $detalle['id_venta'],
                'cantidad_acumulada' => $detalle['cantidad_acumulada'],
                'unidad'             => $detalle['unidad'],
                'cantidad_movimiento'=> $detalle['cantidad_movimiento'],
                'p_total'            => $detalle['p_total'],
                'cantidad_ingreso'   => $detalle['cantidad_ingreso'],
                'cantidad_salida'    => $detalle['cantidad_salida'],
                'precio'             => $detalle['precio'],
                'promedio'           => $detalle['promedio'],
                'costo'              => $detalle['costo'],
                'comentario'         => $detalle['comentario'],
                'fecha_registro'     => date(
                    'd-m-Y H:i:s',
                    strtotime($detalle['fecha_registro'])
                )
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | ARMAR RESPUESTA
        |--------------------------------------------------------------------------
        */

        foreach ($productos as &$producto) {

            $producto['promedio'] = [
                'promedio' => $producto['promedio'],
                'cantidad_acumulada' => $producto['cantidad_acumulada']
            ];

            $producto['stock'] = [
                'cantidad' => $producto['stock']
            ];

            $producto['detalle'] =
                $detallePorProducto[$producto['id']] ?? [];
        }

        unset($producto);

        $response->getBody()->write(
            json_encode($productos, JSON_UNESCAPED_UNICODE)
        );

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);

    } catch (PDOException $e) {

        $response->getBody()->write(json_encode([
            'STATUS' => false,
            'message' => $e->getMessage()
        ]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(500);
    }
});

$app->get('/vendedores', function (Request $request, Response $response) use ($pdo) {

    $sql = "SELECT * FROM vendedor ORDER BY id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $vendedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response->getBody()->write(json_encode($vendedores));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});



$app->post('/permisos', function (Request $request, Response $response) use ($pdo) {

    $body = $request->getBody()->getContents();
    $j = json_decode($body, true);
    $data = json_decode($j['json']);

    try {

        $sql = "CALL p_permisos(:id_usuario, :id_sucursal, :opcion, :usuario)";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':id_usuario'  => $data->id_usuario,
            ':id_sucursal' => $data->id_sucursal,
            ':opcion'      => 1,
            ':usuario'     => $data->usuario
        ]);

        $result = [
            "STATUS"  => true,
            "messaje" => "Permiso registrado correctamente"
        ];

    } catch (PDOException $e) {

        $result = [
            "STATUS"  => false,
            "messaje" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});


$app->get('/permisos', function (Request $request, Response $response) use ($pdo) {

    $sql = "SELECT
                p.id,
                s.nombre AS sucursal,
                u.nombre,
                p.estado,
                p.usuario,
                p.fecha_registro
            FROM permisos p
            INNER JOIN sucursales s ON p.id_sucursal = s.id
            INNER JOIN usuarios u ON p.id_usuario = u.id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response->getBody()->write(json_encode($permisos));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});


$app->get('/cajas/{uid}', function (Request $request, Response $response, $args) use ($pdo) {

    $uid = $args['uid'];

    try {

        $sql = "SELECT
                    c.id,
                    c.nombre,
                    c.tipo
                FROM cajas c
                INNER JOIN permisos_caja p ON c.id = p.id_caja
                WHERE p.id_usuario = :uid
                  AND c.estado = 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':uid' => $uid
        ]);

        $cajas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($cajas));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);

    } catch (PDOException $e) {

        $error = [
            "STATUS" => false,
            "message" => $e->getMessage()
        ];

        $response->getBody()->write(json_encode($error));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(500);
    }
});


$app->get('/usuarios', function (Request $request, Response $response) use ($pdo) {

    $sql = "SELECT * FROM usuarios ORDER BY id DESC";

    $stmt = $pdo->query($sql);
    $usuarios = $stmt->fetchAll();

    $response->getBody()->write(json_encode($usuarios));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/item-producto', function (Request $request, Response $response) use ($pdo) {



    try {

        $j = json_decode($request->getBody()->getContents(), true);

        $data = json_decode($j['json']);

        $pdo->beginTransaction();
        // Buscar el detalle actual
        $stmt = $pdo->prepare("
         SELECT
                id,
                cantidad,
                precio,
                id_producto
            FROM venta_detalle
            WHERE id_venta = ?;
        ");

        $stmt->execute([
            $data->id_venta
        ]);

        $detalle = $stmt->fetch(PDO::FETCH_OBJ);

        if (!$detalle) {
            throw new Exception("No existe el id de  venta.");
        }


 // detalle venta

        // Actualizar inventario solamente por la diferencia
        if ($detalle && exist($data->prod->id)) {


            $stmProd = $pdo->prepare("SELECT * FROM productos where id=?");
            $stmProd->execute([$data->prod->id]);
            $producto = $stmProd->fetch(PDO::FETCH_OBJ);


            $stmtD = $pdo->prepare("CALL p_venta_detalle(?,?,?,?,?,?,?,?,?,?)");
            $stmtD->execute([
                $data->id_venta,
                $data->prod->id,
                $data->prod->id,
                $producto->codigo,
                $producto->unidad,
                $data->prod->cantidad,
                $data->prod->cantidad-$data->prod->despacho,
                0,
                $data->prod->precio,
                'admin'
            ]);
            $stmtD->closeCursor();



            $stmt = $pdo->prepare("
                UPDATE inventario
                SET
                    cantidad = cantidad - ?,
                    fecha_actualizacion = NOW()
                WHERE producto_id = ?
                AND id_almacen = ?
            ");

            $stmt->execute([
                $data->prod->cantidad,
                $data->prod->id,
                $data->sucursal
            ]);

            // Registrar movimiento
            $stmt = $pdo->prepare("
                CALL p_registrar_movimiento(
                    ?,?,?,?,?,?,?,?
                )
            ");

            $stmt->execute([
                $data->prod->id,
                $data->id_venta,
                'Salida',
                $data->prod->cantidad,
                $data->prod->precio,
                2,
                $data->sucursal,
                'Actualización manual venta #' . $data->id_venta
            ]);

            $stmt->closeCursor();
        }
        // Recalcular total de la venta

//---------------------------------------------------
// CALCULAR NUEVO TOTAL DE LA VENTA
//---------------------------------------------------

/*obtenemos el nuevo total de venta desde el detalle*/
        $stmtTotal = $pdo->prepare("
        SELECT COALESCE(
            SUM(
                (cantidad * precio) - descuento
            ),
            0
        ) AS nuevo_total
        FROM venta_detalle
        WHERE id_venta = ?
        ");

        $stmtTotal->execute([$data->id_venta]);

        $rowTotal = $stmtTotal->fetch(PDO::FETCH_OBJ);

        $nuevoTotal = round((float)$rowTotal->nuevo_total, 2);

        $saldo = $nuevoTotal;

        /*obtenemos el total de la venta pagado actual*/
        $stmtPagos = $pdo->prepare("
            SELECT id, monto
            FROM venta_pagos
            WHERE id_venta = ?
            ORDER BY id ASC
            FOR UPDATE
        ");

        $stmtPagos->execute([$data->id_venta]);
        $pagos = $stmtPagos->fetchAll(PDO::FETCH_OBJ);

        //---------------------------------------------------
            // RECALCULAR PENDIENTES DE LOS PAGOS
            //---------------------------------------------------

            $saldo = $nuevoTotal;
            $totalPagado = 0;

            $stmtActualizarPago = $pdo->prepare("
                UPDATE venta_pagos
                SET monto_pendiente = ?
                WHERE id = ?
                AND id_venta = ?
            ");

            foreach ($pagos as $pago) {

                $montoPago = round((float)$pago->monto, 2);

                $totalPagado += $montoPago;

                $saldo = round($saldo - $montoPago, 2);

                $montoPendiente = max(0, $saldo);

                $stmtActualizarPago->execute([
                    $montoPendiente,
                    $pago->id,
                    $data->id_venta
                ]);
            }

//---------------------------------------------------
// ACTUALIZAR VENTA
//---------------------------------------------------

$montoPendienteVenta = max(
    0,
    round($nuevoTotal - $totalPagado, 2)
);

        $stmtVenta = $pdo->prepare("
            UPDATE ventas
            SET
                valor_total = ?,
                monto_pendiente = ?
            WHERE id = ?
        ");

        $stmtVenta->execute([
            $nuevoTotal,
            $montoPendienteVenta,
            $data->id_venta
        ]);


        /*$stmt = $pdo->prepare("
            UPDATE ventas
            SET valor_total = (
                SELECT SUM(subtotal)
                FROM venta_detalle
                WHERE id_venta = ?
            )

            WHERE id = ?
        ");

        $stmt->execute([
            $data->id_venta,
            $data->id_venta
        ]);
*/
        $pdo->commit();

    } catch (Exception $e) {

        $pdo->rollBack();

        $result = [
            'STATUS' => 500,
            'messaje' => $e->getMessage()
        ];
    }

    $result = [
        'STATUS' => 200,
        'messaje' => 'Registro actualizado',
        'total' => $nuevoTotal,
        'pendiente'=>$montoPendienteVenta
    ];

    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});



$app->post('/venta', function (Request $request, Response $response) use ($pdo) {

    $j = json_decode($request->getBody()->getContents(), true);

    $data = json_decode($j['json']);
    $detalle = json_decode($j['detalle']);

    $valor_total = 0;

    $pendiente = ($data->montopendiente < 0) ? 0 : $data->montopendiente;

    try {

        // 🔹 Iniciar transacción
        $pdo->beginTransaction();

        // 🔹 Ejecutar procedimiento venta
        $stmt = $pdo->prepare("CALL p_venta(?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $data->usuario,
            $data->vendedor,
            $data->cliente,
            $data->sucursal,
            $data->entrega,
            $data->tipoDoc,
            $data->neto,
            $data->total,
            $pendiente,
            ($data->total - $data->neto),
            $data->comentario
        ]);
        $stmt->closeCursor();

        // 🔹 Obtener último ID
        $ultimo_id = $pdo->query("SELECT MAX(id) as ultimo_id FROM ventas")->fetch();

        // 🔹 Pagos
        $valor_total = $data->total;

        foreach ($data->pagos as $pago) {

            $valor_total -= $pago->montoPago;

            if ($valor_total < 0) {
                $pago->montoPago += $data->montopendiente;
                $valor_total = 0;
            }

            $stmtP = $pdo->prepare("CALL p_venta_pago(?,?,?,?,?,?,?)");
            $stmtP->execute([
                $ultimo_id->ultimo_id,
                '',
                $pago->numero,
                $pago->cuentaPago,
                $pago->montoPago,
                (count($data->pagos) == 1) ? $pendiente : $valor_total,
                $data->usuario
            ]);
            $stmtP->closeCursor();
        }

        // 🔹 Detalle
        foreach ($detalle as $item) {

            // detalle venta
            $stmtD = $pdo->prepare("CALL p_venta_detalle(?,?,?,?,?,?,?,?,?,?)");
            $stmtD->execute([
                $ultimo_id->ultimo_id,
                $item->id,
                $item->id,
                $item->codigo,
                '',
                $item->cantidad,
                $item->pendiente,
                $item->descuento,
                $item->precio,
                $data->usuario
            ]);
            $stmtD->closeCursor();

            // actualizar inventario
            $stmtInv = $pdo->prepare("
                UPDATE inventario
                SET cantidad = cantidad - ?, fecha_actualizacion = NOW()
                WHERE producto_id = ? AND id_almacen = ?
            ");
            $stmtInv->execute([
                $item->despacho,
                $item->id,
                $data->sucursal
            ]);

            // movimiento
            $stmtMov = $pdo->prepare("CALL p_registrar_movimiento(?,?,?,?,?,?,?,?)");
            $stmtMov->execute([
                $item->id,
                $ultimo_id->ultimo_id,
                'Salida',
                $item->despacho,
                $item->precio,
                $data->usuario,
                $data->sucursal,
                'Venta realizada nro'. $ultimo_id->ultimo_id

            ]);
            $stmtMov->closeCursor();
        }

        // 🔹 Commit
        $pdo->commit();

        $result = [
            "STATUS" => true,
            "numero" => $ultimo_id->ultimo_id,
            "messaje" => "Venta registrada correctamente con el número: " . $ultimo_id->ultimo_id
        ];

    } catch (Exception $e) {

        $pdo->rollBack();

        $result = [
            "STATUS" => false,
            "messaje" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->put('/venta', function (
    Request $request,
    Response $response,
    array $args
) use ($pdo) {

    try {

        $body = json_decode(
            $request->getBody()->getContents()
        );

        if (
            !$body ||
            !isset($body->venta) ||
            !isset($body->venta->id)
        ) {
            throw new Exception('Los datos de la venta son inválidos.');
        }

        $data = $body->venta;
        $detalleVenta = $data->detalleVenta ?? [];

        $valorNeto = 0;
        $totalDescuento = 0;
        $montoIgv = 0;
        $valorTotal = 0;
        $totalPagado = 0;
        $pendiente = 0;

        $pdo->beginTransaction();

        //---------------------------------------------------
        // OBTENER EL DETALLE ANTERIOR
        //---------------------------------------------------

        $stmtDetalleAnterior = $pdo->prepare("
            SELECT
                id_producto,
                cantidad,
                id_inventario
            FROM venta_detalle
            WHERE id_venta = ?
        ");

        $stmtDetalleAnterior->execute([
            $data->id
        ]);

        $detalleAnterior = $stmtDetalleAnterior->fetchAll(
            PDO::FETCH_OBJ
        );

        //---------------------------------------------------
        // DEVOLVER EL STOCK DEL DETALLE ANTERIOR
        //---------------------------------------------------

        $stmtDevolverStock = $pdo->prepare("
            UPDATE inventario
            SET cantidad = cantidad + ?,
                fecha_actualizacion = NOW()
            WHERE producto_id = ?
              AND id_almacen = ?
        ");

        foreach ($detalleAnterior as $item) {

            $stmtDevolverStock->execute([
                $item->cantidad,
                $item->id_producto,
                $item->id_inventario
            ]);
        }

        //---------------------------------------------------
        // ELIMINAR MOVIMIENTOS ANTERIORES
        //---------------------------------------------------

        $stmtEliminarMovimientos = $pdo->prepare("
            DELETE FROM movimiento_articulos
            WHERE id_venta = ?
        ");

        $stmtEliminarMovimientos->execute([
            $data->id
        ]);

        //---------------------------------------------------
        // ELIMINAR SOLAMENTE EL DETALLE ANTERIOR
        //---------------------------------------------------

        $stmtEliminarDetalle = $pdo->prepare("
            DELETE FROM venta_detalle
            WHERE id_venta = ?
        ");

        $stmtEliminarDetalle->execute([
            $data->id
        ]);

        /*
         * NO SE ELIMINAN LOS PAGOS.
         *
         * Se eliminó intencionalmente:
         *
         * DELETE FROM venta_pagos WHERE id_venta = ?
         *
         * Tampoco se vuelve a ejecutar p_venta_pago(),
         * porque eso podría duplicar los pagos existentes.
         */

        //---------------------------------------------------
        // RECALCULAR LOS TOTALES DE LA VENTA
        //---------------------------------------------------

        foreach ($detalleVenta as $item) {

            $cantidad = (float) ($item->cantidad ?? 0);
            $precio = (float) ($item->precio ?? 0);
            $descuento = (float) ($item->descuento ?? 0);

            $subtotal = $cantidad * $precio;

            $valorNeto += $subtotal;
            $totalDescuento += $descuento;
        }

        $valorNeto = round(
            $valorNeto - $totalDescuento,
            2
        );

        // Evitar que el valor neto sea negativo.
        $valorNeto = max(0, $valorNeto);

        $montoIgv = round($valorNeto * 0.18, 2);

        // Se mantiene la lógica original.
        $valorTotal = $valorNeto;

        //---------------------------------------------------
        // OBTENER EL TOTAL DE PAGOS EXISTENTES
        //---------------------------------------------------

        $stmtPagos = $pdo->prepare("
            SELECT COALESCE(SUM(monto), 0)
            FROM venta_pagos
            WHERE id_venta = ?
        ");

        $stmtPagos->execute([
            $data->id
        ]);

        $totalPagado = (float) $stmtPagos->fetchColumn();

        //---------------------------------------------------
        // RECALCULAR EL MONTO PENDIENTE
        //---------------------------------------------------

        $pendiente = round(
            $valorTotal - $totalPagado,
            2
        );

        // Si los pagos son mayores al nuevo total,
        // el monto pendiente queda en cero.
        $pendiente = max(0, $pendiente);

        //---------------------------------------------------
        // ACTUALIZAR LA CABECERA DE LA VENTA
        //---------------------------------------------------

        $stmtActualizarVenta = $pdo->prepare("
            UPDATE ventas
            SET id_vendedor = ?,
                id_cliente = ?,
                id_sucursal = ?,
                tipoDoc = ?,
                valor_neto = ?,
                valor_total = ?,
                monto_pendiente = ?,
                monto_igv = ?,
                observacion = ?,
                usuario = ?,
                fecha_actualizacion = NOW()
            WHERE id = ?
        ");

        $stmtActualizarVenta->execute([
            $data->id_vendedor,
            $data->cliente,
            $data->id_sucursal,
            $data->tipoDoc,
            $valorNeto,
            $valorTotal,
            $pendiente,
            $montoIgv,
            $data->observacion ?? '',
            $data->nombre,
            $data->id
        ]);

        //---------------------------------------------------
        // REGISTRAR EL NUEVO DETALLE
        //---------------------------------------------------

        foreach ($detalleVenta as $item) {

            $stmtNuevoDetalle = $pdo->prepare("
                CALL p_venta_detalle(
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmtNuevoDetalle->execute([
                $data->id,
                $item->id_producto,
                $item->id_inventario,
                $item->codigo,
                '',
                $item->cantidad,
                $item->pendiente ?? 0,
                $item->descuento ?? 0,
                $item->precio,
                $data->nombre
            ]);

            $stmtNuevoDetalle->closeCursor();

            //------------------------------------------------
            // DESCONTAR INVENTARIO DEL NUEVO DETALLE
            //------------------------------------------------

            $stmtDescontarStock = $pdo->prepare("
                UPDATE inventario
                SET cantidad = cantidad - ?,
                    fecha_actualizacion = NOW()
                WHERE producto_id = ?
                  AND id_almacen = ?
            ");

            $stmtDescontarStock->execute([
                $item->cantidad,
                $item->id_producto,
                $item->id_inventario
            ]);

            //------------------------------------------------
            // REGISTRAR EL NUEVO MOVIMIENTO
            //------------------------------------------------

            $stmtMovimiento = $pdo->prepare("
                CALL p_registrar_movimiento(
                    ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmtMovimiento->execute([
                $item->id_producto,
                $data->id,
                'Salida',
                $item->cantidad,
                $item->precio,
                $data->nombre,
                $item->id_inventario,
                'Actualización venta N° ' . $data->id
            ]);

            $stmtMovimiento->closeCursor();
        }

        //---------------------------------------------------
        // CONFIRMAR TODOS LOS CAMBIOS
        //---------------------------------------------------

        $pdo->commit();

        $result = [
            'STATUS' => true,
            'numero' => $data->id,
            'valor_total' => $valorTotal,
            'total_pagado' => $totalPagado,
            'monto_pendiente' => $pendiente,
            'messaje' => 'Venta actualizada correctamente.'
        ];

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $result = [
            'STATUS' => false,
            'messaje' => $e->getMessage()
        ];
    }

    $response->getBody()->write(
        json_encode(
            $result,
            JSON_UNESCAPED_UNICODE
        )
    );

    return $response->withHeader(
        'Content-Type',
        'application/json; charset=utf-8'
    );
});

/*eliminar pago de venta*/

$app->delete(
    '/venta-pago/{idVenta}/{idPago}',
    function (
        Request $request,
        Response $response,
        array $args
    ) use ($pdo) {

        $idVenta = (int) ($args['idVenta'] ?? 0);
        $idPago  = (int) ($args['idPago'] ?? 0);

        try {

            if ($idVenta <= 0 || $idPago <= 0) {
                throw new Exception('Los identificadores son inválidos.');
            }

            $pdo->beginTransaction();

            //---------------------------------------------------
            // OBTENER Y BLOQUEAR LA VENTA
            //---------------------------------------------------

            $stmtVenta = $pdo->prepare("
                SELECT id, valor_total
                FROM ventas
                WHERE id = ?
                FOR UPDATE
            ");

            $stmtVenta->execute([$idVenta]);

            $venta = $stmtVenta->fetch(PDO::FETCH_OBJ);

            if (!$venta) {
                throw new Exception('La venta no existe.');
            }

            $valorTotal = round((float) $venta->valor_total, 2);

            //---------------------------------------------------
            // VERIFICAR QUE EL PAGO EXISTA
            //---------------------------------------------------

            /*
             * Cambia `id` por el nombre real de la llave
             * primaria de venta_pagos, por ejemplo:
             *
             * id_pago
             * id_venta_pago
             */

            $stmtPago = $pdo->prepare("
                SELECT id, monto
                FROM venta_pagos
                WHERE id = ?
                  AND id_venta = ?
                FOR UPDATE
            ");

            $stmtPago->execute([
                $idPago,
                $idVenta
            ]);

            $pago = $stmtPago->fetch(PDO::FETCH_OBJ);

            if (!$pago) {
                throw new Exception(
                    'El pago no existe o no pertenece a la venta.'
                );
            }

            //---------------------------------------------------
            // ELIMINAR EL PAGO SELECCIONADO
            //---------------------------------------------------

            $stmtEliminarPago = $pdo->prepare("
                DELETE FROM venta_pagos
                WHERE id = ?
                  AND id_venta = ?
            ");

            $stmtEliminarPago->execute([
                $idPago,
                $idVenta
            ]);

            if ($stmtEliminarPago->rowCount() === 0) {
                throw new Exception('No se pudo eliminar el pago.');
            }

            //---------------------------------------------------
            // OBTENER LOS PAGOS QUE QUEDARON
            //---------------------------------------------------

            /*
             * Es importante ordenar los pagos cronológicamente.
             * Aquí se usa `id`, pero puedes usar fecha_registro
             * si esa columna existe.
             */

            $stmtPagos = $pdo->prepare("
                SELECT id, monto
                FROM venta_pagos
                WHERE id_venta = ?
                ORDER BY id ASC
                FOR UPDATE
            ");

            $stmtPagos->execute([$idVenta]);

            $pagosRestantes = $stmtPagos->fetchAll(PDO::FETCH_OBJ);

            //---------------------------------------------------
            // RECALCULAR MONTO_PENDIENTE DE CADA PAGO
            //---------------------------------------------------

            $saldoPendiente = $valorTotal;
            $totalPagado = 0;

            $stmtActualizarPago = $pdo->prepare("
                UPDATE venta_pagos
                SET monto_pendiente = ?
                WHERE id = ?
                  AND id_venta = ?
            ");

            foreach ($pagosRestantes as $pagoRestante) {

                $montoPago = round(
                    (float) $pagoRestante->monto,
                    2
                );

                $totalPagado += $montoPago;

                $saldoPendiente = round(
                    $saldoPendiente - $montoPago,
                    2
                );

                // No guardar saldos negativos.
                $saldoPago = max(0, $saldoPendiente);

                $stmtActualizarPago->execute([
                    $saldoPago,
                    $pagoRestante->id,
                    $idVenta
                ]);
            }

            //---------------------------------------------------
            // CALCULAR SALDO FINAL DE LA VENTA
            //---------------------------------------------------

            $montoPendienteVenta = round(
                $valorTotal - $totalPagado,
                2
            );

            $montoPendienteVenta = max(
                0,
                $montoPendienteVenta
            );

            //---------------------------------------------------
            // ACTUALIZAR MONTO_PENDIENTE EN VENTAS
            //---------------------------------------------------

            $stmtActualizarVenta = $pdo->prepare("
                UPDATE ventas
                SET monto_pendiente = ?
                WHERE id = ?
            ");

            $stmtActualizarVenta->execute([
                $montoPendienteVenta,
                $idVenta
            ]);

            //---------------------------------------------------
            // CONFIRMAR TRANSACCIÓN
            //---------------------------------------------------

            $pdo->commit();

            $result = [
                'STATUS'          => true,
                'id_venta'        => $idVenta,
                'id_pago'         => $idPago,
                'valor_total'     => $valorTotal,
                'total_pagado'    => round($totalPagado, 2),
                'monto_pendiente' => $montoPendienteVenta,
                'messaje'         => 'Pago eliminado y saldos recalculados correctamente.'
            ];

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $result = [
                'STATUS'  => false,
                'messaje' => $e->getMessage()
            ];
        }

        $response->getBody()->write(
            json_encode(
                $result,
                JSON_UNESCAPED_UNICODE
            )
        );

        return $response->withHeader(
            'Content-Type',
            'application/json; charset=utf-8'
        );
    }
);

$app->delete(
    '/venta-detalle/{idVenta}/{idDetalle}',
    function (
        Request $request,
        Response $response,
        array $args
    ) use ($pdo) {

        $idVenta   = (int)($args['idVenta'] ?? 0);
        $idDetalle = (int)($args['idDetalle'] ?? 0);

        try {

            if ($idVenta <= 0 || $idDetalle <= 0) {
                throw new Exception("Identificadores inválidos");
            }

            $pdo->beginTransaction();

            //---------------------------------------------------
            // 1. BLOQUEAR VENTA
            //---------------------------------------------------

            $stmtVenta = $pdo->prepare("
                SELECT
                    id,
                    valor_total,
                    id_sucursal
                FROM ventas
                WHERE id = ?
                FOR UPDATE
            ");

            $stmtVenta->execute([$idVenta]);

            $venta = $stmtVenta->fetch(PDO::FETCH_OBJ);

            if (!$venta) {
                throw new Exception("La venta no existe");
            }


            //---------------------------------------------------
            // 2. OBTENER DETALLE
            //---------------------------------------------------

            $stmtDetalle = $pdo->prepare("
                SELECT
                    id,
                    id_producto,
                    cantidad,
                    pendiente,
                    precio,
                    subtotal
                FROM venta_detalle
                WHERE id = ?
                  AND id_venta = ?
                FOR UPDATE
            ");

            $stmtDetalle->execute([
                $idDetalle,
                $idVenta
            ]);

            $detalle = $stmtDetalle->fetch(PDO::FETCH_OBJ);

            if (!$detalle) {
                throw new Exception(
                    "El producto no existe en la venta"
                );
            }


            //---------------------------------------------------
            // 3. CALCULAR CANTIDAD DESPACHADA
            //---------------------------------------------------

            $cantidadDespachada = round(
                (float)$detalle->cantidad -
                (float)$detalle->pendiente,
                2
            );


            //---------------------------------------------------
            // 4. DEVOLVER STOCK
            //---------------------------------------------------

            if ($cantidadDespachada > 0) {

                $stmtStock = $pdo->prepare("
                    UPDATE inventario
                    SET
                        cantidad = cantidad + ?,
                        fecha_actualizacion = NOW()
                    WHERE producto_id = ?
                      AND id_almacen = ?
                ");

                $stmtStock->execute([
                    $cantidadDespachada,
                    $detalle->id_producto,
                    $venta->id_sucursal
                ]);


                //---------------------------------------------------
                // 5. REGISTRAR MOVIMIENTO DE INVENTARIO
                //---------------------------------------------------

                $stmtMov = $pdo->prepare("
                    CALL p_registrar_movimiento(
                        ?,?,?,?,?,?,?,?
                    )
                ");

                $stmtMov->execute([
                    $detalle->id_producto,
                    $idVenta,
                    'Ingreso',
                    $cantidadDespachada,
                    $detalle->precio,
                    'Sistema',
                    $venta->id_sucursal,
                    'Devolución por eliminación de producto venta #' . $idVenta
                ]);

                $stmtMov->closeCursor();
            }


            //---------------------------------------------------
            // 6. ELIMINAR DETALLE
            //---------------------------------------------------

            $stmtEliminar = $pdo->prepare("
                DELETE FROM venta_detalle
                WHERE id = ?
                  AND id_venta = ?
            ");

            $stmtEliminar->execute([
                $idDetalle,
                $idVenta
            ]);

            if ($stmtEliminar->rowCount() === 0) {
                throw new Exception(
                    "No se pudo eliminar el detalle"
                );
            }


            //---------------------------------------------------
            // 7. CALCULAR NUEVO TOTAL
            //---------------------------------------------------

            $stmtTotal = $pdo->prepare("
                SELECT
                    COALESCE(SUM(subtotal), 0) AS nuevo_total
                FROM venta_detalle
                WHERE id_venta = ?
            ");

            $stmtTotal->execute([$idVenta]);

            $rowTotal = $stmtTotal->fetch(PDO::FETCH_OBJ);

            $nuevoTotal = round(
                (float)$rowTotal->nuevo_total,
                2
            );


            //---------------------------------------------------
            // 8. OBTENER PAGOS
            //---------------------------------------------------

            $stmtPagos = $pdo->prepare("
                SELECT
                    id,
                    monto
                FROM venta_pagos
                WHERE id_venta = ?
                ORDER BY id ASC
                FOR UPDATE
            ");

            $stmtPagos->execute([$idVenta]);

            $pagos = $stmtPagos->fetchAll(PDO::FETCH_OBJ);


            //---------------------------------------------------
            // 9. RECALCULAR PENDIENTE DE CADA PAGO
            //---------------------------------------------------

            $saldo = $nuevoTotal;
            $totalPagado = 0;

            $stmtActualizarPago = $pdo->prepare("
                UPDATE venta_pagos
                SET monto_pendiente = ?
                WHERE id = ?
                  AND id_venta = ?
            ");

            foreach ($pagos as $pago) {

                $montoPago = round(
                    (float)$pago->monto,
                    2
                );

                $totalPagado += $montoPago;

                $saldo = round(
                    $saldo - $montoPago,
                    2
                );

                $montoPendientePago = max(
                    0,
                    $saldo
                );

                $stmtActualizarPago->execute([
                    $montoPendientePago,
                    $pago->id,
                    $idVenta
                ]);
            }


            //---------------------------------------------------
            // 10. CALCULAR PENDIENTE DE LA VENTA
            //---------------------------------------------------

            $montoPendienteVenta = max(
                0,
                round(
                    $nuevoTotal - $totalPagado,
                    2
                )
            );


            //---------------------------------------------------
            // 11. ACTUALIZAR VENTA
            //---------------------------------------------------

            $stmtUpdateVenta = $pdo->prepare("
                UPDATE ventas
                SET
                    valor_total = ?,
                    monto_pendiente = ?
                WHERE id = ?
            ");

            $stmtUpdateVenta->execute([
                $nuevoTotal,
                $montoPendienteVenta,
                $idVenta
            ]);


            //---------------------------------------------------
            // 12. CONFIRMAR
            //---------------------------------------------------

            $pdo->commit();


            //---------------------------------------------------
            // RESPUESTA
            //---------------------------------------------------

            $result = [

                "STATUS" => true,
                "id_venta" => $idVenta,
                "detalle_eliminado" => $idDetalle,
                "valor_total" => $nuevoTotal,
                "total_pagado" => round(
                    $totalPagado,
                    2
                ),
                "monto_pendiente" => $montoPendienteVenta,
                "messaje" =>
                    "Producto eliminado y venta recalculada correctamente"

            ];


        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $result = [

                "STATUS" => false,

                "messaje" => $e->getMessage()

            ];
        }


        $response->getBody()->write(
            json_encode(
                $result,
                JSON_UNESCAPED_UNICODE
            )
        );

        return $response->withHeader(
            'Content-Type',
            'application/json; charset=utf-8'
        );
    }
);

$app->get('/venta/{id}', function (Request $request, Response $response, $args) use ($pdo) {

    $id = $args['id'];

    $sql = "SELECT a.nombre, d.*
            FROM venta_detalle d
            INNER JOIN productos a ON a.id = d.id_producto
            WHERE d.id_venta = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);

    $prods = $stmt->fetchAll();

    $response->getBody()->write(json_encode($prods));

    return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/pagos/{id}', function (Request $request, Response $response, $args) use ($pdo) {

    $id = $args['id'];

    $sql = "SELECT p.*, c.nombre AS caja
            FROM venta_pagos p
            /*INNER JOIN tipoPago tp ON p.tipoPago = tp.id*/
            INNER JOIN cajas c ON p.cuentaPago = c.id
            WHERE p.id_venta = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);

    $pagos = $stmt->fetchAll();

    $response->getBody()->write(json_encode($pagos));

    return $response->withHeader('Content-Type', 'application/json');
});


$app->post('/observacioncompra', function (Request $request, Response $response) use ($pdo) {

    $j = json_decode($request->getBody()->getContents(), true);

    try {

        $sql = "UPDATE compras SET observacion = :observacion WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':observacion' => $j['observacion'],
            ':id' => $j['id']
        ]);

        $result = [
            "STATUS" => true,
            "messaje" => "Observación registrada"
        ];

    } catch (PDOException $e) {

        $result = [
            "STATUS" => false,
            "messaje" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/observacion', function (Request $request, Response $response) use ($pdo) {

    $j = json_decode($request->getBody()->getContents(), true);

    try {

        $sql = "UPDATE ventas SET observacion = :observacion WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':observacion' => $j['observacion'],
            ':id' => $j['id']
        ]);

        $result = [
            "STATUS" => true,
            "messaje" => "Observación registrada"
        ];

    } catch (PDOException $e) {

        $result = [
            "STATUS" => false,
            "messaje" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));

    return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/compra/{id}', function (Request $request, Response $response, $args) use ($pdo) {

    $id = $args['id'];

    $sql = "SELECT a.nombre, d.*
            FROM compra_detalle d
            INNER JOIN productos a ON a.id = d.id_producto
            WHERE d.id_compra = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);

    $prods = $stmt->fetchAll();

    $response->getBody()->write(json_encode($prods));

    return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/pagos-compra/{id}', function (Request $request, Response $response, $args) use ($pdo) {

    $id = $args['id'];

    $sql = "SELECT p.*, tp.nombre, c.nombre AS caja
            FROM compra_pagos p
            INNER JOIN tipoPago tp ON p.tipoPago = tp.id
            INNER JOIN cajas c ON p.cuentaPago = c.id
            WHERE p.id_compra = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);

    $pagos = $stmt->fetchAll();

    $response->getBody()->write(json_encode($pagos));

    return $response->withHeader('Content-Type', 'application/json');
});


$app->get('/sucursalusuario/{id}', function (Request $request, Response $response, $args) use ($pdo) {

    $id = $args['id'];

    $sql = "SELECT s.*
            FROM permisos p
            INNER JOIN sucursales s ON p.id_sucursal = s.id
            INNER JOIN usuarios u ON p.id_usuario = u.id
            WHERE p.id_usuario = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);

    $sucursales = $stmt->fetchAll();

    $response->getBody()->write(json_encode($sucursales));

    return $response->withHeader('Content-Type', 'application/json');
});


$app->post('/compra', function (Request $request, Response $response) use ($pdo) {

    $j = json_decode($request->getBody()->getContents(), true);

    $data = json_decode($j['json']);
    $detalle = json_decode($j['detalle']);

    $fecha = substr($data->fecha, 0, 10);

    $pendiente = ($data->montopendiente < 0) ? 0 : $data->montopendiente;

    try {

        // 🔹 Transacción
        $pdo->beginTransaction();

        // 🔹 Registrar compra
        $stmt = $pdo->prepare("CALL p_compra(?,?,?,?,?,?,?,?,?,?,?, ?,?)");
        $stmt->execute([
            $data->usuario,
            $data->seriedoc,
            $data->nrodocumento,
            $fecha,
            $data->proveedor,
            $data->sucursal,
            $data->entrega,
            $data->tipoDoc,
            $data->neto,
            $data->total,
            $pendiente,
            ($data->total - $data->neto),
            $data->comentario
        ]);
        $stmt->closeCursor();

        // 🔹 Obtener ID (mejor si tu SP lo devuelve)
        $ultimo_id = $pdo->query("SELECT MAX(id) AS ultimo_id FROM compras")->fetch();

        // 🔹 Pagos
        $valor_total = $data->total;

        foreach ($data->pagos as $pago) {

            $valor_total -= $pago->montoPago;

            if ($valor_total < 0) {
                $pago->montoPago += $data->montopendiente;
                $valor_total = 0;
            }

            $stmtP = $pdo->prepare("CALL p_compra_pago(?,?,?,?,?,?,?)");
            $stmtP->execute([
                $ultimo_id->ultimo_id,
                '',
                $pago->numero,
                $pago->cuentaPago,
                $pago->montoPago,
                (count($data->pagos) == 1) ? $pendiente : $valor_total,
                $data->usuario
            ]);
            $stmtP->closeCursor();
        }

        // 🔹 Detalle + Inventario + Movimiento
        foreach ($detalle as $item) {
            // detalle
            $stmtD = $pdo->prepare("CALL p_compra_detalle(?,?,?,?,?,?,?,?,?)");
            $stmtD->execute([
                $ultimo_id->ultimo_id,
                $item->id,
                $item->id,
                $item->codigo,
                '',
                $item->cantidad,
                $item->pendiente,
                $item->descuento,
                $item->precio
            ]);
            $stmtD->closeCursor();

            // inventario
            $stmtInv = $pdo->prepare("
                UPDATE inventario
                SET cantidad = cantidad + (? - ?),
                    fecha_actualizacion = NOW()
                WHERE producto_id = ? AND id_almacen = ?
            ");
            $stmtInv->execute([
                $item->cantidad,
                $item->pendiente,
                $item->id,
                $data->sucursal
            ]);

            // movimiento
            $stmtMov = $pdo->prepare("CALL p_registrar_movimiento(?,?,?,?,?,?,?,?)");
            $stmtMov->execute([
                $item->id,
                $ultimo_id->ultimo_id,
                'Ingreso',
                ($item->cantidad - $item->pendiente),
                $item->precio,
                $data->usuario,
                $data->sucursal,
                'Compra nro:'.$ultimo_id->ultimo_id.' Tipo Doc:'.$data->tipoDoc.' Nro:'.$data->nrodocumento.' Serie:'.$data->seriedoc
            ]);
            $stmtMov->closeCursor();
        }

        // 🔹 Commit
        $pdo->commit();

        $result = [
            "STATUS" => true,
            "messaje" => "Compra registrada correctamente con el número: " . $ultimo_id->ultimo_id
        ];

    } catch (Exception $e) {

        $pdo->rollBack();

        $result = [
            "STATUS" => false,
            "messaje" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/actualiza-monto', function (
    Request $request,
    Response $response
) use ($pdo) {

    $result = [
        'STATUS' => false,
        'messaje' => 'No se pudo procesar el pago'
    ];

    try {
        /*
         * Si Angular envía:
         * { "json": "{\"id_venta\":1,...}" }
         */
        $body = json_decode(
            $request->getBody()->getContents(),
            true
        );

        if (!isset($body['json'])) {
            throw new Exception('No se recibió el parámetro json');
        }

        $data = json_decode($body['json']);

        if (json_last_error() !== JSON_ERROR_NONE || !$data) {
            throw new Exception('El JSON recibido no es válido');
        }

        if (
            !isset(
                $data->id_venta,
                $data->monto,
                $data->numero,
                $data->cuenta_pago,
                $data->usuario
            )
        ) {
            throw new Exception('Faltan datos obligatorios');
        }

        $idVenta = (int) $data->id_venta;
        $montoPago = round((float) $data->monto, 2);

        if ($idVenta <= 0) {
            throw new Exception('El identificador de la venta no es válido');
        }

        if ($montoPago <= 0) {
            throw new Exception('El monto debe ser mayor que cero');
        }

        $pdo->beginTransaction();

        /*
         * Se obtiene directamente el saldo de la venta.
         * FOR UPDATE evita pagos simultáneos sobre el mismo saldo.
         */
        $stmtVenta = $pdo->prepare(
            'SELECT monto_pendiente
             FROM ventas
             WHERE id = :id_venta
             FOR UPDATE'
        );

        $stmtVenta->execute([
            'id_venta' => $idVenta
        ]);

        $venta = $stmtVenta->fetch(PDO::FETCH_ASSOC);

        if (!$venta) {
            throw new Exception('No se encontró la venta');
        }

        $montoPendiente = round(
            (float) ($venta['monto_pendiente'] ?? 0),
            2
        );

        if ($montoPendiente <= 0) {
            throw new Exception('La venta ya no tiene monto pendiente');
        }

        if ($montoPago > $montoPendiente) {
            throw new Exception(
                'La cantidad ingresada es mayor al saldo pendiente'
            );
        }

        $nuevoMonto = round(
            $montoPendiente - $montoPago,
            2
        );

        $stmtInsert = $pdo->prepare(
            'INSERT INTO venta_pagos (
                id_venta,
                tipoPago,
                numero_operacion,
                cuentaPago,
                monto,
                monto_pendiente,
                estado,
                usuario
            ) VALUES (
                :id_venta,
                :tipo_pago,
                :numero_operacion,
                :cuenta_pago,
                :monto,
                :monto_pendiente,
                1,
                :usuario
            )'
        );

        $stmtInsert->execute([
            'id_venta' => $idVenta,
            'tipo_pago' => $data->tipo_pago ?? '',
            'numero_operacion' => $data->numero,
            'cuenta_pago' => $data->cuenta_pago,
            'monto' => $montoPago,
            'monto_pendiente' => $nuevoMonto,
            'usuario' => $data->usuario
        ]);

        $stmtUpdate = $pdo->prepare(
            'UPDATE ventas
             SET monto_pendiente = :monto_pendiente
             WHERE id = :id_venta'
        );

        $stmtUpdate->execute([
            'monto_pendiente' => $nuevoMonto,
            'id_venta' => $idVenta
        ]);

        $pdo->commit();

        $result = [
            'STATUS' => true,
            'messaje' => $nuevoMonto == 0
                ? 'La venta fue cancelada completamente'
                : 'Pago registrado correctamente',
            'monto_pagado' => $montoPago,
            'monto_pendiente' => $nuevoMonto
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $result = [
            'STATUS' => false,
            'messaje' => $e->getMessage()
        ];
    }

    $response->getBody()->write(
        json_encode(
            $result,
            JSON_UNESCAPED_UNICODE
        )
    );

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($result['STATUS'] ? 200 : 400);
});


$app->get('/buscarclientes/{criterio}', function (Request $request, Response $response, $args) use ($pdo) {

    $criterio = $args['criterio'];

    try {

        $stmt = $pdo->prepare("
            SELECT id,nombre,num_documento
            FROM clientes
            WHERE nombre LIKE :criterio
               OR num_documento LIKE :criterio
        ");

        $like = "%{$criterio}%";

        $stmt->execute([
            ':criterio' => $like
        ]);

        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $respuesta = json_encode($prods);

    } catch (PDOException $e) {

        $respuesta = json_encode([
            "status" => false,
            "message" => $e->getMessage()
        ]);
    }

    $response->getBody()->write($respuesta);

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});


$app->get('/buscarproveedor/{criterio}', function (Request $request, Response $response, $args) use ($pdo) {

    $criterio = $args['criterio'];

    try {

        $stmt = $pdo->prepare("
            SELECT *
            FROM proveedores
            WHERE razon_social LIKE :criterio
               OR num_documento LIKE :criterio
        ");

        $like = "%{$criterio}%";

        $stmt->execute([
            'criterio' => $like
        ]);

        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $respuesta = json_encode($prods);

    } catch (PDOException $e) {

        $respuesta = json_encode([
            "status" => false,
            "message" => $e->getMessage()
        ]);
    }

    $response->getBody()->write($respuesta);

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});


$app->post('/actualiza-pendiente-venta', function (Request $request, Response $response) use ($pdo) {

    $data = json_decode($request->getBody()->getContents(), true);
    $data = json_decode($data['json']);

    try {

        // Obtener detalle de venta
        $stmt = $pdo->prepare("
            SELECT d.*
            FROM venta_detalle d
            WHERE id = :id AND id_venta = :id_venta
        ");

        $stmt->execute([
            'id' => $data->id,
            'id_venta' => $data->id_venta
        ]);

        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            throw new Exception("No se encontró el detalle de la venta");
        }

        $pendiente = number_format($prod['pendiente'], 2, '.', '');

        // Definir cantidad
        if ($data->cantidad == 0) {
            $cantidad = $pendiente;
        } else {
            $cantidad = $data->cantidad;
        }

        // 🔥 IMPORTANTE: usar transacción (antes no tenías control de errores reales)
        $pdo->beginTransaction();

        // Registrar movimiento (CALL)
        $stmtCall = $pdo->prepare("CALL p_registrar_movimiento(
            :id_producto,
            :id_venta,
            :salida,
            :cantidad,
            :precio,
            :usuario,
            :sucursal,
            :comentario
        )");

        $stmtCall->execute([
            'id_producto' => $data->id_producto,
            'id_venta' => $data->id_venta,
            'salida'=>'Salida',
            'cantidad' => $cantidad,
            'precio' => $prod['precio'],
            'usuario' => $data->usuario,
            'sucursal' => $data->sucursal,
            'comentario'=>'Entrega pendiente producto '.$data->id_producto.' de venta '.$data->id_venta
        ]);

        $stmtCall->closeCursor();
        // Actualizar pendiente
        $stmtUpdate = $pdo->prepare("
            UPDATE venta_detalle
            SET pendiente = :pendiente, usuario = :usuario
            WHERE id_venta = :id_venta AND id_producto = :id_producto
        ");

        $stmtUpdate->execute([
            'pendiente' => $data->cantidad,
            'usuario' => $data->usuario,
            'id_venta' => $data->id_venta,
            'id_producto' => $data->id_producto
        ]);
        $stmtUpdate->closeCursor();
        // Actualizar inventario
        $stmtInv = $pdo->prepare("
            UPDATE inventario
            SET cantidad = cantidad - :cantidad,
                fecha_actualizacion = NOW()
            WHERE producto_id = :id_producto
              AND id_almacen = :sucursal
        ");

        $stmtInv->execute([
            'cantidad' => $cantidad,
            'id_producto' => $data->id_producto,
            'sucursal' => $data->sucursal
        ]);

        $stmtInv->closeCursor();
        $pdo->commit();

        $result = [
            "STATUS" => true,
            "messaje" => "Pendientes actualizados correctamente"
        ];

    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $result = [
            "STATUS" => false,
            "messaje" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/subcategoria_categoria/{criterio}', function (Request $request, Response $response, $args) use ($pdo) {

    $criterio = $args['criterio'];

    try {

        $stmt = $pdo->prepare("
            SELECT nombre, id
            FROM sub_categorias
            WHERE id_categoria = :criterio
            ORDER BY nombre ASC
        ");

        $stmt->execute([
            'criterio' => $criterio
        ]);

        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($prods));

        return $response->withHeader('Content-Type', 'application/json');

    } catch (PDOException $e) {

        $response->getBody()->write(json_encode([
            "STATUS" => false,
            "message" => $e->getMessage()
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
});



$app->get('/familia_subcategoria/{criterio}', function (Request $request, Response $response, $args) use ($pdo) {

    $criterio = $args['criterio'];

    try {

        $stmt = $pdo->prepare("
            SELECT id, nombre
            FROM sub_sub_categorias
            WHERE id_subcategoria = :criterio
            ORDER BY nombre ASC
        ");

        $stmt->execute([
            'criterio' => $criterio
        ]);

        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($prods));

        return $response->withHeader('Content-Type', 'application/json');

    } catch (PDOException $e) {

        $response->getBody()->write(json_encode([
            "STATUS" => false,
            "message" => $e->getMessage()
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
});


$app->get('/articulo/{id}', function (Request $request, Response $response, $args) use ($pdo) {

    $id = $args['id'];

    try {

        $stmt = $pdo->prepare("
            SELECT p.*, p.id_sub_sub_categoria AS id_familia
            FROM productos p
            WHERE p.id = :id
        ");

        $stmt->execute([
            'id' => $id
        ]);

        $producto = $stmt->fetch(PDO::FETCH_ASSOC);

        // Puedes devolver null o array vacío si no existe
        $response->getBody()->write(json_encode($producto ?: []));

        return $response->withHeader('Content-Type', 'application/json');

    } catch (PDOException $e) {

        $response->getBody()->write(json_encode([
            "STATUS" => false,
            "message" => $e->getMessage()
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
});


$app->get('/sub_categorias', function (Request $request, Response $response) use ($pdo) {

    try {

        $stmt = $pdo->prepare("
            SELECT id, nombre
            FROM sub_categorias
            ORDER BY id ASC
        ");

        $stmt->execute();

        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($prods));

        return $response->withHeader('Content-Type', 'application/json');

    } catch (PDOException $e) {

        $response->getBody()->write(json_encode([
            "STATUS" => false,
            "message" => $e->getMessage()
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
});



$app->get('/familia', function (Request $request, Response $response) use ($pdo) {

    try {

        $stmt = $pdo->prepare("
            SELECT id, nombre
            FROM sub_sub_categorias
            ORDER BY id ASC
        ");

        $stmt->execute();

        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($prods));

        return $response->withHeader('Content-Type', 'application/json');

    } catch (PDOException $e) {

        $response->getBody()->write(json_encode([
            "STATUS" => false,
            "message" => $e->getMessage()
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
});

$app->get('/unidad', function (Request $request, Response $response) use ($pdo) {

    try {

        $stmt = $pdo->prepare("
            SELECT id, codigo, nombre
            FROM unidad
            ORDER BY nombre ASC
        ");

        $stmt->execute();

        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($prods));

        return $response->withHeader('Content-Type', 'application/json');

    } catch (PDOException $e) {

        $response->getBody()->write(json_encode([
            "STATUS" => false,
            "message" => $e->getMessage()
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
});

$app->put('/producto', function ($request, $response) use ($pdo) {

    header("Content-Type: application/json; charset=utf-8");

    $body = $request->getBody()->getContents();

    $j = json_decode($body, true);

    $data = json_decode($j['json']);

    try {

        // GUARDAR IMAGEN
        if (!empty($data->imagen) && !empty($data->nombre_imagen)) {

// GUARDAR IMAGEN
if (!empty($data->imagen) && !empty($data->nombre_imagen)) {

    $archivo = base64_decode($data->imagen);

    $filePath = $_SERVER['DOCUMENT_ROOT'] . "/erp-api/upload/" . $data->nombre_imagen;

    // Crear imagen desde el contenido binario
    $image = @imagecreatefromstring($archivo);

    if (!$image) {
        throw new Exception("La imagen recibida no es válida");
    }

    // Obtener dimensiones originales
    $width = imagesx($image);
    $height = imagesy($image);

    // Redimensionar si supera el ancho máximo
    $maxWidth = 1200;

    if ($width > $maxWidth) {

        $newWidth = $maxWidth;
        $newHeight = intval(($height * $newWidth) / $width);

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Fondo blanco para imágenes PNG transparentes
        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);

        imagecopyresampled(
            $resized,
            $image,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $width,
            $height
        );

        imagedestroy($image);
        $image = $resized;
    }

    // Comprimir hasta llegar a 100 KB aprox.
    $maxSize = 100 * 1024; // 100 KB
    $quality = 90;
    $compressed = null;

    do {

        ob_start();
        imagejpeg($image, null, $quality);
        $compressed = ob_get_clean();

        if (strlen($compressed) <= $maxSize) {
            break;
        }

        $quality -= 5;

    } while ($quality >= 10);

    file_put_contents($filePath, $compressed);

    imagedestroy($image);

    // Para verificar en los logs
    error_log(
        "Imagen guardada: " .
        round(filesize($filePath) / 1024, 2) .
        " KB - Calidad: " . $quality
    );

    $sql = "UPDATE productos SET
                id_categoria = :id_categoria,
                id_subcategoria = :id_subcategoria,
                id_sub_sub_categoria = :id_familia,
                nombre = :nombre,
                codigo = :codigo,
                codigobarras = :codigobarras,
                unidad = :unidad,
                precio = :precio,
                precio_compra = :precio_compra,
                imagen = :imagen
            WHERE id = :id";

    $stmt = $pdo->prepare($sql);

    $proceso = $stmt->execute([
        ':id_categoria' => $data->id_categoria,
        ':id_subcategoria' => $data->id_subcategoria,
        ':id_familia' => $data->id_familia,
        ':nombre' => $data->nombre,
        ':codigo' => $data->codigo,
        ':codigobarras' => $data->codigobarras,
        ':unidad' => $data->unidad,
        ':precio' => $data->precio,
        ':precio_compra' => $data->precio_compra,
        ':imagen' => $data->nombre_imagen,
        ':id' => $data->id
    ]);
}


        } else {

            $sql = "UPDATE productos SET
                        id_categoria = :id_categoria,
                        id_subcategoria = :id_subcategoria,
                        id_sub_sub_categoria = :id_familia,
                        nombre = :nombre,
                        codigo = :codigo,
                        codigobarras = :codigobarras,
                        unidad = :unidad,
                        precio = :precio,
                        precio_compra=:precio_compra
                    WHERE id = :id";

            $stmt = $pdo->prepare($sql);

            $proceso = $stmt->execute([
                ':id_categoria' => $data->id_categoria,
                ':id_subcategoria' => $data->id_subcategoria,
                ':id_familia' => $data->id_familia,
                ':nombre' => $data->nombre,
                ':codigo' => $data->codigo,
                ':codigobarras' => $data->codigobarras,
                ':unidad' => $data->unidad,
                ':precio' => $data->precio,
                ':precio_compra' => $data->precio_compra,
                ':id' => $data->id
            ]);
        }

        if ($proceso) {

            $result = [
                "STATUS" => true,
                "messaje" => "Producto actualizado correctamente"
            ];

        } else {

            $result = [
                "STATUS" => false,
                "messaje" => "Ocurrió un error en la actualización"
            ];
        }

    } catch (PDOException $e) {

        $result = [
            "STATUS" => false,
            "messaje" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

$app->post('/categoria', function ($request, $response) use ($pdo) {

    header("Content-Type: application/json; charset=utf-8");

    $body = $request->getBody()->getContents();

    $j = json_decode($body, true);

    $data = json_decode($j['json']);

    try {

        $nombre = (is_array($data->nombre))
            ? array_shift($data->nombre)
            : $data->nombre;

        $sql = "INSERT INTO categorias (nombre,usuario) VALUES (:nombre,'admin')";

        $stmt = $pdo->prepare($sql);

        $proceso = $stmt->execute([
            ':nombre' => $nombre
        ]);

        if ($proceso) {

            $result = [
                "STATUS" => true,
                "messaje" => "Categoria creada correctamente"
            ];

        } else {

            $result = [
                "STATUS" => false,
                "messaje" => "Ocurrió un error en la creación"
            ];
        }

    } catch (PDOException $e) {

        $result = [
            "STATUS" => false,
            "messaje" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});



$app->post('/producto', function (Request $request, Response $response) use ($pdo) {

    $data = json_decode($request->getBody()->getContents(), true);
    $data = json_decode($data['json']);

    try {

        // 🔥 Validación básica
        if (empty($data->nombre) || empty($data->codigo)) {
            throw new Exception("Nombre y código son obligatorios");
        }

        // ---- Guardar imagen ----
        if (!empty($data->imagen) && !empty($data->nombre_imagen)) {

            $archivo = base64_decode($data->imagen);
            $filePath = $_SERVER['DOCUMENT_ROOT'] . "/erp-api/upload/" . $data->nombre_imagen;

            // Crear imagen desde el contenido binario
            $image = imagecreatefromstring($archivo);

            if ($image !== false) {

                $quality = 90;

                do {
                    ob_start();
                    imagejpeg($image, null, $quality);
                    $compressedData = ob_get_clean();

                    $sizeKB = strlen($compressedData) / 1024;
                    $quality -= 5;

                } while ($sizeKB > 100 && $quality > 10);

                file_put_contents($filePath, $compressedData);

                imagedestroy($image);
            }
        }

        // 🔥 Transacción (CLAVE)
        $pdo->beginTransaction();

        // ---- Insert producto ----
        $stmt = $pdo->prepare("
            INSERT INTO productos
            (id_categoria, id_subcategoria, id_sub_sub_categoria, codigo, codigobarras, nombre, unidad, precio,precio_compra, imagen)
            VALUES
            (:categoria, :subcategoria, :familia, :codigo, :codigobarras, :nombre, :unidad, :precio,:precio_compra, :imagen)
        ");

        $stmt->execute([
            'categoria'     => $data->id_categoria,
            'subcategoria'  => $data->id_subcategoria,
            'familia'       => $data->id_familia,
            'codigo'        => $data->codigo,
            'codigobarras'  => $data->codigobarras,
            'nombre'        => $data->nombre,
            'unidad'        => $data->unidad,
            'precio'        => $data->precio,
            'precio_compra' => $data->precio_compra,
            'imagen'        => $data->nombre_imagen ?? null
        ]);

        // ✅ Obtener ID correcto (NO uses MAX(id))
        $ultimo_id = $pdo->lastInsertId();

        // ---- Inventario inicial ----
        $stmtInv = $pdo->prepare("
            INSERT INTO inventario (producto_id, id_almacen, cantidad, comentario)
            VALUES (:producto_id, :almacen, 0, 'carga inicial')
        ");

        $stmtInv->execute(['producto_id' => $ultimo_id, 'almacen' => 1]);
        $stmtInv->execute(['producto_id' => $ultimo_id, 'almacen' => 2]);

        // ---- Movimientos iniciales ----
        $stmtMov = $pdo->prepare("
            INSERT INTO movimiento_articulos
            (codigo_prod, tipo_movimiento, cantidad_ingreso, cantidad_salida, cantidad_acumulada, precio, comentario, id_sucursal, usuario)
            VALUES (:producto_id, 'Ingreso', 0, 0, 0, 0, 'carga inicial', :sucursal, :usuario)
        ");

        $stmtMov->execute(['producto_id' => $ultimo_id, 'sucursal' => 1, 'usuario' => 'admin']);
        $stmtMov->execute(['producto_id' => $ultimo_id, 'sucursal' => 2, 'usuario' => 'admin']);

        $pdo->commit();

        $result = [
            "STATUS" => true,
            "messaje" => "Producto creado correctamente"
        ];

    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $result = [
            "STATUS" => false,
            "messaje" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));

    return $response->withHeader('Content-Type', 'application/json');
});


$app->put('/cliente', function ($request, $response) use ($pdo) {

    header("Content-Type: application/json; charset=utf-8");

    $body = $request->getBody()->getContents();

    $j = json_decode($body, true);

    $data = json_decode($j['json']);

    try {

        $sql = "UPDATE clientes
                SET
                    nombre = :nombre,
                    direccion = :direccion,
                    telefono = :telefono,
                    num_documento = :num_documento,
                    email = :email,
                    departamento = :departamento,
                    provincia = :provincia,
                    distrito = :distrito
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':nombre' => $data->nombre,
            ':direccion' => $data->direccion,
            ':telefono' => $data->telefono,
            ':num_documento' => $data->num_documento,
            ':email' => $data->email,
            ':departamento' => $data->departamento,
            ':provincia' => $data->provincia,
            ':distrito' => $data->distrito,
            ':id' => $data->id
        ]);

        $result = [
            "STATUS" => true,
            "messaje" => "Cliente actualizado correctamente"
        ];

    } catch (PDOException $e) {

        $result = [
            "STATUS" => false,
            "messaje" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});




$app->post('/cliente', function (Request $request, Response $response) use ($pdo) {

    $body = $request->getBody()->getContents();
    $j = json_decode($body, true);

    // Validación básica
    if (!isset($j['json'])) {
        $response->getBody()->write(json_encode([
            "STATUS" => false,
            "messaje" => "No se recibió el JSON correctamente"
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    $data = json_decode($j['json']);

    try {

        $sql = "INSERT INTO clientes
                (num_documento, nombre, telefono, direccion, email, departamento, provincia, distrito, estado)
                VALUES (:num_documento, :nombre, :telefono, :direccion, :email, :departamento, :provincia, :distrito, 1)";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            ':num_documento' => $data->num_documento,
            ':nombre' => $data->nombre,
            ':telefono' => $data->telefono,
            ':direccion' => $data->direccion,
            ':email' => isset($data->email) ?$data->email :'',
            ':departamento' => isset($data->departamento) ?$data->departamento :'',
            ':provincia' => isset($data->provincia) ? $data->provincia :'',
            ':distrito' =>  isset($data->distrito) ?$data->distrito :'',
        ]);

        $result = [
            "STATUS" => true,
            "messaje" => "Cliente registrado correctamente"
        ];

    } catch (PDOException $e) {

        $result = [
            "STATUS" => false,
            "messaje" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));

    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/anular', function (Request $request, Response $response) use ($pdo) {

    $body = $request->getBody()->getContents();
    $j = json_decode($body, true);
    $data = json_decode($j['json'], true);

    try {

        $id = $data['datos']['id'];
        $estado = $data['datos']['estado'];
        $id_sucursal = $data['datos']['id_sucursal'];

        if ($estado != 'Anulado') {

            // 🔥 1. Anular venta
            $stmt = $pdo->prepare("UPDATE ventas SET estado = 2 WHERE id = :id");
            $stmt->execute([':id' => $id]);

            // 🔥 2. Obtener detalle
            $stmt = $pdo->prepare("SELECT * FROM venta_detalle WHERE id_venta = :id");
            $stmt->execute([':id' => $id]);

            $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($prods as $fila) {

                // 🔥 3. Movimiento artículos
                $stmt = $pdo->prepare("
                    INSERT INTO movimiento_articulos
                    (codigo_prod, id_venta, tipo_movimiento, id_almacen, cantidad_ingreso, precio, comentario, id_sucursal, usuario)
                    VALUES (:codigo_prod, :id_venta, 'Ingreso', :id_almacen, :cantidad, :precio, 'Venta anulada', :id_sucursal, 'admin')
                ");

                $stmt->execute([
                    ':codigo_prod' => $fila['id_producto'],
                    ':id_venta' => $fila['id_venta'],
                    ':id_almacen' => $fila['id_inventario'],
                    ':cantidad' => ($fila['cantidad'] - $fila['pendiente']),
                    ':precio' => $fila['precio'],
                    ':id_sucursal' => $id_sucursal
                ]);

                // 🔥 4. Actualizar inventario
                $stmt = $pdo->prepare("
                    UPDATE inventario
                    SET cantidad = cantidad + :cantidad, fecha_actualizacion = NOW()
                    WHERE producto_id = :producto_id AND id_almacen = :almacen
                ");

                $stmt->execute([
                    ':cantidad' => $fila['cantidad'],
                    ':producto_id' => $fila['id_producto'],
                    ':almacen' => $id_sucursal
                ]);
            }

            $result = [
                "STATUS" => true,
                "messaje" => "Venta nro $id fue anulada correctamente"
            ];

        } else {

            $result = [
                "STATUS" => true,
                "messaje" => "La venta $id ya está anulada"
            ];
        }

    } catch (Exception $e) {

        $result = [
            "STATUS" => false,
            "message" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));

    return $response->withHeader('Content-Type', 'application/json');
});


$app->post('/del_proveedor', function (Request $request, Response $response) use ($pdo) {

    $body = $request->getBody()->getContents();
    $j = json_decode($body, true);
    $data = json_decode($j['json']);

    try {
        $stmt = $pdo->prepare("DELETE FROM proveedores WHERE id = :id");
        $stmt->bindParam(':id', $data->proveedor->id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            $result = [
                "STATUS" => true,
                "message" => "Proveedor eliminado correctamente"
            ];
        } else {
            $result = [
                "STATUS" => false,
                "message" => "Error al eliminar el proveedor"
            ];
        }

    } catch (PDOException $e) {
        $result = [
            "STATUS" => false,
            "message" => "Error: " . $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');

});


$app->post('/del_cliente', function (Request $request, Response $response) use ($pdo) {

    $body = $request->getBody()->getContents();
    $j = json_decode($body, true);
    $data = json_decode($j['json']);


    try {
        $stmt = $pdo->prepare("DELETE FROM clientes WHERE id = :id");
        $stmt->bindParam(':id', $data->cliente->id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            $result = [
                "STATUS" => true,
                "messaje" => "Cliente eliminado correctamente"
            ];
        } else {
            $result = [
                "STATUS" => false,
                "messaje" => "Error al eliminar el proveedor"
            ];
        }

    } catch (PDOException $e) {
        $result = [
            "STATUS" => false,
            "messaje" => "Error: " . $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');

});

$app->post('/proveedor', function (Request $request, Response $response) use ($pdo) {

    $body = $request->getBody()->getContents();
    $j = json_decode($body, true);
    $data = json_decode($j['json']);

    // Normalizar datos (por si vienen como array o string)
    $getValue = function($field) {
        return is_array($field) ? array_shift($field) : $field;
    };

    $razon_social = $getValue($data->razon_social);
    $direccion    = $getValue($data->direccion);
    $ruc          = $getValue($data->num_documento);
    $departamento = $getValue($data->departamento);
    $provincia    = $getValue($data->provincia);
    $distrito     = $getValue($data->distrito);

    try {

        $stmt = $pdo->prepare("
            INSERT INTO proveedores
            (razon_social, direccion, num_documento, departamento, provincia, distrito)
            VALUES
            (:razon_social, :direccion, :ruc, :departamento, :provincia, :distrito)
        ");

        $stmt->execute([
            ':razon_social' => $razon_social,
            ':direccion'    => $direccion,
            ':ruc'          => $ruc,
            ':departamento' => $departamento,
            ':provincia'    => $provincia,
            ':distrito'     => $distrito
        ]);

        $result = [
            "STATUS" => true,
            "message" => "Proveedor agregado correctamente"
        ];

    } catch (PDOException $e) {

        $result = [
            "STATUS" => false,
            "message" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');

});


$app->post('/actualizar-precios', function ($request, $response) use ($pdo) {

    $data = json_decode($request->getBody()->getContents());

    $sql = "UPDATE productos
            SET precio = :precio
            WHERE codigo = :codigo";

    $stmt = $pdo->prepare($sql);

    foreach ($data->productos as $producto) {

        $stmt->execute([
            ':precio' => $producto->PRECIO,
            ':codigo' => $producto->CODIGO
        ]);
    }

    $response->getBody()->write(json_encode([
        'success' => true
    ]));

    return $response
        ->withHeader('Content-Type', 'application/json');
});

$app->get('/articulos/{criterio}', function (Request $request, Response $response, $args) use ($pdo) {

    $criterio = $args['criterio'];

    try {

        $stmt = $pdo->prepare("
            SELECT *
            FROM productos
            WHERE nombre LIKE :criterio
               OR codigo LIKE :criterio
        ");

        $like = "%{$criterio}%";

        $stmt->execute([
            'criterio' => $like
        ]);

        $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $respuesta = json_encode($prods);

    } catch (PDOException $e) {

        $respuesta = json_encode([
            "status" => false,
            "message" => $e->getMessage()
        ]);
    }

    $response->getBody()->write($respuesta);

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});



$app->post('/agregar-inventario', function (Request $request, Response $response) use ($pdo) {

    $body = $request->getBody()->getContents();
    $j = json_decode($body, true);
    $data = json_decode($j['json']);



    $cantidad_acumulada = 0;

    $stmt = $pdo->prepare("
        SELECT *
        FROM movimiento_articulos
        WHERE codigo_prod = :producto
        AND id_sucursal = :sucursal
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([
        ':producto' => $data->id_producto,
        ':sucursal' => $data->id_sucursal
    ]);

    $inv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        $inv = [
            'precio' => 0,
            'cantidad_acumulada' => 0,
            'cantidad_ingreso' => 0,
            'total' => 0,
            'promedio' => 0
        ];
    }

    if ($data->operacion === 'Ingreso') {

        if ($inv['precio'] == 0 || $inv['cantidad_acumulada'] == 0) {

            $promedio = ($inv['cantidad_acumulada'] == 0)
                ? $data->precio
                : ($data->cantidad / $data->precio);

            $sql = "
                INSERT INTO movimiento_articulos
                (
                    codigo_prod,
                    tipo_movimiento,
                    id_almacen,
                    comentario,
                    cantidad_movimiento,
                    cantidad_ingreso,
                    cantidad_acumulada,
                    precio,
                    promedio,
                    total,
                    id_sucursal,
                    estado,
                    usuario
                )
                VALUES
                (
                    :codigo_prod,
                    :tipo_movimiento,
                    :id_almacen,
                    :comentario,
                    :cantidad_movimiento,
                    :cantidad_ingreso,
                    :cantidad_acumulada,
                    :precio,
                    :promedio,
                    :total,
                    :id_sucursal,
                    :estado,
                    :usuario
                )
            ";

            $params = [
                ':codigo_prod' => $data->id_producto,
                ':tipo_movimiento' => $data->operacion,
                ':id_almacen' => $data->id_sucursal,
                ':comentario' => 'Modificación manual -'.$data->comentario,
                ':cantidad_movimiento' => $data->cantidad,
                ':cantidad_ingreso' => $data->cantidad,
                ':cantidad_acumulada' => $data->cantidad,
                ':precio' => $data->precio,
                ':promedio' => $promedio,
                ':total' => $data->cantidad * $data->precio,
                ':estado'=>'activo',
                ':id_sucursal' => $data->id_sucursal,
                ':usuario' => $data->usuario
            ];

        } else {

            $cantidad_ingreso = $data->cantidad + floatval($inv['cantidad_ingreso']);
            $total = round(
                ($data->cantidad * $data->precio) + floatval($inv['total']),
                2
            );

            if (floatval($inv['cantidad_acumulada']) <= 0) {

                $promedio =
                    (
                        floatval($inv['total']) +
                        ($data->cantidad * $data->precio)
                    )
                    /
                    (
                        floatval($inv['cantidad_acumulada']) +
                        $data->cantidad
                    );

                $cantidad_acumulada =
                    floatval($inv['cantidad_acumulada']) +
                    $data->cantidad;

            } else {

                $promedio =
                    (
                        floatval($inv['total']) +
                        ($data->cantidad * $data->precio)
                    )
                    /
                    (
                        floatval($inv['cantidad_acumulada']) +
                        $data->cantidad
                    );

                $cantidad_ingreso = $data->cantidad;

                $cantidad_acumulada =
                    floatval($inv['cantidad_acumulada']) +
                    $data->cantidad;
            }

            $sql = "
                INSERT INTO movimiento_articulos
                (
                    codigo_prod,
                    tipo_movimiento,
                    id_almacen,
                    comentario,
                    cantidad_movimiento,
                    cantidad_ingreso,
                    cantidad_acumulada,
                    precio,
                    promedio,
                    total,
                    id_sucursal,
                    usuario
                )
                VALUES
                (
                    :codigo_prod,
                    :tipo_movimiento,
                    :id_almacen,
                    :comentario,
                    :cantidad_movimiento,
                    :cantidad_ingreso,
                    :cantidad_acumulada,
                    :precio,
                    :promedio,
                    :total,
                    :id_sucursal,
                    :usuario
                )
            ";

            $params = [
                ':codigo_prod' => $data->id_producto,
                ':tipo_movimiento' => $data->operacion,
                ':id_almacen' => $data->id_sucursal,
                ':comentario' => 'Modificación manual -'.$data->comentario,
                ':cantidad_movimiento' => $data->cantidad,
                ':cantidad_ingreso' => $cantidad_ingreso,
                ':cantidad_acumulada' => $cantidad_acumulada,
                ':precio' => $data->precio,
                ':promedio' => $promedio,
                ':total' => $total,
                ':id_sucursal' => $data->id_sucursal,
                ':usuario' => $data->usuario
            ];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $stmt = $pdo->prepare("
            UPDATE inventario
            SET cantidad = cantidad + :cantidad,
                fecha_actualizacion = NOW()
            WHERE producto_id = :producto
            AND id_almacen = :almacen
        ");

        $stmt->execute([
            ':cantidad' => $data->cantidad,
            ':producto' => $data->id_producto,
            ':almacen' => $data->id_sucursal
        ]);
    }

    if ($data->operacion === 'Salida') {

        if ($inv["precio"] != "0.00") {

            $total = number_format(
                $inv["total"] - ($data->cantidad * $inv["promedio"]),
                2,
                '.',
                ''
            );

            $cantidadAcumulada = $inv["cantidad_acumulada"] - $data->cantidad;

            try {

                $pdo->beginTransaction();

                // Actualizar inventario
                $sqlInventario = "
                    UPDATE inventario
                    SET
                        cantidad = cantidad - :cantidad,
                        fecha_actualizacion = NOW()
                    WHERE
                        producto_id = :producto_id
                    AND id_almacen = :almacen
                ";

                $stmtInventario = $pdo->prepare($sqlInventario);

                $stmtInventario->execute([
                    ':cantidad'    => $data->cantidad,
                    ':producto_id' => $data->id_producto,
                    ':almacen'     => $data->id_sucursal
                ]);

                // Registrar movimiento
                $sqlMovimiento = "
                    INSERT INTO movimiento_articulos
                    (
                        codigo_prod,
                        tipo_movimiento,
                        id_almacen,
                        comentario,
                        cantidad_movimiento,
                        cantidad_salida,
                        cantidad_acumulada,
                        precio,
                        promedio,
                        total,
                        id_sucursal,
                        usuario
                    )
                    VALUES
                    (
                        :codigo_prod,
                        :tipo_movimiento,
                        :id_almacen,
                        :comentario,
                        :cantidad_movimiento,
                        :cantidad_salida,
                        :cantidad_acumulada,
                        :precio,
                        :promedio,
                        :total,
                        :id_sucursal,
                        :usuario
                    )
                ";

                $stmtMovimiento = $pdo->prepare($sqlMovimiento);

                $stmtMovimiento->execute([
                    ':codigo_prod'         => $data->id_producto,
                    ':tipo_movimiento'     => $data->operacion,
                    ':id_almacen'          => $data->id_sucursal,
                    ':comentario'          => 'Modificación manual -'.$data->comentario,
                    ':cantidad_movimiento' => $data->cantidad,
                    ':cantidad_salida'     => -$data->cantidad,
                    ':cantidad_acumulada'  => $cantidadAcumulada,
                    ':precio'              => $inv["promedio"],
                    ':promedio'            => $inv["promedio"],
                    ':total'               => $total,
                    ':id_sucursal'         => $data->id_sucursal,
                    ':usuario'             => $data->usuario
                ]);

                $pdo->commit();

            } catch (PDOException $e) {

                $pdo->rollBack();

                throw $e;

            }

        }

    }


    $result = [
        'STATUS' => true,
        'messaje' => 'Inventario registrado correctamente'
    ];

    $response->getBody()->write(json_encode($result));

    return $response
        ->withHeader('Content-Type', 'application/json');
});


$app->post('/movimiento-kardex',function(Request $request,Response $response) use ($pdo){

    $body = $request->getBody()->getContents();
    $j = json_decode($body, true);
    $data = json_decode($j['json']);
    $detalle = json_decode($j['detalle']);
 // 🔹 Obtener ID (mejor si tu SP lo devuelve)
//$ultimo_id = $pdo->query("SELECT MAX(id) AS ultimo_id FROM movimiento_articulos")->fetch();
// movimiento
$tipo='';
$cantidad=0;
if($detalle->tipo_movimiento=='Salida'){
    $tipo='Ingreso';
     $cantidad = abs($detalle->cantidad_movimiento);
     $stmtInv = $pdo->prepare("
     UPDATE inventario  SET cantidad = cantidad + (cantidad + ?),
     fecha_actualizacion = NOW()
     WHERE producto_id = ?  AND id_almacen = ?;
     ");
     $stmtInv->execute([
         $cantidad,
         $data,
         $detalle->id_almacen
     ]);
     $stmtInv->closeCursor();

     $sqlVenta=$pdo->prepare("UPDATE venta_detalle set pendiente=? WHERE id_venta=? and id_producto=?");
     $sqlVenta->execute([
        $cantidad,
        $detalle->id_venta,
        $data
    ]);

     $sqlVenta->closeCursor();

}else{
    $tipo='Salida';
     $cantidad = abs($detalle->cantidad_movimiento);
     $stmtInv = $pdo->prepare("
     UPDATE inventario  SET cantidad = - (cantidad - ?),
     fecha_actualizacion = NOW()
     WHERE producto_id = ?  AND id_almacen = ?;
     ");

     $stmtInv->execute([
         $cantidad,
         $data,
         $detalle->id_almacen
     ]);
     $stmtInv->closeCursor();

     $sqlCompra=$pdo->prepare("UPDATE compra_detalle set pendiente=? WHERE id_compra=? and id_producto=?");
     $sqlCompra->execute([
        $cantidad,
        $detalle->id_compra,
        $data
    ]);

     $sqlCompra->closeCursor();

}

$stmtMov = $pdo->prepare("CALL p_registrar_movimiento(?,?,?,?,?,?,?,?)");
$stmtMov->execute([
    $data,
    00000000,
    $tipo,
    $cantidad,
    $detalle->precio,
    'admin',
    $detalle->id_almacen,
    'Modificación manual'
]);
//var_dump( $data,$ultimo_id->ultimo_id,$detalle->tipo_movimiento,$detalle->cantidad_movimiento,$detalle->precio,$detalle->id_almacen);
 //exit;
$stmtMov->closeCursor();



$smtEstado=$pdo->prepare("UPDATE movimiento_articulos SET estado=? where id=?");
$smtEstado->execute(['anulado',$detalle->id]);
$smtEstado->closeCursor();

$result = [
    'STATUS' => true,
    'messaje' => 'Inventario actualizado correctamente'
];

$response->getBody()->write(json_encode($result));

return $response
    ->withHeader('Content-Type', 'application/json');


});


$app->get('/numeroletras/{cantidad}', function (
    Request $request,
    Response $response,
    array $args
) {

    $cantidad = $args['cantidad'];

    $json = @file_get_contents("http://nal.azurewebsites.net/api/Nal?num=" . urlencode($cantidad));

    if ($json === false) {
        $response->getBody()->write(json_encode([
            'error' => 'No se pudo obtener la conversión a letras.'
        ]));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus(500);
    }

    $data = json_decode($json, true);

    $resultado = $data['letras'] ?? '';

    $response->getBody()->write(json_encode($resultado));

    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});


$app->post('/consulta-cuenta', function (Request $request, Response $response) use ($pdo) {
    $body = $request->getBody()->getContents();


    $j = json_decode($body, true);

 $params = [
    ':cuenta' => $j['cuenta']
   ];
    // 🔹 Función helper
    $run = function($sql, $params) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    };


try{
    $sql_ingreso = $run("SELECT sum(total) as total from (
        SELECT SUM(valor_total) total FROM ventas v left join venta_pagos vp on v.id=vp.id_venta where v.estado=1 and vp.cuentaPago=:cuenta
        union all
        SELECT SUM(monto) total FROM movimiento_caja  where tipo='Ingreso' and cuenta=:cuenta ) AS movimientos;",$params);

         $sql_egreso=$run("SELECT sum(total) as total from (
         SELECT SUM(cp.monto) total FROM compras c left join compra_pagos cp on c.id=cp.id_compra where c.estado=1 and
         cp.cuentaPago=:cuenta
         union all
        SELECT SUM(monto) total FROM movimiento_caja  where tipo='Egreso' and cuenta=:cuenta ) AS movimientos;
         ",$params);

 $resp = [
    "status"=>200,
    "ingresos"=>$sql_ingreso,
    "egresos"=>$sql_egreso,
 ];



$respuesta = json_encode($resp);

} catch (PDOException $e) {

    $respuesta = json_encode([
        "status" => false,
        "message" => $e->getMessage()
    ]);
}

$response->getBody()->write($respuesta);

return $response->withHeader('Content-Type','application/json');

});


$app->post('/guardamovimiento', function (Request $request, Response $response) use ($pdo) {
    $body = $request->getBody()->getContents();
    $data = json_decode($body, true);
    $monto=0;

    try {
        if($data['tipo']=='Ingreso'){
        $monto=$data['monto'];
        }else{
        $monto=-$data['monto'];
        }
        $sql = "INSERT INTO movimiento_caja (cuenta, tipo,concepto,monto,usuario)
                VALUES (:cuenta,:tipo,:concepto,:monto,:usuario)";

        $stmt = $pdo->prepare($sql);

        $proceso = $stmt->execute([
            ':cuenta' => $data['cuenta'],
            ':tipo'=> $data['tipo'],
            ':concepto'=> $data['concepto'],
            ':monto'=> $monto,
            ':usuario'=> $data['usuario']
        ]);

        if ($proceso) {
            $result = [
                "STATUS"  => true,
                "messaje" => "Movimiento generado correctamente"
            ];
        } else {
            $result = [
                "STATUS"  => false,
                "messaje" => "Ocurrió un error en el movimiento"
            ];
        }

    } catch (PDOException $e) {
        $result = [
            "STATUS"  => false,
            "messaje" => $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);


});


$app->get('/movimiento_caja/{id}', function (Request $request, Response $response,$args) use ($pdo) {

    $cuenta = $args['id'];
    $sql = "SELECT id, fecha_registro,tipo,concepto,monto FROM movimiento_caja where cuenta=:cuenta ORDER BY id desc";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':cuenta'    => $cuenta,
    ]);

    $mov = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response->getBody()->write(json_encode($mov));

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});


$app->post('/consulta-movimientos', function (Request $request, Response $response) use ($pdo) {

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


        $sql = "SELECT id, cuenta, tipo,concepto,monto,usuario,fecha_registro FROM movimiento_caja WHERE
        cuenta=:cuenta and fecha_registro BETWEEN :ini and :fin order by id desc";
        $stmt = $pdo->prepare($sql);

        $stmt->execute([
        ':ini' => "$ini 00:00:00",
        ':fin' => "$fin 23:59:59",
        ':cuenta'=>$data['cuenta']
        ]);

        $mov = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $response->getBody()->write(json_encode($mov));


        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);

});


$app->post('/anular-movimiento', function (Request $request, Response $response) use ($pdo) {
    $data = json_decode($request->getBody()->getContents(), true);
    $data = json_decode($data['json']);

    try {
        $stmt = $pdo->prepare("DELETE FROM movimiento_caja WHERE id = :id");
        $stmt->bindParam(':id', $data->id, PDO::PARAM_INT);

        if ($stmt->execute()) {
            $result = [
                "STATUS" => true,
                "messaje" => "Movimiento eliminado correctamente"
            ];
        } else {
            $result = [
                "STATUS" => false,
                "messaje" => "Error al eliminar el movimiento"
            ];
        }

    } catch (PDOException $e) {
        $result = [
            "STATUS" => false,
            "message" => "Error: " . $e->getMessage()
        ];
    }

    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');

});


$app->run();