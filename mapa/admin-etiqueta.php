<?php

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$remoteAddress = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
if (!in_array($remoteAddress, array('127.0.0.1', '::1', '::ffff:127.0.0.1'), true)) {
    http_response_code(403);
    exit('Este administrador solo está disponible desde esta computadora.');
}

session_name('mapa_label_admin_session');
session_set_cookie_params(0, '/', '', $https, true);
session_start();

define('MAPA_DATA_FILE', __DIR__ . '/datos/mapa.json');

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function readMapData()
{
    $content = file_get_contents(MAPA_DATA_FILE);
    if ($content === false) {
        throw new RuntimeException('No se pudo leer datos/mapa.json.');
    }
    $data = json_decode($content, true);
    if (!is_array($data) || !isset($data['configuracion']) || !is_array($data['configuracion'])) {
        throw new RuntimeException('datos/mapa.json no tiene la estructura esperada.');
    }
    return $data;
}

function saveMapData($data)
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('No se pudo convertir la configuración del mapa a JSON.');
    }
    $temporary = tempnam(dirname(MAPA_DATA_FILE), 'mapa-');
    if ($temporary === false) {
        throw new RuntimeException('No se pudo preparar el archivo temporal de la configuración.');
    }
    if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false || !rename($temporary, MAPA_DATA_FILE)) {
        if (file_exists($temporary)) {
            unlink($temporary);
        }
        throw new RuntimeException('No se pudo guardar datos/mapa.json. Revisa los permisos de escritura.');
    }
}

function makeCsrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $strong = false;
        $randomBytes = openssl_random_pseudo_bytes(32, $strong);
        if ($randomBytes === false || !$strong) {
            throw new RuntimeException('No se pudo generar un token seguro para el formulario.');
        }
        $_SESSION['csrf_token'] = bin2hex($randomBytes);
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken()
{
    $submitted = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $stored = isset($_SESSION['csrf_token']) ? (string)$_SESSION['csrf_token'] : '';
    if ($stored === '' || strlen($stored) !== strlen($submitted)) {
        throw new RuntimeException('La sesión del formulario venció. Recarga la página e inténtalo de nuevo.');
    }
    $difference = 0;
    for ($index = 0, $length = strlen($stored); $index < $length; $index++) {
        $difference |= ord($stored[$index]) ^ ord($submitted[$index]);
    }
    if ($difference !== 0) {
        throw new RuntimeException('La sesión del formulario venció. Recarga la página e inténtalo de nuevo.');
    }
}

function validColor($value)
{
    return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1;
}

function boundedNumber($value, $minimum, $maximum, $label)
{
    if ($value === '' || !is_numeric($value) || (float)$value < $minimum || (float)$value > $maximum) {
        throw new RuntimeException($label . ' debe estar entre ' . $minimum . ' y ' . $maximum . '.');
    }
    return (float)$value;
}

$error = '';
$notice = '';
$defaults = array(
    'posicion' => 'bottom',
    'desplazamiento' => array(0, 18),
    'estilo' => array(
        'fondo' => '#ffffff',
        'texto' => '#1e293b',
        'tamano' => '12px',
        'peso' => '700',
        'relleno' => '5px 9px',
        'radio' => '999px',
        'sombra' => '0 2px 8px #0005'
    )
);
$popupDefaults = array(
    'ancho' => 260,
    'altoFoto' => 100,
    'altoMaximo' => 420,
    'fondo' => '#ffffff',
    'texto' => '#172033',
    'nombre' => '#172033',
    'descripcion' => '#475569',
    'detalles' => '#334155',
    'enlaces' => '#0284c7',
    'botonFondo' => '#1e293b',
    'botonTexto' => '#0ea5e9',
    'botonDesactivadoFondo' => '#94a3b8',
    'botonDesactivadoTexto' => '#f8fafc',
    'tamanoTexto' => 13,
    'tamanoNombre' => 14,
    'tamanoBoton' => 13,
    'radio' => 12,
    'radioFoto' => 8,
    'radioBoton' => 8,
    'relleno' => 13,
    'espaciado' => 5,
    'sombra' => '0 4px 16px #0004'
);
$config = array();
$popup = $popupDefaults;

