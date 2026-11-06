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

session_name('mapa_clientes_session');
session_set_cookie_params(0, '/', '', $https, true);
session_start();

define('MAPA_DATA_FILE', __DIR__ . '/datos/mapa.json');
define('NEGOCIOS_DIR', __DIR__ . '/negocios');

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function readJsonFile($path, $description)
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('No se pudo leer ' . $description . '.');
    }
    $data = json_decode($content, true);
    if (!is_array($data)) {
        throw new RuntimeException($description . ' no contiene JSON válido.');
    }
    return $data;
}

function writeJsonFile($path, $data, $description)
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('No se pudo convertir ' . $description . ' a JSON.');
    }
    $temporary = tempnam(dirname($path), 'mapa-');
    if ($temporary === false) {
        throw new RuntimeException('No se pudo preparar el archivo temporal de ' . $description . '.');
    }
    if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false || !rename($temporary, $path)) {
        if (file_exists($temporary)) {
            unlink($temporary);
        }
        throw new RuntimeException('No se pudo guardar ' . $description . '. Revisa los permisos de escritura.');
    }
}

function readMapData()
{
    $data = readJsonFile(MAPA_DATA_FILE, 'datos/mapa.json');
    if (!isset($data['configuracion']['rubros'], $data['negocios'])
        || !is_array($data['configuracion']['rubros']) || !is_array($data['negocios'])) {
        throw new RuntimeException('datos/mapa.json no tiene la estructura esperada.');
    }
    return $data;
}

function slugify($value)
{
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
    if ($ascii !== false) {
        $value = $ascii;
    }
    $slug = strtolower((string)$value);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string)$slug, '-');
    return $slug !== '' ? $slug : 'cliente';
}

function labelKey($value)
{
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
    $normalized = strtolower($ascii !== false ? $ascii : $value);
    $key = preg_replace('/[^a-z0-9]+/', '', $normalized);
    if ($key !== '') {
        return $key;
    }
    $key = preg_replace('/[\p{P}\p{S}\s]+/u', '', $value);
    return $key === null ? trim($value) : $key;
}

function makeId()
{
    $strong = false;
    $bytes = openssl_random_pseudo_bytes(16, $strong);
    if ($bytes === false || !$strong) {
        throw new RuntimeException('No se pudo generar un identificador seguro para el cliente.');
    }
    return bin2hex($bytes);
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

function safeSlug($value)
{
    return is_string($value) && preg_match('/^[\p{L}\p{N}-]+$/u', $value) === 1;
}

function categoryFolder($category)
{
    $slug = isset($category['slug']) ? (string)$category['slug'] : '';
    if (!safeSlug($slug)) {
        throw new RuntimeException('La categoría tiene un nombre de carpeta no válido.');
    }
    return NEGOCIOS_DIR . DIRECTORY_SEPARATOR . $slug;
}

function categoryFile($category)
{
    return categoryFolder($category) . DIRECTORY_SEPARATOR . 'clientes.json';
}

function ensureDirectory($path)
{
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('No se pudo crear la carpeta ' . $path . '. Revisa los permisos.');
    }
}

function readCategoryBusinesses($category)
{
    $path = categoryFile($category);
    if (!file_exists($path)) {
        return array();
    }
    $data = readJsonFile($path, 'el archivo de clientes de ' . (isset($category['label']) ? $category['label'] : $category['id']));
    if (!isset($data['negocios']) || !is_array($data['negocios'])) {
        throw new RuntimeException('El archivo de clientes de la categoría no tiene la estructura esperada.');
    }
    return $data['negocios'];
}

function saveCategoryBusinesses($category, $businesses)
{
    ensureDirectory(categoryFolder($category));
    writeJsonFile(categoryFile($category), array('negocios' => array_values($businesses)), 'clientes de la categoría');
}

function findCategoryIndex($categories, $id)
{
    foreach ($categories as $index => $category) {
        if (isset($category['id']) && (string)$category['id'] === (string)$id) {
            return $index;
        }
    }
    return null;
}

