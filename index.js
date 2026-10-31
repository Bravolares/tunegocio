let currentCategory = 'Platos';
let products = [];
let configData = {};
let selectedProducts = {};

// Inicializar la carga asíncrona de datos desde la API
// Inicializar la carga asíncrona de datos desde el archivo JSON local
async function init() {
    try {
        // CAMBIO AQUÍ: Llamamos directamente al archivo JSON que creamos antes
        const resp = await fetch('productos.json');
        configData = await resp.json();
        products = configData.productos || [];
        
        if (configData.empresa) {
            document.getElementById('emp-nombre').innerText = configData.empresa.nombre || "Sazón Urbano";
            document.getElementById('emp-eslogan').innerText = configData.empresa.eslogan || "Sabores que conectan";
            
            const rutaLogo = configData.empresa.logo || "";
            
            if (rutaLogo !== "") {
                document.getElementById('emp-logo').src = rutaLogo + "?v=" + new Date().getTime();
            } else {
                document.getElementById('emp-logo').src = "";
            }
            
            document.getElementById('emp-logo').onerror = function() {
                this.src = "https://picsum.photos"; 
            };
        }

        renderCatalog();
    } catch (error) {
        console.error("Error cargando los datos del archivo JSON:", error);
    }
}



// Pintar dinámicamente las tarjetas del catálogo según la pestaña
function renderCatalog() {
    const container = document.getElementById('catalog-container');
    container.innerHTML = '';
    
    const filtered = products.filter(p => p.categoria === currentCategory);
    
    if (filtered.length === 0) {
        container.innerHTML = '<p style="grid-column: 1/-1; text-align: center; color: var(--text-muted); padding: 20px;">No hay productos en esta categoría.</p>';
        return;
    }

    filtered.forEach(p => {
        const itemSeleccionado = selectedProducts[p.id];
        const isChecked = itemSeleccionado ? 'checked' : '';
        const cantidad = itemSeleccionado ? itemSeleccionado.cantidad : 1;
        
        // Controladores numéricos (+ y -) flotantes individuales
        const selectorCantidadHTML = itemSeleccionado ? `
            <div class="quantity-control" onclick="event.stopPropagation()">
                <button class="qty-btn minus" onclick="changeQty(${p.id}, -1)">-</button>
                <span style="font-weight: bold; font-size: 14px; min-width: 14px; text-align: center;">${cantidad}</span>
                <button class="qty-btn plus" onclick="changeQty(${p.id}, 1)">+</button>
            </div>
        ` : '';

        // Si el producto está seleccionado, guarda lo que escribe en tiempo real en la propiedad .nota
        const notaHTML = itemSeleccionado ? `
            <input type="text" 
                   class="product-note-input" 
                   placeholder="Notas (Algún cambio sobre esta seleccion?)..." 
                   value="${itemSeleccionado.nota || ''}" 
                   onclick="event.stopPropagation()" 
                   oninput="selectedProducts[${p.id}].nota = this.value">
        ` : '';

        const card = document.createElement('div');
        card.className = 'product-card';
        card.innerHTML = `
            <img src="${p.imagen}" class="product-img" onerror="this.src='https://placeholder.com' + encodeURIComponent('${p.nombre}')" onclick="openModal('${p.nombre}', '${p.descripcion}', this.src)">
            <div class="product-title">${p.nombre}</div>
            <div class="product-desc">${p.descripcion}</div>
            
            <!-- Aquí va la cajita de la nota -->
            ${notaHTML}
            
            <div class="product-footer">
                <div class="product-price">$${parseInt(p.precio).toLocaleString()}</div>
                <div style="display: flex; align-items: center;">
                    ${selectorCantidadHTML}
                    <div class="check-box ${isChecked}" onclick="toggleSelect(${p.id}, ${p.precio})"></div>
                </div>
            </div>
        `;

        container.appendChild(card);
    });
}

// Cambiar de categoría al presionar las pestañas
function filterCategory(cat, btn) {
    currentCategory = cat;
    document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    renderCatalog();
}

// Lógica de alternancia del cuadro de selección de dos columnas
function toggleSelect(id, precio) {
    if (selectedProducts[id]) {
        delete selectedProducts[id];
    } else {
        const item = products.find(p => p.id === id);
        selectedProducts[id] = { nombre: item.nombre, precio: parseInt(precio), cantidad: 1, nota: "" };
    }
    calculateTotal();
    renderCatalog();
}

// Modificar la cantidad de unidades pedidas por producto
function changeQty(id, delta) {
    if (selectedProducts[id]) {
        selectedProducts[id].cantidad += delta;
        if (selectedProducts[id].cantidad <= 0) {
            delete selectedProducts[id];
        }
        calculateTotal();
        renderCatalog();
    }
}

// Calcular de forma automática los incrementos o decrementos de precio
function calculateTotal() {
    let total = 0;
    for (let id in selectedProducts) {
        total += selectedProducts[id].precio * selectedProducts[id].amount || selectedProducts[id].precio * selectedProducts[id].cantidad;
    }
    document.getElementById('total-val').innerText = total.toLocaleString();
}

// Desplegar modal flotante detallado
function openModal(title, desc, img) {
    document.getElementById('modal-title').innerText = title;
    document.getElementById('modal-desc').innerText = desc;
    document.getElementById('modal-img').src = img;
    document.getElementById('product-modal').classList.add('open');
}

// Cerrar ventana flotante
function closeModal() {
    document.getElementById('product-modal').classList.remove('open');
}

// Procesar y enviar el pedido acumulado estructurado por WhatsApp
function sendOrder() {
    var items = Object.values(selectedProducts);
    if (items.length === 0) {
        alert("Por favor selecciona al menos un producto.");
        return;
    }
    
    var message = "*Pedido desde el Menú Digital*\n\n";
    var total = 0;
    
    items.forEach(function(i) {
        var subtotal = i.precio * i.cantidad;
        message += "• (" + i.cantidad + "x) " + i.nombre + " - $" + subtotal.toLocaleString() + " COP\n";
        
        if (i.nota && i.nota.trim() !== "") {
            message += "  _" + i.nota.trim() + "_\n";
        }
        
        total += subtotal;
    });
    
    message += "\n*Total a Pagar:* $" + total.toLocaleString() + " COP";
    
    var rawPhone = (configData.empresa && configData.empresa.whatsapp) ? configData.empresa.whatsapp : "573219163532";
    var phone = rawPhone.replace(/[^0-9]/g, '');
    
    var url = "https://wa.me/" + phone + "?text=" + encodeURIComponent(message);
    
    var link = document.createElement('a');
    link.href = url;
    link.target = '_blank';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Funciones globales para controlar el modal del logo
function openLogoModal() {
    const logoSrc = document.getElementById('emp-logo').src;
    const empNombre = document.getElementById('emp-nombre').innerText;
    
    document.getElementById('logo-modal-title').innerText = empNombre;
    document.getElementById('logo-modal-img').src = logoSrc;
    document.getElementById('logo-modal').classList.add('open');
}

function closeLogoModal() {
    document.getElementById('logo-modal').classList.remove('open');
}

window.onload = init;
