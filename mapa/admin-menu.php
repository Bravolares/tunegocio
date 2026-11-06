<?php

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$remoteAddress = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
if (!$isLocalRequest) {
    http_response_code(403);
    exit('Este administrador solo está disponible desde esta computadora.');
}

session_name('mapa_admin_session');
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
    if (!is_array($data) || !isset($data['configuracion']['rubros'], $data['negocios'])
        || !is_array($data['configuracion']['rubros']) || !is_array($data['negocios'])) {
        throw new RuntimeException('datos/mapa.json no tiene la estructura esperada.');
    }

    return $data;
}

function saveMapData($data)
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('No se pudieron convertir los datos del mapa a JSON.');
    }

    if (file_put_contents(MAPA_DATA_FILE, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar datos/mapa.json. Revisa los permisos de escritura.');
    }
}

function categoryJsonPath($category)
{
    $slug = isset($category['slug']) ? (string)$category['slug'] : '';
    if ($slug === '' || preg_match('/^[\p{L}\p{N}-]+$/u', $slug) !== 1) {
        throw new RuntimeException('La categoría tiene una carpeta no válida.');
    }
    return __DIR__ . '/negocios/' . $slug . '/clientes.json';
}

function readCategoryBusinesses($category)
{
    $path = categoryJsonPath($category);
    if (!file_exists($path)) {
        return [];
    }
    $content = file_get_contents($path);
    $data = $content === false ? null : json_decode($content, true);
    if (!is_array($data) || !isset($data['negocios']) || !is_array($data['negocios'])) {
        throw new RuntimeException('El JSON de clientes de una categoría no tiene la estructura esperada.');
    }
    return $data['negocios'];
}

function ensureCategoryJson($category)
{
    $directory = dirname(categoryJsonPath($category));
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear la carpeta de la categoría.');
    }
    $path = categoryJsonPath($category);
    if (!file_exists($path) && file_put_contents($path, "{\n    \"negocios\": []\n}\n", LOCK_EX) === false) {
        throw new RuntimeException('No se pudo inicializar el JSON de la categoría.');
    }
}

function slugify($value)
{
    $value = strtr($value, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N'
    ]);
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
    if ($ascii !== false) {
        $value = $ascii;
    }
    $slug = strtolower($value);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string)$slug, '-');

    return $slug !== '' ? $slug : 'categoria';
}

function labelKey($value)
{
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
    $normalized = strtolower($ascii !== false ? $ascii : $value);
    $key = preg_replace('/[^a-z0-9]+/', '', $normalized);
    if ($key !== '') {
        return $key;
    }

    $normalized = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $key = preg_replace('/[\p{P}\p{S}\s]+/u', '', $normalized);
    return $key === null ? trim($normalized) : $key;
}

function textLength($value)
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $length = preg_match_all('/./us', $value, $matches);
    return $length === false ? strlen($value) : $length;
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

function constantTimeEquals($known, $submitted)
{
    if (strlen($known) !== strlen($submitted)) {
        return false;
    }

    $difference = 0;
    for ($index = 0, $length = strlen($known); $index < $length; $index++) {
        $difference |= ord($known[$index]) ^ ord($submitted[$index]);
    }
    return $difference === 0;
}

function validateCsrfToken()
{
    $submitted = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    $stored = isset($_SESSION['csrf_token']) ? (string)$_SESSION['csrf_token'] : '';
    if ($stored === '' || !constantTimeEquals($stored, $submitted)) {
        throw new RuntimeException('La sesión del formulario venció. Recarga la página e inténtalo de nuevo.');
    }
}

