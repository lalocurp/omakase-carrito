<?php
/* OMAKASE - Finaliza la compra del carrito.
   Recibe JSON:
   { modo: "existente"|"nuevo", email, nombre?, password?, direccion?{...}, carrito:[{id,cantidad}] }
   Responde JSON: { ok, mensaje, id_pedido?, total? } */

header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';          // Se asume que define $conexion (mysqli). Cambia el nombre si usas otro.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conexion->set_charset('utf8mb4');

function responder($ok, $mensaje, $extra = []) {
    echo json_encode(array_merge(['ok' => $ok, 'mensaje' => $mensaje], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
if (!$in) responder(false, 'Solicitud inválida.');

$modo    = $in['modo'] ?? '';
$email   = strtolower(trim($in['email'] ?? ''));
$carrito = $in['carrito'] ?? [];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) responder(false, 'El correo electrónico no es válido.');
if (!is_array($carrito) || count($carrito) === 0) responder(false, 'Tu carrito está vacío.');

try {
    $conexion->begin_transaction();

    /* ---------- 1. Identificar o crear al usuario ---------- */
    $stmt = $conexion->prepare('SELECT id_usuario, nombre FROM usuarios WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $usuario = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($modo === 'existente') {
        if (!$usuario) {
            $conexion->rollback();
            responder(false, 'No encontramos ese correo en nuestros registros. Revisa que esté bien escrito o elige "Soy cliente nuevo".');
        }
        $id_usuario = (int)$usuario['id_usuario'];

        // Usa la dirección más reciente del cliente
        $stmt = $conexion->prepare('SELECT id_direccion FROM direcciones WHERE id_usuario = ? ORDER BY id_direccion DESC LIMIT 1');
        $stmt->bind_param('i', $id_usuario);
        $stmt->execute();
        $dir = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$dir) {
            $conexion->rollback();
            responder(false, 'Tu cuenta no tiene una dirección de envío registrada. Contáctanos para completarla.');
        }
        $id_direccion = (int)$dir['id_direccion'];

    } elseif ($modo === 'nuevo') {
        if ($usuario) {
            $conexion->rollback();
            responder(false, 'Ese correo ya está registrado. Elige "Ya soy cliente registrado" e ingrésalo ahí.');
        }

        $nombre   = trim($in['nombre'] ?? '');
        $password = $in['password'] ?? '';
        $d        = $in['direccion'] ?? [];
        $calle    = trim($d['calle_numero'] ?? '');
        $colonia  = trim($d['colonia'] ?? '');
        $ciudad   = trim($d['ciudad'] ?? '');
        $cp       = trim($d['codigo_postal'] ?? '');
        $tel      = trim($d['telefono'] ?? '');

        if ($nombre === '' || $calle === '' || $colonia === '' || $ciudad === '' || $cp === '' || $tel === '')
            responder(false, 'Completa todos los campos del registro.');
        if (strlen($password) < 6)
            responder(false, 'La contraseña debe tener al menos 6 caracteres.');

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $rol  = 'cliente';

        $stmt = $conexion->prepare('INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('ssss', $nombre, $email, $hash, $rol);
        $stmt->execute();
        $id_usuario = $conexion->insert_id;
        $stmt->close();

        $stmt = $conexion->prepare('INSERT INTO direcciones (id_usuario, calle_numero, colonia, ciudad, codigo_postal, telefono) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('isssss', $id_usuario, $calle, $colonia, $ciudad, $cp, $tel);
        $stmt->execute();
        $id_direccion = $conexion->insert_id;
        $stmt->close();

    } else {
        responder(false, 'Modo de compra inválido.');
    }

    /* ---------- 2. Validar productos con precios REALES de la BD ---------- */
    $lineas = [];
    $total  = 0;
    $sel = $conexion->prepare('SELECT nombre, precio, stock FROM productos WHERE id_producto = ? FOR UPDATE');

    foreach ($carrito as $item) {
        $id   = (int)($item['id'] ?? 0);
        $cant = (int)($item['cantidad'] ?? 0);
        if ($id <= 0 || $cant <= 0) continue;

        $sel->bind_param('i', $id);
        $sel->execute();
        $p = $sel->get_result()->fetch_assoc();

        if (!$p) {
            $conexion->rollback();
            responder(false, 'Uno de los productos ya no existe. Vacía el carrito e inténtalo de nuevo.');
        }
        if ($p['stock'] < $cant) {
            $conexion->rollback();
            responder(false, 'Stock insuficiente de "' . $p['nombre'] . '" (disponibles: ' . $p['stock'] . ').');
        }
        $lineas[] = ['id' => $id, 'cant' => $cant, 'precio' => (float)$p['precio']];
        $total += $p['precio'] * $cant;
    }
    $sel->close();

    if (!$lineas) {
        $conexion->rollback();
        responder(false, 'Tu carrito no tiene productos válidos.');
    }

    /* ---------- 3. Crear pedido + detalle + descontar stock ---------- */
    $estado = 'Pendiente';
    $stmt = $conexion->prepare('INSERT INTO pedidos (id_usuario, id_direccion, fecha, total, estado) VALUES (?, ?, NOW(), ?, ?)');
    $stmt->bind_param('iids', $id_usuario, $id_direccion, $total, $estado);
    $stmt->execute();
    $id_pedido = $conexion->insert_id;
    $stmt->close();

    $det = $conexion->prepare('INSERT INTO detalle_pedidos (id_pedido, id_producto, cantidad, precio_unitario) VALUES (?, ?, ?, ?)');
    $upd = $conexion->prepare('UPDATE productos SET stock = stock - ? WHERE id_producto = ?');
    foreach ($lineas as $l) {
        $det->bind_param('iiid', $id_pedido, $l['id'], $l['cant'], $l['precio']);
        $det->execute();
        $upd->bind_param('ii', $l['cant'], $l['id']);
        $upd->execute();
    }
    $det->close();
    $upd->close();

    $conexion->commit();
    responder(true, 'Pedido registrado.', ['id_pedido' => $id_pedido, 'total' => $total]);

} catch (mysqli_sql_exception $e) {
    $conexion->rollback();
    if ($e->getCode() === 1062) {   // Llave duplicada (si agregas UNIQUE al email)
        responder(false, 'Ese correo ya está registrado. Elige "Ya soy cliente registrado".');
    }
    responder(false, 'Error en la base de datos: ' . $e->getMessage());
}