try {
    $data = readMapData();
    $config = isset($data['configuracion']['etiquetaPredeterminada'])
        && is_array($data['configuracion']['etiquetaPredeterminada'])
        ? array_replace_recursive($defaults, $data['configuracion']['etiquetaPredeterminada'])
        : $defaults;
    if (isset($data['configuracion']['ventanaFlotante']) && is_array($data['configuracion']['ventanaFlotante'])) {
        $popup = array_replace($popupDefaults, $data['configuracion']['ventanaFlotante']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validateCsrfToken();
        $section = isset($_POST['section']) ? (string)$_POST['section'] : 'etiqueta';
        if ($section === 'popup') {
            $colorFields = array(
                'fondo' => 'Color de fondo de la ventana',
                'texto' => 'Color de texto general',
                'nombre' => 'Color del nombre',
                'descripcion' => 'Color de la descripción',
                'detalles' => 'Color de dirección y teléfono',
                'enlaces' => 'Color de enlaces',
                'botonFondo' => 'Color de fondo del botón web',
                'botonTexto' => 'Color de texto del botón web',
                'botonDesactivadoFondo' => 'Color del botón web desactivado',
                'botonDesactivadoTexto' => 'Color de texto del botón desactivado'
            );
            $popup = $popupDefaults;
            foreach ($colorFields as $field => $label) {
                $value = isset($_POST[$field]) ? (string)$_POST[$field] : '';
                if (!validColor($value)) {
                    throw new RuntimeException($label . ': elige un color válido.');
                }
                $popup[$field] = $value;
            }
            $popup['ancho'] = boundedNumber(isset($_POST['ancho']) ? (string)$_POST['ancho'] : '', 200, 500, 'El ancho de la ventana');
            $popup['altoFoto'] = boundedNumber(isset($_POST['altoFoto']) ? (string)$_POST['altoFoto'] : '', 60, 240, 'La altura de la foto');
            $popup['altoMaximo'] = boundedNumber(isset($_POST['altoMaximo']) ? (string)$_POST['altoMaximo'] : '', 200, 700, 'La altura máxima del contenido');
            $popup['tamanoTexto'] = boundedNumber(isset($_POST['tamanoTexto']) ? (string)$_POST['tamanoTexto'] : '', 10, 20, 'El tamaño de texto');
            $popup['tamanoNombre'] = boundedNumber(isset($_POST['tamanoNombre']) ? (string)$_POST['tamanoNombre'] : '', 10, 26, 'El tamaño del nombre');
            $popup['tamanoBoton'] = boundedNumber(isset($_POST['tamanoBoton']) ? (string)$_POST['tamanoBoton'] : '', 10, 20, 'El tamaño del botón web');
            $popup['radio'] = boundedNumber(isset($_POST['radioPopup']) ? (string)$_POST['radioPopup'] : '', 0, 32, 'El redondeo de la ventana');
            $popup['radioFoto'] = boundedNumber(isset($_POST['radioFoto']) ? (string)$_POST['radioFoto'] : '', 0, 32, 'El redondeo de la foto');
            $popup['radioBoton'] = boundedNumber(isset($_POST['radioBoton']) ? (string)$_POST['radioBoton'] : '', 0, 32, 'El redondeo del botón web');
            $popup['relleno'] = boundedNumber(isset($_POST['rellenoPopup']) ? (string)$_POST['rellenoPopup'] : '', 4, 28, 'El espacio interior de la ventana');
            $popup['espaciado'] = boundedNumber(isset($_POST['espaciado']) ? (string)$_POST['espaciado'] : '', 0, 20, 'El espacio entre elementos');
            $popupShadows = array(
                'none' => 'none',
                'soft' => '0 2px 10px #0003',
                'normal' => '0 4px 16px #0004',
                'strong' => '0 8px 24px #0006'
            );
            $popupShadow = isset($_POST['sombraPopup']) ? (string)$_POST['sombraPopup'] : '';
            if (!isset($popupShadows[$popupShadow])) {
                throw new RuntimeException('Selecciona una sombra válida para la ventana.');
            }
            foreach (array('ancho', 'altoFoto', 'altoMaximo', 'tamanoTexto', 'tamanoNombre', 'tamanoBoton', 'radio', 'radioFoto', 'radioBoton', 'relleno', 'espaciado') as $field) {
                $popup[$field] = (int)$popup[$field];
            }
            $popup['sombra'] = $popupShadows[$popupShadow];
            $data['configuracion']['ventanaFlotante'] = $popup;
        }else{
            $position = isset($_POST['posicion']) ? (string)$_POST['posicion'] : '';
            $allowedPositions = array('top', 'right', 'bottom', 'left', 'center', 'auto');
            if (!in_array($position, $allowedPositions, true)) {
                throw new RuntimeException('Selecciona una posición válida para la etiqueta.');
            }
            $background = isset($_POST['fondo']) ? (string)$_POST['fondo'] : '';
            $textColor = isset($_POST['texto']) ? (string)$_POST['texto'] : '';
            if (!validColor($background) || !validColor($textColor)) {
                throw new RuntimeException('Elige colores válidos para el fondo y el texto.');
            }
            $size = boundedNumber(isset($_POST['tamano']) ? (string)$_POST['tamano'] : '', 8, 32, 'El tamaño de letra');
            $paddingY = boundedNumber(isset($_POST['relleno_y']) ? (string)$_POST['relleno_y'] : '', 0, 24, 'El relleno vertical');
            $paddingX = boundedNumber(isset($_POST['relleno_x']) ? (string)$_POST['relleno_x'] : '', 0, 32, 'El relleno horizontal');
            $radius = boundedNumber(isset($_POST['radio']) ? (string)$_POST['radio'] : '', 0, 999, 'El redondeo');
            $offsetX = boundedNumber(isset($_POST['desplazamiento_x']) ? (string)$_POST['desplazamiento_x'] : '', -50, 50, 'El desplazamiento horizontal');
            $offsetY = boundedNumber(isset($_POST['desplazamiento_y']) ? (string)$_POST['desplazamiento_y'] : '', -50, 50, 'El desplazamiento vertical');
            $weight = isset($_POST['peso']) ? (string)$_POST['peso'] : '';
            if (!in_array($weight, array('400', '500', '600', '700', '800', '900'), true)) {
                throw new RuntimeException('Selecciona un peso de letra válido.');
            }
            $shadows = array(
                'none' => 'none',
                'soft' => '0 2px 8px #0003',
                'normal' => '0 2px 8px #0005',
                'strong' => '0 3px 12px #0008'
            );
            $shadow = isset($_POST['sombra']) ? (string)$_POST['sombra'] : '';
            if (!isset($shadows[$shadow])) {
                throw new RuntimeException('Selecciona una sombra válida.');
            }

            $config = array(
                'posicion' => $position,
                'desplazamiento' => array((int)$offsetX, (int)$offsetY),
                'estilo' => array(
                    'fondo' => $background,
                    'texto' => $textColor,
                    'tamano' => ((float)$size == (int)$size ? (int)$size : $size) . 'px',
                    'peso' => $weight,
                    'relleno' => ((float)$paddingY == (int)$paddingY ? (int)$paddingY : $paddingY) . 'px '
                        . ((float)$paddingX == (int)$paddingX ? (int)$paddingX : $paddingX) . 'px',
                    'radio' => ((float)$radius == (int)$radius ? (int)$radius : $radius) . 'px',
                    'sombra' => $shadows[$shadow]
                )
            );
            $data['configuracion']['etiquetaPredeterminada'] = $config;
        }
        saveMapData($data);
        $_SESSION['flash'] = $section === 'popup'
            ? 'Apariencia global de la ventana guardada. Se aplicará a todos los negocios.'
            : 'Apariencia global guardada. Se aplicará a todas las etiquetas, actuales y nuevas.';
        header('Location: admin-etiqueta.php', true, 303);
        exit;
    }
    $notice = isset($_SESSION['flash']) ? (string)$_SESSION['flash'] : '';
    unset($_SESSION['flash']);
} catch (Exception $exception) {
    $error = $exception->getMessage();
}

$style = isset($config['estilo']) && is_array($config['estilo']) ? array_replace($defaults['estilo'], $config['estilo']) : $defaults['estilo'];
$padding = isset($style['relleno']) ? preg_split('/\s+/', trim((string)$style['relleno'])) : array('5px', '9px');
$paddingY = isset($padding[0]) ? (float)$padding[0] : 5;
$paddingX = isset($padding[1]) ? (float)$padding[1] : $paddingY;
$offset = isset($config['desplazamiento']) && is_array($config['desplazamiento']) ? $config['desplazamiento'] : array(0, 18);
$shadowOptions = array(
    'none' => 'none',
    '0 2px 8px #0003' => 'soft',
    '0 2px 8px #0005' => 'normal',
    '0 3px 12px #0008' => 'strong'
);
$shadowValue = isset($style['sombra']) && isset($shadowOptions[$style['sombra']]) ? $shadowOptions[$style['sombra']] : 'normal';
$popupShadowOptions = array(
    'none' => 'none',
    '0 2px 10px #0003' => 'soft',
    '0 4px 16px #0004' => 'normal',
    '0 8px 24px #0006' => 'strong'
);
$popupShadowValue = isset($popupShadowOptions[$popup['sombra']]) ? $popupShadowOptions[$popup['sombra']] : 'normal';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#2563eb">
  <title>Apariencia de etiquetas | Celulokos</title>
  <link rel="stylesheet" href="estilos/admin-etiqueta.css">
</head>
<body>
  <header>
    <h1>🎨 Apariencia de etiquetas</h1>
    <nav>
      <a href="admin.php">Administrar clientes</a>
      <a href="admin-menu.php">Administrar categorías</a>
      <a href="mapa.html">Ver mapa</a>
    </nav>
  </header>
  <main>
    <div class="aviso">Los cambios se aplican globalmente a las etiquetas actuales y a los nuevos clientes. El mapa los carga automáticamente.</div>
    <?php if ($error !== ''): ?><div class="aviso error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="aviso exito" role="status"><?= h($notice) ?></div><?php endif; ?>
    <section class="panel">
      <h2>Estilo global</h2>
      <form method="post" action="admin-etiqueta.php">
        <input type="hidden" name="csrf_token" value="<?= h(makeCsrfToken()) ?>">
        <input type="hidden" name="section" value="etiqueta">
        <div class="campos">
          <label class="campo">Posición de la etiqueta
            <select name="posicion" id="posicion">
              <?php foreach (array('top' => 'Arriba', 'right' => 'Derecha', 'bottom' => 'Abajo', 'left' => 'Izquierda', 'center' => 'Centro', 'auto' => 'Automática') as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= isset($config['posicion']) && $config['posicion'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="campo">Color de fondo
            <input type="color" name="fondo" id="fondo" value="<?= h($style['fondo']) ?>" required>
          </label>
          <label class="campo">Color del texto
            <input type="color" name="texto" id="texto" value="<?= h($style['texto']) ?>" required>
          </label>
          <label class="campo">Tamaño de letra (8 a 32 px)
            <input type="number" name="tamano" id="tamano" min="8" max="32" step="1" value="<?= h(rtrim((string)$style['tamano'], 'px')) ?>" required>
          </label>
          <label class="campo">Grosor del texto
            <select name="peso" id="peso">
              <?php foreach (array('400' => 'Normal', '500' => 'Medio', '600' => 'Seminegrita', '700' => 'Negrita', '800' => 'Extra negrita', '900' => 'Máximo') as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= (string)$style['peso'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="campo">Redondeo de esquinas (0 a 999 px)
            <input type="number" name="radio" id="radio" min="0" max="999" step="1" value="<?= h(rtrim((string)$style['radio'], 'px')) ?>" required>
          </label>
          <label class="campo">Relleno vertical (0 a 24 px)
            <input type="number" name="relleno_y" id="rellenoY" min="0" max="24" step="1" value="<?= h($paddingY) ?>" required>
          </label>
          <label class="campo">Relleno horizontal (0 a 32 px)
            <input type="number" name="relleno_x" id="rellenoX" min="0" max="32" step="1" value="<?= h($paddingX) ?>" required>
          </label>
          <label class="campo">Sombra
            <select name="sombra" id="sombra">
              <option value="none" <?= $shadowValue === 'none' ? 'selected' : '' ?>>Sin sombra</option>
              <option value="soft" <?= $shadowValue === 'soft' ? 'selected' : '' ?>>Suave</option>
              <option value="normal" <?= $shadowValue === 'normal' ? 'selected' : '' ?>>Normal</option>
              <option value="strong" <?= $shadowValue === 'strong' ? 'selected' : '' ?>>Marcada</option>
            </select>
          </label>
          <label class="campo">Desplazamiento horizontal (-50 a 50 px)
            <input type="number" name="desplazamiento_x" id="desplazamientoX" min="-50" max="50" step="1" value="<?= h(isset($offset[0]) ? $offset[0] : 0) ?>" required>
          </label>
          <label class="campo">Desplazamiento vertical (-50 a 50 px)
            <input type="number" name="desplazamiento_y" id="desplazamientoY" min="-50" max="50" step="1" value="<?= h(isset($offset[1]) ? $offset[1] : 18) ?>" required>
          </label>
        </div>
        <div class="vista-previa" aria-live="polite">
          <span class="texto-etiqueta" id="vistaEtiqueta">Nombre del negocio</span>
          <span>Vista previa</span>
        </div>
        <div class="acciones">
          <button type="submit">Guardar apariencia global</button>
        </div>
      </form>
    </section>
    <section class="panel">
      <h2>Ventana flotante del negocio</h2>
      <p class="ayuda">Personaliza la ficha que aparece al seleccionar un punto del mapa: tamaño, foto, textos, enlaces y botones. Se aplica a todos los negocios actuales y nuevos.</p>
      <form method="post" action="admin-etiqueta.php">
        <input type="hidden" name="csrf_token" value="<?= h(makeCsrfToken()) ?>">
        <input type="hidden" name="section" value="popup">
        <div class="campos">
          <label class="campo">Ancho de la ventana (200 a 500 px)
            <input type="number" name="ancho" id="popupAncho" min="200" max="500" step="1" value="<?= h($popup['ancho']) ?>" required>
          </label>
          <label class="campo">Alto de la foto (60 a 240 px)
            <input type="number" name="altoFoto" id="popupAltoFoto" min="60" max="240" step="1" value="<?= h($popup['altoFoto']) ?>" required>
          </label>
          <label class="campo">Alto máximo del contenido (200 a 700 px)
            <input type="number" name="altoMaximo" id="popupAltoMaximo" min="200" max="700" step="1" value="<?= h($popup['altoMaximo']) ?>" required>
          </label>
          <label class="campo">Espacio interior (4 a 28 px)
            <input type="number" name="rellenoPopup" id="popupRelleno" min="4" max="28" step="1" value="<?= h($popup['relleno']) ?>" required>
          </label>
          <label class="campo">Redondeo de la ventana (0 a 32 px)
            <input type="number" name="radioPopup" id="popupRadio" min="0" max="32" step="1" value="<?= h($popup['radio']) ?>" required>
          </label>
          <label class="campo">Redondeo de la foto (0 a 32 px)
            <input type="number" name="radioFoto" id="popupRadioFoto" min="0" max="32" step="1" value="<?= h($popup['radioFoto']) ?>" required>
          </label>
          <label class="campo">Redondeo del botón web (0 a 32 px)
            <input type="number" name="radioBoton" id="popupRadioBoton" min="0" max="32" step="1" value="<?= h($popup['radioBoton']) ?>" required>
          </label>
          <label class="campo">Espacio entre elementos (0 a 20 px)
            <input type="number" name="espaciado" id="popupEspaciado" min="0" max="20" step="1" value="<?= h($popup['espaciado']) ?>" required>
          </label>
          <label class="campo">Sombra de la ventana
            <select name="sombraPopup" id="popupSombra">
              <option value="none" <?= $popupShadowValue === 'none' ? 'selected' : '' ?>>Sin sombra</option>
              <option value="soft" <?= $popupShadowValue === 'soft' ? 'selected' : '' ?>>Suave</option>
              <option value="normal" <?= $popupShadowValue === 'normal' ? 'selected' : '' ?>>Normal</option>
              <option value="strong" <?= $popupShadowValue === 'strong' ? 'selected' : '' ?>>Marcada</option>
            </select>
          </label>
          <label class="campo">Color de fondo de la ventana
            <input type="color" name="fondo" id="popupFondo" value="<?= h($popup['fondo']) ?>" required>
          </label>
          <label class="campo">Color de texto general
            <input type="color" name="texto" id="popupTexto" value="<?= h($popup['texto']) ?>" required>
          </label>
          <label class="campo">Color del nombre del negocio
            <input type="color" name="nombre" id="popupNombre" value="<?= h($popup['nombre']) ?>" required>
          </label>
          <label class="campo">Color de la descripción
            <input type="color" name="descripcion" id="popupDescripcion" value="<?= h($popup['descripcion']) ?>" required>
          </label>
          <label class="campo">Color de dirección y teléfono
            <input type="color" name="detalles" id="popupDetalles" value="<?= h($popup['detalles']) ?>" required>
          </label>
          <label class="campo">Color de enlaces (Cómo llegar y WhatsApp)
            <input type="color" name="enlaces" id="popupEnlaces" value="<?= h($popup['enlaces']) ?>" required>
          </label>
          <label class="campo">Tamaño del texto (10 a 20 px)
            <input type="number" name="tamanoTexto" id="popupTamanoTexto" min="10" max="20" step="1" value="<?= h($popup['tamanoTexto']) ?>" required>
          </label>
          <label class="campo">Tamaño del nombre (10 a 26 px)
            <input type="number" name="tamanoNombre" id="popupTamanoNombre" min="10" max="26" step="1" value="<?= h($popup['tamanoNombre']) ?>" required>
          </label>
          <label class="campo">Fondo del botón «Entra a mi web»
            <input type="color" name="botonFondo" id="popupBotonFondo" value="<?= h($popup['botonFondo']) ?>" required>
          </label>
          <label class="campo">Texto del botón «Entra a mi web»
            <input type="color" name="botonTexto" id="popupBotonTexto" value="<?= h($popup['botonTexto']) ?>" required>
          </label>
          <label class="campo">Fondo del botón web sin página
            <input type="color" name="botonDesactivadoFondo" id="popupBotonDesactivadoFondo" value="<?= h($popup['botonDesactivadoFondo']) ?>" required>
          </label>
          <label class="campo">Texto del botón web desactivado
            <input type="color" name="botonDesactivadoTexto" id="popupBotonDesactivadoTexto" value="<?= h($popup['botonDesactivadoTexto']) ?>" required>
          </label>
          <label class="campo">Tamaño del texto del botón (10 a 20 px)
            <input type="number" name="tamanoBoton" id="popupTamanoBoton" min="10" max="20" step="1" value="<?= h($popup['tamanoBoton']) ?>" required>
          </label>
        </div>
        <div class="vista-previa vista-previa-popup" id="vistaVentana" aria-live="polite">
          <div class="ventana-demo">
            <img class="foto-demo" src="images/fachada-default.png" alt="">
            <strong class="nombre-demo">Nombre del negocio</strong>
            <p class="descripcion-demo">Descripción del negocio y sus servicios.</p>
            <p class="detalle-demo">Dirección y teléfono</p>
            <div class="enlaces-demo"><span>Cómo llegar</span><span>WhatsApp</span></div>
            <div class="boton-web-demo">Entra a mi web</div>
          </div>
          <span>Vista previa de la ventana flotante</span>
        </div>
        <div class="acciones">
          <button type="submit">Guardar apariencia de la ventana</button>
        </div>
      </form>
    </section>
  </main>
  <script>
    (function () {
      var preview = document.getElementById('vistaEtiqueta');
      var fields = ['fondo', 'texto', 'tamano', 'peso', 'radio', 'rellenoY', 'rellenoX', 'sombra'];
      var shadows = {
        none: 'none',
        soft: '0 2px 8px #0003',
        normal: '0 2px 8px #0005',
        strong: '0 3px 12px #0008'
      };
      function updatePreview() {
        preview.style.backgroundColor = document.getElementById('fondo').value;
        preview.style.color = document.getElementById('texto').value;
        preview.style.fontSize = document.getElementById('tamano').value + 'px';
        preview.style.fontWeight = document.getElementById('peso').value;
        preview.style.borderRadius = document.getElementById('radio').value + 'px';
        preview.style.padding = document.getElementById('rellenoY').value + 'px ' + document.getElementById('rellenoX').value + 'px';
        preview.style.boxShadow = shadows[document.getElementById('sombra').value];
      }
      fields.forEach(function (id) {
        document.getElementById(id).addEventListener('input', updatePreview);
        document.getElementById(id).addEventListener('change', updatePreview);
      });
      updatePreview();
    }());
    (function () {
      var preview = document.getElementById('vistaVentana');
      var fields = {
        '--ventana-ancho': ['popupAncho', 'px'],
        '--ventana-alto-foto': ['popupAltoFoto', 'px'],
        '--ventana-alto-maximo': ['popupAltoMaximo', 'px'],
        '--ventana-relleno': ['popupRelleno', 'px'],
        '--ventana-radio': ['popupRadio', 'px'],
        '--ventana-radio-foto': ['popupRadioFoto', 'px'],
        '--ventana-radio-boton': ['popupRadioBoton', 'px'],
        '--ventana-espaciado': ['popupEspaciado', 'px'],
        '--ventana-fondo': ['popupFondo', ''],
        '--ventana-texto': ['popupTexto', ''],
        '--ventana-nombre': ['popupNombre', ''],
        '--ventana-descripcion': ['popupDescripcion', ''],
        '--ventana-detalles': ['popupDetalles', ''],
        '--ventana-enlaces': ['popupEnlaces', ''],
        '--ventana-boton-fondo': ['popupBotonFondo', ''],
        '--ventana-boton-texto': ['popupBotonTexto', ''],
        '--ventana-boton-inactivo-fondo': ['popupBotonDesactivadoFondo', ''],
        '--ventana-boton-inactivo-texto': ['popupBotonDesactivadoTexto', ''],
        '--ventana-texto-tamano': ['popupTamanoTexto', 'px'],
        '--ventana-nombre-tamano': ['popupTamanoNombre', 'px'],
        '--ventana-boton-tamano': ['popupTamanoBoton', 'px']
      };
      var shadows = {
        none: 'none',
        soft: '0 2px 10px #0003',
        normal: '0 4px 16px #0004',
        strong: '0 8px 24px #0006'
      };
      function updatePreview() {
        Object.keys(fields).forEach(function (property) {
          var field = fields[property];
          preview.style.setProperty(property, document.getElementById(field[0]).value + field[1]);
        });
        preview.style.setProperty('--ventana-sombra', shadows[document.getElementById('popupSombra').value]);
      }
      Object.keys(fields).forEach(function (property) {
        var element = document.getElementById(fields[property][0]);
        element.addEventListener('input', updatePreview);
        element.addEventListener('change', updatePreview);
      });
      document.getElementById('popupSombra').addEventListener('change', updatePreview);
      updatePreview();
    }());
  </script>
</body>
</html>
