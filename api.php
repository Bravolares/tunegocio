<?php
// Forzar que muestre errores si ocurren en Laragon
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

$dbFile = __DIR__ . '/database.php';

// Leer el archivo de texto plano nativo de PHP
$data = file_exists($dbFile) ? include($dbFile) : array();

if (!is_array($data)) {
    $data = array(
        'empresa' => array('nombre' => 'Sazón Urbano', 'eslogan' => 'Sabores que conectan', 'whatsapp' => '+573219163532'),
        'productos' => array()
    );
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// Enviar los datos limpios al index en formato JSON seguro
if ($action === 'get') {
    // JSON_UNESCAPED_UNICODE previene que las tildes rompan el script en el navegador
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
// Control de seguridad por si se intenta llamar una acción inexistente
else {
    echo json_encode(array("status" => "error", "message" => "Acción no reconocida"));
    exit;
}
?>
