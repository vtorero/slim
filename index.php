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


// 🔥 SOLUCIÓN
$app->setBasePath('/slim');

$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->get('/', function($request, $response, $args){
    $response->getBody()->write('Pagina Principal');
    return $response;
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