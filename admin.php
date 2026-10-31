<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Bogota');

$dbFile = __DIR__ . '/database.php';
$uploadDir = __DIR__ . '/images/';

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Leer Base de Datos de Texto Plano de forma segura
$data = file_exists($dbFile) ? include($dbFile) : array();

if (!is_array($data) || empty($data)) {
    $data = array(
        'empresa' => array('nombre' => 'Sazón Urbano', 'eslogan' => 'Sabores que conectan', 'whatsapp' => '+573219163532'),
        'productos' => array()
    );
}

// FORMULARIO 1: Actualizar datos de la Empresa (Incluyendo el Logo)
if (isset($_POST['action']) && $_POST['action'] === 'update_empresa') {
    $data['empresa']['nombre'] = isset($_POST['nombre']) ? $_POST['nombre'] : $data['empresa']['nombre'];
    $data['empresa']['eslogan'] = isset($_POST['eslogan']) ? $_POST['eslogan'] : $data['empresa']['eslogan'];
    $data['empresa']['whatsapp'] = isset($_POST['whatsapp']) ? $_POST['whatsapp'] : $data['empresa']['whatsapp'];
    
    // Lógica para procesar y guardar la foto del logo en la carpeta images/
    if (isset($_FILES['logo_empresa']) && $_FILES['logo_empresa']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['logo_empresa']['name'], PATHINFO_EXTENSION);
        $fileName = 'logo_' . time() . '_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($_FILES['logo_empresa']['tmp_name'], $uploadDir . $fileName)) {
            $data['empresa']['logo'] = 'images/' . $fileName;
        }
    }
    
    file_put_contents($dbFile, '<?php ' . PHP_EOL . 'return ' . var_export($data, true) . ';');
    header("Location: admin.php?msg=Empresa Actualizada");
    exit;
}


// FORMULARIO 2: Agregar o Modificar Producto
if (isset($_POST['action']) && $_POST['action'] === 'save_product') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $nombre = isset($_POST['nombre']) ? $_POST['nombre'] : '';
    $descripcion = isset($_POST['descripcion']) ? $_POST['descripcion'] : '';
    $precio = isset($_POST['precio']) ? (float)$_POST['precio'] : 0;
    $categoria = isset($_POST['categoria']) ? $_POST['categoria'] : 'Platos';
    
    $imagePath = '';
    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION);
        $fileName = time() . '_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($_FILES['imagen']['tmp_name'], $uploadDir . $fileName)) {
            $imagePath = 'images/' . $fileName;
        }
    }

    if ($id > 0) {
        foreach ($data['productos'] as &$prod) {
            if ($prod['id'] === $id) {
                $prod['nombre'] = $nombre;
                $prod['descripcion'] = $descripcion;
                $prod['precio'] = $precio;
                $prod['categoria'] = $categoria;
                if ($imagePath !== '') { $prod['imagen'] = $imagePath; }
                break;
            }
        }
    } else {
        $maxId = 0;
        foreach ($data['productos'] as $prod) {
            if ($prod['id'] > $maxId) { $maxId = $prod['id']; }
        }
        $newId = $maxId + 1;
        
        $data['productos'][] = array(
            "id" => $newId,
            "nombre" => $nombre,
            "descripcion" => $descripcion,
            "precio" => $precio,
            "imagen" => $imagePath != '' ? $imagePath : 'images/default.jpg',
            "categoria" => $categoria
        );
    }
    
    file_put_contents($dbFile, '<?php ' . PHP_EOL . 'return ' . var_export($data, true) . ';');
    header("Location: admin.php?msg=Producto Guardado");
    exit;
}