function findBusiness($categories, $id)
{
    foreach ($categories as $category) {
        foreach (readCategoryBusinesses($category) as $business) {
            if (isset($business['id']) && (string)$business['id'] === (string)$id) {
                return array('category' => $category, 'business' => $business);
            }
        }
    }
    return null;
}

function relativeBusinessFolder($category, $business)
{
    if (isset($business['carpeta']) && is_string($business['carpeta'])
        && strpos($business['carpeta'], 'negocios/') === 0
        && strpos($business['carpeta'], '..') === false && strpos($business['carpeta'], '\\') === false) {
        return rtrim($business['carpeta'], '/');
    }
    return 'negocios/' . $category['slug'] . '/' . slugify(isset($business['nombre']) ? $business['nombre'] : 'cliente');
}

function absoluteMapPath($relative)
{
    return __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
}

function replacePathPrefix($path, $oldPrefix, $newPrefix)
{
    if ($path === $oldPrefix) {
        return $newPrefix;
    }
    if (strpos($path, $oldPrefix . '/') === 0) {
        return $newPrefix . substr($path, strlen($oldPrefix));
    }
    return $path;
}

function safeWebsitePath($value)
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^https?:\/\/\S+$/i', $value)) {
        return $value;
    }
    if (strpos($value, 'negocios/') !== 0 || strpos($value, '..') !== false
        || strpos($value, '\\') !== false || preg_match('/[\x00-\x20]/', $value)) {
        throw new RuntimeException('La web debe ser una URL http(s) o una ruta relativa que empiece por negocios/.');
    }
    return $value;
}

function removeTree($path)
{
    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('No se pudo eliminar un archivo de la carpeta del cliente.');
        }
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if ($items === false) {
        throw new RuntimeException('No se pudo revisar la carpeta del cliente antes de eliminarla.');
    }
    foreach ($items as $item) {
        if ($item !== '.' && $item !== '..') {
            removeTree($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException('No se pudo eliminar la carpeta del cliente.');
    }
}

function validateUploadedPhoto($file)
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('No se pudo recibir la foto. Vuelve a seleccionarla.');
    }
    if ((int)$file['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('La foto debe pesar máximo 10 MB.');
    }
    $image = getimagesize($file['tmp_name']);
    if ($image === false) {
        throw new RuntimeException('El archivo seleccionado no es una imagen válida.');
    }
    $extensions = array(
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif'
    );
    if (defined('IMAGETYPE_WEBP')) {
        $extensions[constant('IMAGETYPE_WEBP')] = 'webp';
    }
    if (!isset($extensions[$image[2]])) {
        throw new RuntimeException('La foto debe ser JPG, PNG, GIF o WEBP.');
    }
    return $extensions[$image[2]];
}

function createCategory($mapData, $label)
{
    $label = trim($label);
    if ($label === '' || textLength($label) > 120) {
        throw new RuntimeException('Escribe un nombre de categoría de hasta 120 caracteres.');
    }
    foreach ($mapData['configuracion']['rubros'] as $category) {
        if (isset($category['label']) && labelKey((string)$category['label']) === labelKey($label)) {
            throw new RuntimeException('Ya existe una categoría con ese nombre.');
        }
    }
    $base = slugify($label);
    $slug = $base;
    $suffix = 2;
    $used = array();
    foreach ($mapData['configuracion']['rubros'] as $category) {
        if (isset($category['slug'])) {
            $used[] = $category['slug'];
        }
        if (isset($category['id'])) {
            $used[] = slugify((string)$category['id']);
        }
    }
    while (in_array($slug, $used, true)) {
        $slug = $base . '-' . $suffix++;
    }
    $id = $slug;
    $category = array(
        'id' => $id,
        'label' => $label,
        'slug' => $slug,
        'archivo' => 'negocios/' . $slug . '/clientes.json'
    );
    ensureDirectory(categoryFolder($category));
    if (!file_exists(categoryFile($category))) {
        writeJsonFile(categoryFile($category), array('negocios' => array()), 'el archivo de la categoría');
    }
    $mapData['configuracion']['rubros'][] = $category;
    writeJsonFile(MAPA_DATA_FILE, $mapData, 'datos/mapa.json');
    return array($mapData, $category);
}