function setFlash($type, $message)
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function redirectToAdmin()
{
    header('Location: admin-menu.php', true, 303);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validateCsrfToken();
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

        $data = readMapData();
        $categories =& $data['configuracion']['rubros'];
        $categoryIndex = false;
        foreach ($categories as $position => $category) {
            if (isset($category['id']) && $category['id'] === 'Todos') {
                $categoryIndex = $position;
                break;
            }
        }

        if ($action === 'add') {
            $label = isset($_POST['label']) ? trim((string)$_POST['label']) : '';
            if ($label === '' || textLength($label) > 120) {
                throw new RuntimeException('Escribe un nombre de categoría de hasta 120 caracteres.');
            }
            foreach ($categories as $category) {
                if (isset($category['label']) && labelKey((string)$category['label']) === labelKey($label)) {
                    throw new RuntimeException('Ya existe una categoría con ese nombre.');
                }
            }

            $slug = slugify($label);
            $usedSlugs = [];
            foreach ($categories as $existingCategory) {
                if (isset($existingCategory['id'])) {
                    $usedSlugs[] = $existingCategory['id'];
                }
                if (isset($existingCategory['slug'])) {
                    $usedSlugs[] = $existingCategory['slug'];
                }
            }
            $baseSlug = $slug;
            $suffix = 2;
            while (in_array($slug, $usedSlugs, true)) {
                $slug = $baseSlug . '-' . $suffix++;
            }

            $category = [
                'id' => $slug,
                'label' => $label,
                'slug' => $slug,
                'archivo' => 'negocios/' . $slug . '/clientes.json'
            ];
            ensureCategoryJson($category);
            if ($categoryIndex === false) {
                $categories[] = $category;
            } else {
                array_splice($categories, $categoryIndex + 1, 0, [$category]);
            }
            saveMapData($data);
            setFlash('exito', 'Categoría creada y conectada al filtro del mapa.');
            redirectToAdmin();
        }

        $id = isset($_POST['id']) ? (string)$_POST['id'] : '';
        $index = null;
        foreach ($categories as $position => $category) {
            if ((isset($category['id']) ? $category['id'] : '') === $id) {
                $index = $position;
                break;
            }
        }
        if ($index === null || $id === 'Todos') {
            throw new RuntimeException('No se encontró esa categoría.');
        }

        if ($action === 'rename') {
            $label = isset($_POST['label']) ? trim((string)$_POST['label']) : '';
            if ($label === '' || textLength($label) > 120) {
                throw new RuntimeException('Escribe un nombre de categoría de hasta 120 caracteres.');
            }
            foreach ($categories as $other) {
                if ((isset($other['id']) ? $other['id'] : '') !== $id && isset($other['label'])
                    && labelKey((string)$other['label']) === labelKey($label)) {
                    throw new RuntimeException('Ya existe otra categoría con ese nombre.');
                }
            }
            $categories[$index]['label'] = $label;
            saveMapData($data);
            setFlash('exito', 'Nombre actualizado. Los filtros siguen conectados mediante el identificador de categoría.');
            redirectToAdmin();
        }

        if ($action === 'delete') {
            $assignedBusinesses = array_filter($data['negocios'], static function ($business) use ($id) {
                return (isset($business['rubro']) ? (string)$business['rubro'] : '') === $id;
            });
            $assignedBusinesses = array_merge($assignedBusinesses, readCategoryBusinesses($categories[$index]));
            if (count($assignedBusinesses) > 0) {
                throw new RuntimeException('No se puede borrar: todavía hay negocios asignados. Cámbialos de categoría desde su admin y vuelve a intentarlo.');
            }
            array_splice($categories, $index, 1);
            saveMapData($data);
            setFlash('exito', 'Categoría eliminada del menú y de los filtros del mapa.');
            redirectToAdmin();
        }

        throw new RuntimeException('Acción no reconocida.');
    } catch (Exception $exception) {
        $error = $exception->getMessage();
    }
}