// ACCIÓN 3: Eliminar Producto
if (isset($_GET['delete'])) {
    $idDelete = (int)$_GET['delete'];
    $nuevosProductos = array();
    foreach ($data['productos'] as $prod) {
        if ($prod['id'] !== $idDelete) { $nuevosProductos[] = $prod; }
    }
    $data['productos'] = $nuevosProductos;
    
    file_put_contents($dbFile, '<?php ' . PHP_EOL . 'return ' . var_export($data, true) . ';');
    header("Location: admin.php?msg=Producto Eliminado");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Administrador - Moderno</title>
    <style>
        :root {
            --bg-main: #121824;
            --bg-card: #1c2434;
            --accent: #ff4757;
            --accent-hover: #ff6b81;
            --text-white: #ffffff;
            --text-muted: #a0aec0;
            --input-bg: #2d3748;
            --success: #00e676;
        }
        body { font-family: 'Segoe UI', Roboto, sans-serif; background: var(--bg-main); margin: 0; padding: 20px; color: var(--text-white); }
        .box { background: var(--bg-card); padding: 25px; border-radius: 14px; border: 1px solid #2d3748; margin-bottom: 25px; max-width: 600px; margin-left: auto; margin-right: auto; }
        h2 { margin-top: 0; font-size: 1.3rem; color: var(--text-white); border-bottom: 2px solid #2d3748; padding-bottom: 10px; margin-bottom: 20px;}
        label { display: block; margin: 15px 0 6px; font-weight: 600; font-size: 0.9rem; color: var(--text-muted); }
        input, select, textarea { width: 100%; padding: 12px; background: var(--input-bg); border: 1px solid #4a5568; border-radius: 8px; color: var(--text-white); box-sizing: border-box; font-size: 0.95rem; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--accent); }
        button { background: var(--accent); color: white; border: none; padding: 14px; border-radius: 8px; cursor: pointer; font-weight: bold; width: 100%; margin-top: 20px; font-size: 1rem; transition: background 0.2s; }
        button:hover { background: var(--accent-hover); }
        .prod-row { display: flex; align-items: center; justify-content: space-between; background: #252f44; padding: 12px; border-radius: 10px; margin-bottom: 10px; border: 1px solid #2d3748; }
        .prod-info { display: flex; align-items: center; gap: 15px; }
        .prod-thumb { width: 55px; height: 55px; object-fit: cover; border-radius: 8px; border: 1px solid #4a5568; }
        .actions { display: flex; gap: 12px; align-items: center; }
        .btn-edit { background: #319795; color: white; border: none; padding: 7px 14px; cursor: pointer; font-size: 0.85rem; border-radius: 6px; font-weight: bold; }
        .btn-edit:hover { background: #4db6ac; }
        .close-x { color: var(--accent); font-weight: bold; cursor: pointer; font-size: 1.7rem; text-decoration: none; padding: 0 5px; transition: color 0.2s; }
        .close-x:hover { color: var(--accent-hover); }
        .alert { background: rgba(0, 230, 118, 0.15); color: var(--success); padding: 12px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; text-align: center; border: 1px solid var(--success); max-width: 600px; margin-left: auto; margin-right: auto; }
    </style>
</head>
<body>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert"><?php echo htmlspecialchars($_GET['msg']); ?></div>
    <?php endif; ?>
    <!-- Configuración Empresa -->
    <div class="box">
        <h2>Configuración del Negocio</h2>
        <form action="admin.php" method="POST">
            <input type="hidden" name="action" value="update_empresa">
            
            <label>Nombre de la Empresa</label>
            <input type="text" name="nombre" value="<?php echo htmlspecialchars(isset($data['empresa']['nombre']) ? $data['empresa']['nombre'] : ''); ?>" required>
            
            <label>Eslogan</label>
            <input type="text" name="eslogan" value="<?php echo htmlspecialchars(isset($data['empresa']['eslogan']) ? $data['empresa']['eslogan'] : ''); ?>" required>
            
            <label>WhatsApp de Recepción (Formato: +57...)</label>
            <input type="text" name="whatsapp" value="<?php echo htmlspecialchars(isset($data['empresa']['whatsapp']) ? $data['empresa']['whatsapp'] : ''); ?>" required>
            
<!-- NUEVO CAMPO: Casilla para cambiar la foto del Logo -->
            <label>Logo de la Empresa</label>
            <input type="file" name="logo_empresa" accept="image/*">

            <button type="submit">Guardar Datos Generales</button>
        </form>
    </div>

    <!-- Agregar / Modificar Producto -->
    <div class="box">
        <h2 id="form-title">Agregar Nuevo Producto</h2>
        <form action="admin.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" id="prod-id" name="id" value="0">
            
            <label>Nombre del Producto</label>
            <input type="text" id="prod-nombre" name="nombre" required>
            
            <label>Ingredientes / Descripción</label>
            <textarea id="prod-desc" name="descripcion" rows="2" required></textarea>
            
            <label>Precio (COP)</label>
            <input type="number" id="prod-precio" name="precio" required>

            <label>Categoría</label>
            <select id="prod-categoria" name="categoria">
                <option value="Platos">Platos</option>
                <option value="Bebidas">Bebidas</option>
                <option value="Domicilio">Domicilio</option>
            </select>
            
            <label>Foto del Producto</label>
            <input type="file" name="imagen" accept="image/*">
            
            <button type="submit" style="background: var(--accent);">Guardar Producto</button>
            <button type="button" id="btn-cancelar" style="background: #4a5568; display:none;" onclick="resetProductForm()">Cancelar Edición</button>
        </form>
    </div>

    <!-- Listado de Productos Activos -->
    <div class="box">
        <h2>Lista de Productos</h2>
        <div id="lista-productos">
            <?php if (!empty($data['productos'])): ?>
                <?php foreach ($data['productos'] as $p): ?>
                    <div class="prod-row">
                        <div class="prod-info">
                            <img src="<?php echo htmlspecialchars($p['imagen']); ?>" class="prod-thumb" onerror="this.src='https://placeholder.com'">
                            <div>
                                <strong><?php echo htmlspecialchars($p['nombre']); ?></strong> 
                                <small style="color: var(--text-muted)">(<?php echo htmlspecialchars($p['categoria']); ?>)</small><br>
                                <span style="color: var(--success)">$<?php echo number_format($p['precio']); ?></span>
                            </div>
                        </div>
                        <div class="actions">
                            <button class="btn-edit" onclick='editProduct(<?php echo json_encode($p); ?>)'>Modificar</button>
                            <a href="admin.php?delete=<?php echo $p['id']; ?>" class="close-x" onclick="return confirm('¿Seguro que deseas eliminar este producto?')">&times;</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: var(--text-muted); text-align:center;">No hay productos registrados en la base de datos.</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function editProduct(p) {
            document.getElementById('form-title').innerText = "Modificar Producto: " + p.nombre;
            document.getElementById('prod-id').value = p.id;
            document.getElementById('prod-nombre').value = p.nombre;
            document.getElementById('prod-desc').value = p.descripcion;
            document.getElementById('prod-precio').value = p.precio;
            document.getElementById('prod-categoria').value = p.categoria;
            document.getElementById('btn-cancelar').style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function resetProductForm() {
            document.getElementById('form-title').innerText = "Agregar Nuevo Producto";
            document.getElementById('prod-id').value = "0";
            document.getElementById('prod-nombre').value = "";
            document.getElementById('prod-desc').value = "";
            document.getElementById('prod-precio').value = "";
            document.getElementById('prod-categoria').value = "Platos";
            document.getElementById('btn-cancelar').style.display = 'none';
        }
    </script>
</body>
</html>
