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
        $sql = "INSERT INTO sub_categorias (id_categoria, nombre) 
                VALUES (:id_categoria, :nombre)";

        $stmt = $pdo->prepare($sql);

        $proceso = $stmt->execute([
            ':id_categoria' => $data->id_categoria,
            ':nombre'       => $data->nombre
        ]);

        if ($proceso) {
            $result = [
                "STATUS"  => true,
                "message" => "Subcategoría creada correctamente"
            ];
        } else {
            $result = [
                "STATUS"  => false,
                "message" => "Ocurrió un error en la creación"
            ];
        }

    } catch (PDOException $e) {
        $result = [
            "STATUS"  => false,
            "message" => $e->getMessage()
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


$app->post('/kardex', function (Request $request, Response $response) use ($pdo) {

    $body = $request->getBody()->getContents();
    $j = json_decode($body, true);
    $data = json_decode($j['json'], true);

    // 🔹 Conversión de fechas (igual a tu lógica)
    $arraymeses = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $arraynros  = ['01','02','03','04','05','06','07','08','09','10','11','12'];

    $mes1 = substr($data['inicio'], 0,3);
    $mes2 = substr($data['fin'], 0,3);
    $dia1 = substr($data['inicio'], 3,2);
    $dia2 = substr($data['fin'], 3,2);
    $ano1 = substr($data['inicio'], 5,4);
    $ano2 = substr($data['fin'], 5,4);

    $ini = $ano1.'-'.str_replace($arraymeses,$arraynros,$mes1).'-'.$dia1.' 00:00:01';
    $fin = $ano2.'-'.str_replace($arraymeses,$arraynros,$mes2).'-'.$dia2.' 23:59:59';

    try {

        // 🔹 Query principal
        $sql1 = "SELECT p.id, p.codigo, p.nombre, p.categoria
                 FROM movimiento_articulos m
                 INNER JOIN productos p ON m.codigo_prod = p.id
                 WHERE m.fecha_registro BETWEEN :ini AND :fin";

        $params = [
            ':ini' => $ini,
            ':fin' => $fin
        ];

        if (!empty($data['producto'])) {
            $sql1 .= " AND m.codigo_prod = :producto";
            $params[':producto'] = $data['producto'];
        }

        $sql1 .= " GROUP BY p.id ORDER BY p.id DESC";

        $stmt = $pdo->prepare($sql1);
        $stmt->execute($params);

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
                                ROUND(m.cantidad_acumulada*m.promedio,2) AS p_total,
                                m.cantidad_ingreso,
                                m.cantidad_salida,
                                m.precio,
                                m.promedio,
                                ROUND(m.cantidad_acumulada*m.precio,2) AS costo,
                                m.comentario,
                                DATE_FORMAT(m.fecha_registro,'%d-%m-%Y') AS fecha_registro
                            FROM movimiento_articulos m
                            INNER JOIN sucursales s ON s.id = m.id_sucursal
                            INNER JOIN productos p ON m.codigo_prod = p.id
                            INNER JOIN unidad u ON p.unidad = u.codigo
                            WHERE m.codigo_prod = :id
                              AND NOT (m.cantidad_ingreso = 0 AND m.cantidad_salida = 0)
                              AND m.precio <> 0";

            $paramsDetalle = [':id' => $id];

            if (!empty($data['sucursal']) && $data['sucursal'] != "0") {
                $sqlDetalle .= " AND m.id_almacen = :sucursal";
                $paramsDetalle[':sucursal'] = $data['sucursal'];
            }

            if (!empty($data['movimiento']) && $data['movimiento'] != "0") {
                $sqlDetalle .= " AND m.tipo_movimiento = :movimiento";
                $paramsDetalle[':movimiento'] = $data['movimiento'];
            }

            if (!empty($data['compra'])) {
                $sqlDetalle .= " AND m.id_compra = :compra";
                $paramsDetalle[':compra'] = $data['compra'];
            }

            if (!empty($data['venta'])) {
                $sqlDetalle .= " AND m.id_venta = :venta";
                $paramsDetalle[':venta'] = $data['venta'];
            }

            $sqlDetalle .= " ORDER BY m.id DESC";

            $stmtDet = $pdo->prepare($sqlDetalle);
            $stmtDet->execute($paramsDetalle);

            $fila['detalle'] = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

            // 🔹 PROMEDIO
            $sqlProm = "SELECT promedio, cantidad_acumulada
                        FROM movimiento_articulos
                        WHERE codigo_prod = :id
                        ORDER BY id DESC LIMIT 1";

            $stmtProm = $pdo->prepare($sqlProm);
            $stmtProm->execute([':id' => $id]);
            $fila['promedio'] = $stmtProm->fetch(PDO::FETCH_ASSOC);

            // 🔹 STOCK
            $sqlStock = "SELECT 
                            SUM(cantidad_ingreso) - SUM(cantidad_salida) AS cantidad
                         FROM movimiento_articulos
                         WHERE codigo_prod = :id";

            $stmtStock = $pdo->prepare($sqlStock);
            $stmtStock->execute([':id' => $id]);
            $fila['stock'] = $stmtStock->fetch(PDO::FETCH_ASSOC);

            $prods[] = $fila;
        }

        $response->getBody()->write(json_encode($prods));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

    } catch (PDOException $e) {

        $response->getBody()->write(json_encode([
            "STATUS" => false,
            "message" => $e->getMessage()
        ]));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
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

$app->run();