$flash = isset($_SESSION['flash']) ? $_SESSION['flash'] : null;
unset($_SESSION['flash']);
$categories = [];
$businessCounts = [];
$dataError = '';
$data = [];
try {
    $data = readMapData();
    $categories = array_values(array_filter($data['configuracion']['rubros'], static function ($category) {
        return (isset($category['id']) ? $category['id'] : '') !== 'Todos';
    }));
    foreach ($categories as $category) {
        $categoryBusinesses = array_merge($data['negocios'], readCategoryBusinesses($category));
        foreach ($categoryBusinesses as $business) {
            $businessId = isset($business['rubro']) ? (string)$business['rubro'] : '';
            if ($businessId === (string)$category['id']) {
                $businessCounts[$businessId] = (isset($businessCounts[$businessId]) ? $businessCounts[$businessId] : 0) + 1;
            }
        }
    }
} catch (Exception $exception) {
    $dataError = $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#2563eb">
  <title>Admin de categorías | Celulokos</title>
  <link rel="stylesheet" href="estilos/admin-menu.css">
</head>
<body>
  <header>
    <h1>⚙️ Admin de categorías</h1>
    <nav><a href="admin.php">Administrar clientes</a><a href="admin-etiqueta.php">Apariencia de etiquetas</a><a href="mapa.html">Volver al mapa</a></nav>
  </header>
  <main>
    <?php if ($error !== ''): ?>
      <div class="aviso error" role="alert"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if (is_array($flash)): ?>
      <div class="aviso <?= h(isset($flash['type']) ? (string)$flash['type'] : 'exito') ?>" role="status"><?= h(isset($flash['message']) ? (string)$flash['message'] : '') ?></div>
    <?php endif; ?>

    <div class="aviso">Sin clave por ahora: este panel solo acepta conexiones desde esta computadora y no debe publicarse en internet.</div>
    <?php if ($dataError !== ''): ?>
      <div class="aviso error" role="alert"><?= h($dataError) ?></div>
    <?php else: ?>
        <section class="panel">
          <h2>Categorías del mapa</h2>
          <p class="ayuda">Crea, cambia o elimina las categorías que aparecen como filtros. Al cambiar un nombre, su identificador no cambia y los negocios conservan su filtro.</p>
          <div class="lista-categorias">
            <?php foreach ($categories as $category): ?>
              <?php
                $id = isset($category['id']) ? (string)$category['id'] : '';
                $label = isset($category['label']) ? (string)$category['label'] : $id;
                $count = isset($businessCounts[$id]) ? $businessCounts[$id] : 0;
              ?>
              <article class="categoria">
                <div class="categoria-nombre"><?= h($label) ?></div>
                <div class="categoria-meta"><?= $count ?> negocio(s) vinculados</div>
                <form method="post" action="admin-menu.php">
                  <input type="hidden" name="csrf_token" value="<?= h(makeCsrfToken()) ?>">
                  <input type="hidden" name="action" value="rename">
                  <input type="hidden" name="id" value="<?= h($id) ?>">
                  <label class="campo">Nuevo nombre
                    <input type="text" name="label" maxlength="120" value="<?= h($label) ?>" aria-label="Nuevo nombre para <?= h($label) ?>" required>
                  </label>
                  <button class="boton-secundario" type="submit">Guardar nombre</button>
                </form>
                <form method="post" action="admin-menu.php" onsubmit="return confirm('¿Eliminar esta categoría del menú?');">
                  <input type="hidden" name="csrf_token" value="<?= h(makeCsrfToken()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= h($id) ?>">
                  <button class="boton-peligro" type="submit" <?= $count > 0 ? 'disabled title="Primero cambia de categoría los negocios asignados."' : '' ?>>× Eliminar</button>
                </form>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="panel">
          <h2>Crear categoría</h2>
          <p class="ayuda">La categoría nueva aparecerá como filtro. El mapa se actualiza automáticamente en unos segundos.</p>
          <form method="post" action="admin-menu.php">
            <input type="hidden" name="csrf_token" value="<?= h(makeCsrfToken()) ?>">
            <input type="hidden" name="action" value="add">
            <label class="campo">Nombre del botón de filtro
              <input type="text" name="label" maxlength="120" placeholder="Ej.: 🧸 Jugueterías" required>
            </label>
            <button type="submit">＋ Crear categoría</button>
          </form>
          <p class="pie">Para proteger los filtros actuales, no se puede borrar una categoría que tenga negocios vinculados. La opción de cambiar los negocios de categoría se agregará más adelante.</p>
        </section>
    <?php endif; ?>
  </main>
</body>
</html>