function textLength($value)
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $length = preg_match_all('/./us', $value, $matches);
    return $length === false ? strlen($value) : $length;
}

$error = '';
$notice = '';
$mapData = array();
$categories = array();
$businesses = array();
$editing = null;

try {
    $mapData = readMapData();
    $categories = array_values(array_filter($mapData['configuracion']['rubros'], static function ($category) {
        return isset($category['id']) && $category['id'] !== 'Todos';
    }));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validateCsrfToken();
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

        if ($action === 'delete') {
            $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
            $found = findBusiness($categories, $id);
            if ($found === null) {
                throw new RuntimeException('No se encontró el cliente que intentas eliminar.');
            }
            $folder = relativeBusinessFolder($found['category'], $found['business']);
            $expectedPrefix = 'negocios/' . $found['category']['slug'] . '/';
            $businessFolderName = substr($folder, strlen($expectedPrefix));
            if (strpos($folder, $expectedPrefix) !== 0 || !safeSlug($businessFolderName)) {
                throw new RuntimeException('La carpeta asociada al cliente no es válida; no se eliminó.');
            }
            $list = readCategoryBusinesses($found['category']);
            $list = array_values(array_filter($list, static function ($business) use ($id) {
                return !isset($business['id']) || (string)$business['id'] !== $id;
            }));
            saveCategoryBusinesses($found['category'], $list);
            removeTree(absoluteMapPath($folder));
            $_SESSION['flash'] = 'Cliente eliminado del mapa y su carpeta de archivos fue borrada.';
            header('Location: admin.php?categoria=' . rawurlencode((string)$found['category']['id']), true, 303);
            exit;
        }

        if ($action !== 'save') {
            throw new RuntimeException('Acción no reconocida.');
        }

        $id = isset($_POST['id']) ? trim((string)$_POST['id']) : '';
        $old = $id !== '' ? findBusiness($categories, $id) : null;
        if ($id !== '' && $old === null) {
            throw new RuntimeException('No se encontró el cliente que intentas editar.');
        }
        $categoryId = isset($_POST['rubro']) ? (string)$_POST['rubro'] : '';
        $newCategoryLabel = '';
        if ($categoryId === '__new') {
            $newCategoryLabel = isset($_POST['nueva_categoria']) ? (string)$_POST['nueva_categoria'] : '';
        }else{
            $categoryIndex = findCategoryIndex($categories, $categoryId);
            if ($categoryIndex === null) {
                throw new RuntimeException('Selecciona una categoría válida.');
            }
            $category = $categories[$categoryIndex];
        }
        $name = trim(isset($_POST['nombre']) ? (string)$_POST['nombre'] : '');
        $address = trim(isset($_POST['direccion']) ? (string)$_POST['direccion'] : '');
        $phone = trim(isset($_POST['whatsapp']) ? (string)$_POST['whatsapp'] : '');
        $comment = trim(isset($_POST['comentario']) ? (string)$_POST['comentario'] : '');
        $latitude = isset($_POST['lat']) ? trim((string)$_POST['lat']) : '';
        $longitude = isset($_POST['lng']) ? trim((string)$_POST['lng']) : '';
        $website = safeWebsitePath(isset($_POST['pagina']) ? (string)$_POST['pagina'] : '');

        if ($name === '' || textLength($name) > 120) {
            throw new RuntimeException('Escribe el nombre del negocio (máximo 120 caracteres).');
        }
        if (textLength($address) > 240 || textLength($phone) > 40 || textLength($comment) > 1000) {
            throw new RuntimeException('La dirección, WhatsApp o descripción supera el límite permitido.');
        }
        if (!is_numeric($latitude) || !is_numeric($longitude)
            || (float)$latitude < -90 || (float)$latitude > 90
            || (float)$longitude < -180 || (float)$longitude > 180) {
            throw new RuntimeException('Selecciona un punto válido en el mapa para guardar latitud y longitud.');
        }
        $photoExtension = validateUploadedPhoto(isset($_FILES['foto']) ? $_FILES['foto'] : array());
        if ($categoryId === '__new') {
            list($mapData, $category) = createCategory($mapData, $newCategoryLabel);
            $categories = array_values(array_filter($mapData['configuracion']['rubros'], static function ($item) {
                return isset($item['id']) && $item['id'] !== 'Todos';
            }));
        }

        $oldCategory = $old === null ? null : $old['category'];
        $oldBusiness = $old === null ? null : $old['business'];
        $oldFolder = $old === null ? '' : relativeBusinessFolder($oldCategory, $oldBusiness);
        if ($old !== null) {
            $oldPrefix = 'negocios/' . $oldCategory['slug'] . '/';
            if (strpos($oldFolder, $oldPrefix) !== 0 || !safeSlug(substr($oldFolder, strlen($oldPrefix)))) {
                throw new RuntimeException('La carpeta asociada al cliente no es válida; no se guardaron los cambios.');
            }
        }
        $folderSlug = slugify($name);
        $baseFolder = 'negocios/' . $category['slug'] . '/' . $folderSlug;
        $sameExistingFolder = $old !== null && $oldCategory['id'] === $category['id']
            && isset($oldBusiness['nombre']) && $oldBusiness['nombre'] === $name;
        $newFolder = $sameExistingFolder ? $oldFolder : $baseFolder;
        $suffix = 2;
        $newAbsoluteFolder = absoluteMapPath($newFolder);
        while (($oldFolder === '' || $newFolder !== $oldFolder) && file_exists($newAbsoluteFolder)) {
            $newFolder = $baseFolder . '-' . $suffix++;
            $newAbsoluteFolder = absoluteMapPath($newFolder);
        }

        ensureDirectory(categoryFolder($category));
        if ($old !== null && $oldFolder !== $newFolder) {
            $oldAbsoluteFolder = absoluteMapPath($oldFolder);
            if (is_dir($oldAbsoluteFolder)) {
                ensureDirectory(dirname($newAbsoluteFolder));
                if (!rename($oldAbsoluteFolder, $newAbsoluteFolder)) {
                    throw new RuntimeException('No se pudo cambiar la carpeta del cliente. Revisa permisos y archivos en uso.');
                }
            } else {
                ensureDirectory($newAbsoluteFolder);
            }
        } else {
            ensureDirectory($newAbsoluteFolder);
        }

        $business = $oldBusiness !== null ? $oldBusiness : array();
        if ($oldBusiness === null) {
            $business['id'] = makeId();
            $business['etiqueta'] = array();
        }
        $business['activo'] = isset($_POST['activo']);
        $business['nombre'] = $name;
        $business['rubro'] = $category['id'];
        $business['direccion'] = $address;
        $business['whatsapp'] = $phone;
        $business['whatsappActivo'] = isset($_POST['whatsappActivo']);
        $business['comentario'] = $comment;
        $business['pagina'] = $website;
        $business['lat'] = (float)$latitude;
        $business['lng'] = (float)$longitude;
        $business['carpeta'] = $newFolder;
        if ($oldBusiness !== null) {
            $business['foto'] = isset($business['foto']) ? replacePathPrefix((string)$business['foto'], $oldFolder, $newFolder) : '';
            $business['pagina'] = $website !== '' ? $website : '';
            if ($website !== '' && strpos($website, 'negocios/') === 0) {
                $business['pagina'] = replacePathPrefix($website, $oldFolder, $newFolder);
            }
        } else {
            $business['foto'] = '';
        }

        if ($photoExtension !== null) {
            $imagesFolder = $newAbsoluteFolder . DIRECTORY_SEPARATOR . 'images';
            ensureDirectory($imagesFolder);
            foreach (array('jpg', 'png', 'gif', 'webp') as $extension) {
                $oldPhoto = $imagesFolder . DIRECTORY_SEPARATOR . 'fachada.' . $extension;
                if (file_exists($oldPhoto) && !unlink($oldPhoto)) {
                    throw new RuntimeException('No se pudo reemplazar la foto anterior del cliente.');
                }
            }
            $photoPath = $imagesFolder . DIRECTORY_SEPARATOR . 'fachada.' . $photoExtension;
            if (!move_uploaded_file($_FILES['foto']['tmp_name'], $photoPath)) {
                throw new RuntimeException('No se pudo guardar la foto dentro de la carpeta del cliente.');
            }
            $business['foto'] = $newFolder . '/images/fachada.' . $photoExtension;
        }

        if ($old !== null) {
            $oldList = readCategoryBusinesses($oldCategory);
            $oldList = array_values(array_filter($oldList, static function ($item) use ($id) {
                return !isset($item['id']) || (string)$item['id'] !== $id;
            }));
            if ($oldCategory['id'] === $category['id']) {
                $oldList[] = $business;
                saveCategoryBusinesses($category, $oldList);
            } else {
                $newList = readCategoryBusinesses($category);
                $newList[] = $business;
                saveCategoryBusinesses($category, $newList);
                saveCategoryBusinesses($oldCategory, $oldList);
            }
        } else {
            $list = readCategoryBusinesses($category);
            $list[] = $business;
            saveCategoryBusinesses($category, $list);
        }
        $_SESSION['flash'] = $old === null ? 'Cliente creado y conectado a su categoría.' : 'Datos del cliente actualizados.';
        header('Location: admin.php?categoria=' . rawurlencode((string)$category['id']), true, 303);
        exit;
    }

    $flash = isset($_SESSION['flash']) ? (string)$_SESSION['flash'] : '';
    unset($_SESSION['flash']);
    foreach ($categories as $category) {
        foreach (readCategoryBusinesses($category) as $business) {
            $businesses[] = $business;
        }
    }
    if (isset($_GET['editar'])) {
        $editing = findBusiness($categories, (string)$_GET['editar']);
        if ($editing === null) {
            throw new RuntimeException('No se encontró el cliente que quieres editar.');
        }
    }
} catch (Exception $exception) {
    $error = $exception->getMessage();
}

if (count($businesses) === 0 && count($categories) > 0) {
    try {
        foreach ($categories as $category) {
            foreach (readCategoryBusinesses($category) as $business) {
                $businesses[] = $business;
            }
        }
    } catch (Exception $exception) {
        if ($error === '') {
            $error = $exception->getMessage();
        }
    }
}

$formBusiness = $editing !== null ? $editing['business'] : array(
    'id' => '',
    'nombre' => '',
    'rubro' => isset($_GET['categoria']) ? (string)$_GET['categoria'] : '',
    'direccion' => '',
    'whatsapp' => '',
    'whatsappActivo' => true,
    'comentario' => '',
    'foto' => '',
    'pagina' => '',
    'lat' => '',
    'lng' => '',
    'activo' => true
);
$formCategoryId = $editing !== null ? $editing['category']['id'] : $formBusiness['rubro'];
$selectedCategoryId = isset($_GET['categoria']) ? (string)$_GET['categoria'] : '';
if ($selectedCategoryId !== '' && findCategoryIndex($categories, $selectedCategoryId) === null) {
    $selectedCategoryId = '';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save' && $error !== '') {
    $postedId = isset($_POST['id']) ? (string)$_POST['id'] : '';
    if ($postedId !== '') {
        try {
            $editing = findBusiness($categories, $postedId);
            if ($editing !== null) {
                $formBusiness = $editing['business'];
            }
        } catch (Exception $exception) {
            $error = $exception->getMessage();
        }
    }
    foreach (array('nombre', 'direccion', 'whatsapp', 'comentario', 'pagina', 'lat', 'lng') as $field) {
        if (isset($_POST[$field])) {
            $formBusiness[$field] = (string)$_POST[$field];
        }
    }
    $formBusiness['activo'] = isset($_POST['activo']);
    $formBusiness['whatsappActivo'] = isset($_POST['whatsappActivo']);
    $formCategoryId = isset($_POST['rubro']) ? (string)$_POST['rubro'] : '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#2563eb">
  <title>Admin de clientes | Celulokos</title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="estilos/admin-clientes.css">
</head>
<body>
  <header>
    <h1>📍 Admin de clientes</h1>
    <nav><a href="mapa.html">Ver mapa</a><a href="admin-menu.php">Administrar categorías</a><a href="admin-etiqueta.php">Apariencia de etiquetas</a></nav>
  </header>
  <main>
    <div class="aviso">Panel local sin contraseña: úsalo desde esta computadora y no publiques <code>admin.php</code> en internet.</div>
    <?php if ($error !== ''): ?><div class="aviso error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if (isset($flash) && $flash !== ''): ?><div class="aviso exito" role="status"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($error === '' || $mapData): ?>
    <section class="panel">
      <h2><?= $editing === null ? 'Agregar cliente' : 'Editar cliente' ?></h2>
      <p class="ayuda">Haz clic en el mapa para colocar el punto; las coordenadas se llenarán automáticamente.</p>
      <div id="mapaAdmin" aria-label="Mapa para seleccionar coordenadas"></div>
      <form method="post" action="admin.php<?= $editing !== null ? '?editar=' . rawurlencode($formBusiness['id']) : '' ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(makeCsrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= h($formBusiness['id']) ?>">
        <div class="campos">
          <label class="campo">Categoría
            <select id="rubro" name="rubro" required>
              <option value="">Elige una categoría</option>
              <?php foreach ($categories as $category): ?>
                <option value="<?= h($category['id']) ?>" <?= $formCategoryId === $category['id'] ? 'selected' : '' ?>><?= h($category['label']) ?></option>
              <?php endforeach; ?>
              <option value="__new" <?= $formCategoryId === '__new' ? 'selected' : '' ?>>＋ Crear nueva categoría</option>
            </select>
          </label>
          <label class="campo nuevo-campo" id="nuevoCampo" hidden>Nueva categoría
            <input id="nuevaCategoria" type="text" name="nueva_categoria" maxlength="120" value="<?= h(isset($_POST['nueva_categoria']) ? $_POST['nueva_categoria'] : '') ?>" placeholder="Ej.: 🧸 Jugueterías">
          </label>
          <label class="campo">Nombre del negocio (nombre de su carpeta)
            <input id="nombre" type="text" name="nombre" maxlength="120" value="<?= h($formBusiness['nombre']) ?>" required>
          </label>
          <label class="campo">WhatsApp
            <input type="text" name="whatsapp" maxlength="40" value="<?= h($formBusiness['whatsapp']) ?>" placeholder="573001234567">
          </label>
          <label class="check"><input type="checkbox" name="whatsappActivo" value="1" <?= !isset($formBusiness['whatsappActivo']) || $formBusiness['whatsappActivo'] !== false ? 'checked' : '' ?>> Activar «Escribir al WhatsApp» en la ventana flotante</label>
          <label class="campo">Dirección
            <input type="text" name="direccion" maxlength="240" value="<?= h($formBusiness['direccion']) ?>">
          </label>
          <label class="campo">Descripción / comentario
            <textarea name="comentario" maxlength="1000" rows="3"><?= h($formBusiness['comentario']) ?></textarea>
          </label>
          <label class="campo">Foto de fachada (JPG, PNG, GIF o WEBP; máximo 10 MB)
            <input type="file" name="foto" accept="image/jpeg,image/png,image/gif,image/webp">
          </label>
          <?php if (!empty($formBusiness['foto'])): ?><p class="foto-actual">Foto actual: <a href="<?= h($formBusiness['foto']) ?>" target="_blank" rel="noopener noreferrer">ver imagen</a></p><?php endif; ?>
          <label class="campo">Página web (ruta dentro de negocios/ o URL completa)
            <input type="text" name="pagina" maxlength="500" value="<?= h($formBusiness['pagina']) ?>" placeholder="negocios/categoria/cliente/index.html">
          </label>
          <label class="campo">Latitud
            <input id="lat" type="number" name="lat" step="any" min="-90" max="90" value="<?= h($formBusiness['lat']) ?>" required>
          </label>
          <label class="campo">Longitud
            <input id="lng" type="number" name="lng" step="any" min="-180" max="180" value="<?= h($formBusiness['lng']) ?>" required>
          </label>
        </div>
        <label class="check"><input type="checkbox" name="activo" value="1" <?= !isset($formBusiness['activo']) || $formBusiness['activo'] !== false ? 'checked' : '' ?>> Mostrar este cliente en el mapa</label>
        <div class="acciones">
          <button type="submit"><?= $editing === null ? 'Guardar cliente' : 'Guardar cambios' ?></button>
          <?php if ($editing !== null): ?><a class="boton boton-secundario" href="admin.php<?= $selectedCategoryId !== '' ? '?categoria=' . rawurlencode($selectedCategoryId) : '' ?>">Cancelar edición</a><?php endif; ?>
        </div>
      </form>
    </section>

    <section class="panel">
      <h2>Clientes registrados</h2>
      <div class="filtros-categorias" role="group" aria-label="Filtrar clientes por categoría">
        <button class="filtro-categoria<?= $selectedCategoryId === '' ? ' activo' : '' ?>" type="button" data-categoria="" aria-pressed="<?= $selectedCategoryId === '' ? 'true' : 'false' ?>">Todas</button>
        <?php foreach ($categories as $category): ?>
          <?php
            $categoryCount = 0;
            foreach ($businesses as $business) {
                if (isset($business['rubro']) && (string)$business['rubro'] === (string)$category['id']) {
                    $categoryCount++;
                }
            }
          ?>
          <button class="filtro-categoria<?= $selectedCategoryId === (string)$category['id'] ? ' activo' : '' ?>" type="button" data-categoria="<?= h($category['id']) ?>" aria-pressed="<?= $selectedCategoryId === (string)$category['id'] ? 'true' : 'false' ?>"><?= h($category['label']) ?> <span><?= $categoryCount ?></span></button>
        <?php endforeach; ?>
      </div>
      <p class="ayuda filtro-resumen" id="resumenCategoria" aria-live="polite"></p>
      <?php if (count($businesses) === 0): ?><p class="ayuda">Todavía no hay clientes registrados.</p><?php endif; ?>
      <div class="lista-clientes">
        <?php foreach ($businesses as $business): ?>
          <?php
            $categoryIndex = findCategoryIndex($categories, isset($business['rubro']) ? $business['rubro'] : '');
            $categoryLabel = $categoryIndex === null ? 'Sin categoría' : $categories[$categoryIndex]['label'];
            $businessCategoryId = isset($business['rubro']) ? (string)$business['rubro'] : '';
          ?>
          <article class="cliente" data-categoria="<?= h($businessCategoryId) ?>" <?= $selectedCategoryId !== '' && $selectedCategoryId !== $businessCategoryId ? 'hidden' : '' ?>>
            <div><strong><?= h($business['nombre']) ?></strong><span><?= h($categoryLabel) ?> · <?= !isset($business['activo']) || $business['activo'] !== false ? 'Visible' : 'Oculto' ?> · WhatsApp <?= !isset($business['whatsappActivo']) || $business['whatsappActivo'] !== false ? 'activo' : 'desactivado' ?></span></div>
            <div class="acciones">
              <a class="boton boton-secundario" href="admin.php?editar=<?= rawurlencode($business['id']) ?>&amp;categoria=<?= rawurlencode($businessCategoryId) ?>">Editar</a>
              <form method="post" action="admin.php" onsubmit="return confirm('Esto quitará al cliente del mapa y borrará su carpeta, foto y web. ¿Continuar?');">
                <input type="hidden" name="csrf_token" value="<?= h(makeCsrfToken()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= h($business['id']) ?>">
                <button class="boton-peligro" type="submit">Eliminar cliente y archivos</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <p class="ayuda clientes-vacios" id="clientesVacios" hidden>No hay clientes en esta categoría todavía.</p>
    </section>
    <?php endif; ?>
  </main>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    var mapaAdmin = L.map('mapaAdmin').setView([5.33, -73.79], 12);
    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
      attribution: 'Tiles © Esri'
    }).addTo(mapaAdmin);
    var marcadorSeleccion = null;
    <?php foreach ($businesses as $business): ?>
      <?php if (isset($business['lat'], $business['lng']) && is_numeric($business['lat']) && is_numeric($business['lng'])): ?>
      L.marker([<?= json_encode((float)$business['lat']) ?>, <?= json_encode((float)$business['lng']) ?>])
        .addTo(mapaAdmin).bindPopup(<?= json_encode((string)$business['nombre'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
      <?php endif; ?>
    <?php endforeach; ?>
    mapaAdmin.on('click', function (event) {
      document.getElementById('lat').value = event.latlng.lat.toFixed(7);
      document.getElementById('lng').value = event.latlng.lng.toFixed(7);
      if (marcadorSeleccion) mapaAdmin.removeLayer(marcadorSeleccion);
      marcadorSeleccion = L.marker(event.latlng).addTo(mapaAdmin).bindPopup('Nuevo punto').openPopup();
    });
    document.getElementById('rubro').addEventListener('change', function () {
      var esNueva = this.value === '__new';
      var contenedor = document.getElementById('nuevoCampo');
      var campo = document.getElementById('nuevaCategoria');
      contenedor.hidden = !esNueva;
      campo.required = esNueva;
      if (esNueva) campo.focus();
    });
    document.getElementById('rubro').dispatchEvent(new Event('change'));
    var filtrosCategoria = document.querySelectorAll('.filtro-categoria');
    var filasClientes = document.querySelectorAll('.cliente[data-categoria]');
    var resumenCategoria = document.getElementById('resumenCategoria');
    var clientesVacios = document.getElementById('clientesVacios');
    function seleccionarCategoria(id) {
      var visibles = 0;
      filasClientes.forEach(function (cliente) {
        var coincide = id === '' || cliente.dataset.categoria === id;
        cliente.hidden = !coincide;
        if (coincide) visibles++;
      });
      filtrosCategoria.forEach(function (filtro) {
        var seleccionado = filtro.dataset.categoria === id;
        filtro.classList.toggle('activo', seleccionado);
        filtro.setAttribute('aria-pressed', String(seleccionado));
      });
      var activo = Array.prototype.find.call(filtrosCategoria, function (filtro) {
        return filtro.dataset.categoria === id;
      });
      resumenCategoria.textContent = activo ? (id === '' ? 'Mostrando clientes de todas las categorías.' : 'Categoría seleccionada: ' + activo.textContent.trim()) : '';
      if (clientesVacios) clientesVacios.hidden = visibles !== 0 || (id === '' && filasClientes.length === 0);
      if (window.history && window.history.replaceState) {
        var url = new URL(window.location.href);
        if (id === '') url.searchParams.delete('categoria');
        else url.searchParams.set('categoria', id);
        window.history.replaceState({}, '', url);
      }
    }
    filtrosCategoria.forEach(function (filtro) {
      filtro.addEventListener('click', function () {
        seleccionarCategoria(filtro.dataset.categoria);
      });
    });
    seleccionarCategoria(<?= json_encode($selectedCategoryId, JSON_HEX_TAG) ?>);
  </script>
</body>
</html>
