const RUBRO_TODOS = 'Todos';
const RUTA_DATOS = 'datos/mapa.json';
const RUTA_FOTO_PREDETERMINADA = 'images/fachada-default.png';
const POSICIONES_ETIQUETA = new Set(['top', 'right', 'bottom', 'left', 'center', 'auto']);
const VALORES_ESTILO_ETIQUETA = {
  fondo: 'backgroundColor',
  texto: 'color',
  tamano: 'fontSize',
  peso: 'fontWeight',
  relleno: 'padding',
  radio: 'borderRadius',
  sombra: 'boxShadow'
};

const botonFiltro = document.getElementById('botonFiltro');
const menuFiltros = document.getElementById('menuFiltros');
const visorFoto = document.getElementById('visorFoto');
const imagenAmpliada = document.getElementById('imagenAmpliada');
const cerrarVisor = document.getElementById('cerrarVisor');
const botonCapa = document.getElementById('botonCapa');
const estadoMapa = document.getElementById('estadoMapa');

let mapa;
let negocios = [];
let configuracion = {};
let filtroActual = RUBRO_TODOS;
let zoomFoto = 1;
let fotoActiva = null;
let capaNegocios;
let datosJsonActual = '';

function mostrarError(error) {
  console.error('No se pudo cargar el mapa de negocios.', error);
  estadoMapa.textContent = 'No se pudo cargar el mapa. Comprueba que se esté abriendo desde un servidor web y que el archivo datos/mapa.json esté disponible.';
  estadoMapa.hidden = false;
}

function normalizarNegocios(datos) {
  const negociosBase = [...datos.negocios];
  const negociosGuardados = localStorage.getItem('celulokos_negocios');
  if(negociosGuardados){
    const negociosLocales = JSON.parse(negociosGuardados);
    if(!Array.isArray(negociosLocales)){
      throw new Error('Los negocios guardados en este navegador no tienen un formato válido.');
    }
    negociosLocales.forEach(negocio => {
      const existeEnJson = negociosBase.some(base =>
        (negocio.id && base.id === negocio.id) || (negocio.nombre && base.nombre === negocio.nombre)
      );
      if(!existeEnJson){
        negociosBase.push(negocio);
      }
    });
  }

  return negociosBase.map((negocio, index) => ({
    ...negocio,
    id: negocio.id || `negocio-${index + 1}`,
    etiqueta: negocio.etiqueta || {}
  }));
}

function crearMapa() {
  mapa = L.map('mapa');

  const capaCallejera = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
    attribution: 'Sources: Esri, HERE, Garmin, USGS, Intermap, INCREMENT P, NRCan, Esri Japan, METI, Esri China (Hong Kong), Esri Korea, Esri (Thailand), NGCC, &copy; OpenStreetMap contributors, and the GIS User Community.',
    maxZoom: 19
  }).addTo(mapa);
  const capaSatelital = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    attribution: 'Tiles &copy; Esri'
  });

  mapa.fitBounds(L.latLngBounds([5.286, -73.838], [5.335, -73.785]), {padding: [16, 16], maxZoom: 14});
  botonCapa.addEventListener('click', () => {
    const mostrarSatelite = !mapa.hasLayer(capaSatelital);
    if(mostrarSatelite){
      mapa.removeLayer(capaCallejera);
      capaSatelital.addTo(mapa);
    }else{
      mapa.removeLayer(capaSatelital);
      capaCallejera.addTo(mapa);
    }
    botonCapa.textContent = mostrarSatelite ? '🗺️ Ver callejero' : '🛰️ Ver satélite';
    botonCapa.setAttribute('aria-pressed', String(mostrarSatelite));
  });

  capaNegocios = L.layerGroup().addTo(mapa);
}

function aplicarEstiloVentana() {
  const estilo = configuracion.ventanaFlotante || {};
  const propiedades = {
    '--ventana-ancho': [estilo.ancho, '260px'],
    '--ventana-alto-foto': [estilo.altoFoto, '100px'],
    '--ventana-alto-maximo': [estilo.altoMaximo, '420px'],
    '--ventana-relleno': [estilo.relleno, '13px'],
    '--ventana-radio': [estilo.radio, '12px'],
    '--ventana-radio-foto': [estilo.radioFoto, '8px'],
    '--ventana-radio-boton': [estilo.radioBoton, '8px'],
    '--ventana-espaciado': [estilo.espaciado, '5px'],
    '--ventana-fondo': [estilo.fondo, '#ffffff'],
    '--ventana-texto': [estilo.texto, '#172033'],
    '--ventana-nombre': [estilo.nombre, '#172033'],
    '--ventana-descripcion': [estilo.descripcion, '#475569'],
    '--ventana-detalles': [estilo.detalles, '#334155'],
    '--ventana-enlaces': [estilo.enlaces, '#0284c7'],
    '--ventana-boton-fondo': [estilo.botonFondo, '#1e293b'],
    '--ventana-boton-texto': [estilo.botonTexto, '#0ea5e9'],
    '--ventana-boton-inactivo-fondo': [estilo.botonDesactivadoFondo, '#94a3b8'],
    '--ventana-boton-inactivo-texto': [estilo.botonDesactivadoTexto, '#f8fafc'],
    '--ventana-texto-tamano': [estilo.tamanoTexto, '13px'],
    '--ventana-nombre-tamano': [estilo.tamanoNombre, '14px'],
    '--ventana-boton-tamano': [estilo.tamanoBoton, '13px'],
    '--ventana-sombra': [estilo.sombra, '0 4px 16px #0004']
  };
  Object.entries(propiedades).forEach(([propiedad, valores]) => {
    const valor = valores[0];
    document.documentElement.style.setProperty(
      propiedad,
      (typeof valor === 'number' ? `${valor}px` : (typeof valor === 'string' && valor ? valor : valores[1]))
    );
  });
}

function crearEtiqueta(negocio) {
  const etiquetaPredeterminada = configuracion.etiquetaPredeterminada || {};
  const estilo = etiquetaPredeterminada.estilo || {};
  const posicion = etiquetaPredeterminada.posicion;
  const desplazamiento = etiquetaPredeterminada.desplazamiento;
  const textoEtiqueta = document.createElement('span');
  textoEtiqueta.className = 'texto-etiqueta';
  textoEtiqueta.textContent = negocio.nombre;

  Object.entries(VALORES_ESTILO_ETIQUETA).forEach(([clave, propiedad]) => {
    const valor = estilo[clave];
    if(typeof valor === 'string' || typeof valor === 'number'){
      textoEtiqueta.style[propiedad] = String(valor);
    }
  });

  return {
    elemento: textoEtiqueta,
    opciones: {
      permanent: true,
      direction: POSICIONES_ETIQUETA.has(posicion) ? posicion : 'bottom',
      offset: Array.isArray(desplazamiento) && desplazamiento.length === 2 ? desplazamiento : [0, 18],
      className: 'negocio-tooltip'
    }
  };
}

function abrirFoto(event, foto) {
  event.stopPropagation();
  fotoActiva = foto;
  imagenAmpliada.src = foto.src;
  imagenAmpliada.alt = foto.alt;
  cambiarZoom(1);
  visorFoto.hidden = false;
  cerrarVisor.focus();
}

function crearContenidoNegocio(negocio) {
  const contenido = document.createElement('div');
  contenido.className = 'contenido-negocio';

  const foto = document.createElement('img');
  foto.className = 'foto-negocio';
  foto.src = negocio.foto || RUTA_FOTO_PREDETERMINADA;
  foto.alt = `Fachada de ${negocio.nombre}`;
  foto.tabIndex = 0;
  foto.setAttribute('role', 'button');
  foto.addEventListener('click', event => abrirFoto(event, foto));
  foto.addEventListener('keydown', event => {
    if(event.key === 'Enter' || event.key === ' '){
      event.preventDefault();
      abrirFoto(event, foto);
    }
  });

  const nombre = document.createElement('strong');
  nombre.className = 'nombre-negocio';
  nombre.textContent = negocio.nombre;

  const comentario = document.createElement('p');
  comentario.className = 'comentario-negocio';
  comentario.textContent = negocio.comentario || '';

  const direccion = document.createElement('p');
  direccion.className = 'direccion-negocio';
  direccion.textContent = negocio.direccion || '';

  const telefono = negocio.whatsapp || configuracion.telefonoPredeterminado || '';
  const telefonoVisible = document.createElement('p');
  telefonoVisible.className = 'telefono-negocio';
  telefonoVisible.textContent = `Teléfono: ${telefono}`;

  const llegar = document.createElement('a');
  llegar.textContent = 'Cómo llegar';
  llegar.href = `https://www.google.com/maps/dir/?api=1&destination=${negocio.lat},${negocio.lng}`;
  llegar.target = '_blank';
  llegar.rel = 'noopener noreferrer';

  const acciones = document.createElement('div');
  acciones.className = 'acciones-negocio';
  acciones.append(llegar);

  if(telefono && negocio.whatsappActivo !== false){
    const enlaceWhatsapp = document.createElement('a');
    enlaceWhatsapp.textContent = 'Escribir al WhatsApp';
    enlaceWhatsapp.href = `https://wa.me/${encodeURIComponent(telefono)}?text=${encodeURIComponent('Hola, te vi en el mapa de Celulokos')}`;
    enlaceWhatsapp.target = '_blank';
    enlaceWhatsapp.rel = 'noopener noreferrer';
    acciones.append(enlaceWhatsapp);
  }else{
    const whatsappDesactivado = document.createElement('span');
    whatsappDesactivado.className = 'whatsapp-desactivado';
    whatsappDesactivado.textContent = 'Escribir al WhatsApp';
    whatsappDesactivado.setAttribute('aria-disabled', 'true');
    acciones.append(whatsappDesactivado);
  }

  contenido.append(foto, nombre);
  if(negocio.comentario){
    contenido.append(comentario);
  }
  if(negocio.direccion){
    contenido.append(direccion);
  }
  if(telefono){
    contenido.append(telefonoVisible);
  }
  contenido.append(acciones);
  if(negocio.pagina){
    const enlaceWeb = document.createElement('a');
    enlaceWeb.className = 'boton-web';
    enlaceWeb.textContent = 'Entra a mi web';
    enlaceWeb.href = encodeURI(negocio.pagina);
    enlaceWeb.target = '_blank';
    enlaceWeb.rel = 'noopener noreferrer';
    contenido.append(enlaceWeb);
  }else{
    const enlaceWeb = document.createElement('button');
    enlaceWeb.className = 'boton-web';
    enlaceWeb.type = 'button';
    enlaceWeb.textContent = 'Entra a mi web';
    enlaceWeb.disabled = true;
    contenido.append(enlaceWeb);
  }

  return contenido;
}

function mostrarNegocios() {
  capaNegocios.clearLayers();
  negocios
    .filter(negocio => negocio.activo !== false)
    .filter(negocio => configuracion.rubros.some(rubro => rubro.id === negocio.rubro))
    .filter(negocio => filtroActual === RUBRO_TODOS || negocio.rubro === filtroActual)
    .filter(negocio => Number.isFinite(Number(negocio.lat)) && Number.isFinite(Number(negocio.lng)))
    .forEach(negocio => {
      const marcador = L.marker([Number(negocio.lat), Number(negocio.lng)]).addTo(capaNegocios);
      const etiqueta = crearEtiqueta(negocio);
      marcador.bindTooltip(etiqueta.elemento, etiqueta.opciones);
      marcador.bindPopup(crearContenidoNegocio(negocio), {
        className: 'ventana-negocio',
        maxWidth: 560,
        minWidth: 0
      });
    });
}

function cambiarZoom(valor) {
  zoomFoto = Math.min(4, Math.max(1, valor));
  imagenAmpliada.style.setProperty('--zoom', zoomFoto);
}

function cerrarFoto() {
  visorFoto.hidden = true;
  imagenAmpliada.removeAttribute('src');
  if(fotoActiva) fotoActiva.focus();
}

function actualizarOpcionesFiltro() {
  const rubros = configuracion.rubros || [];
  menuFiltros.replaceChildren();
  rubros.forEach(rubro => {
    const opcion = document.createElement('button');
    opcion.className = 'opcion-filtro';
    opcion.type = 'button';
    opcion.setAttribute('role', 'menuitem');
    opcion.dataset.rubro = rubro.id;
    opcion.textContent = rubro.label;
    opcion.classList.toggle('activo', rubro.id === filtroActual);
    menuFiltros.append(opcion);
  });

  const rubroActual = rubros.find(rubro => rubro.id === filtroActual);
  botonFiltro.textContent = filtroActual === RUBRO_TODOS ? 'Todos' : (rubroActual?.label || filtroActual);
}

function configurarControles() {
  actualizarOpcionesFiltro();

  botonFiltro.addEventListener('click', () => {
    const abierto = botonFiltro.getAttribute('aria-expanded') === 'true';
    botonFiltro.setAttribute('aria-expanded', String(!abierto));
    menuFiltros.hidden = abierto;
  });

  menuFiltros.addEventListener('click', event => {
    const opcion = event.target.closest('button[data-rubro]');
    if(!opcion) return;

    filtroActual = opcion.dataset.rubro;
    actualizarOpcionesFiltro();
    menuFiltros.hidden = true;
    botonFiltro.setAttribute('aria-expanded', 'false');
    mostrarNegocios();
  });

  document.addEventListener('click', event => {
    if(!event.target.closest('.barra-filtros')){
      menuFiltros.hidden = true;
      botonFiltro.setAttribute('aria-expanded', 'false');
    }
  });

  cerrarVisor.addEventListener('click', cerrarFoto);
  visorFoto.addEventListener('click', event => {
    if(event.target === visorFoto) cerrarFoto();
  });
  document.getElementById('acercarFoto').addEventListener('click', () => cambiarZoom(zoomFoto + 0.5));
  document.getElementById('alejarFoto').addEventListener('click', () => cambiarZoom(zoomFoto - 0.5));
  document.getElementById('restablecerZoom').addEventListener('click', () => cambiarZoom(1));
  visorFoto.addEventListener('wheel', event => {
    event.preventDefault();
    cambiarZoom(zoomFoto + (event.deltaY < 0 ? 0.25 : -0.25));
  }, {passive: false});

  document.addEventListener('keydown', event => {
    if(event.key === 'Escape' && !visorFoto.hidden){
      cerrarFoto();
      return;
    }
    if(!visorFoto.hidden && (event.key === '+' || event.key === '=')) cambiarZoom(zoomFoto + 0.25);
    if(!visorFoto.hidden && event.key === '-') cambiarZoom(zoomFoto - 0.25);
    if(event.key === 'Escape'){
      menuFiltros.hidden = true;
      botonFiltro.setAttribute('aria-expanded', 'false');
      botonFiltro.focus();
    }
  });
}

async function sincronizarDatosMapa() {
  if(document.visibilityState === 'hidden') return;

  try{
    const datos = await cargarDatosCompletos('no-store');
    const contenido = JSON.stringify(datos);
    if(contenido === datosJsonActual) return;

    datosJsonActual = contenido;
    configuracion = datos.configuracion;
    aplicarEstiloVentana();
    negocios = normalizarNegocios(datos);
    if(!configuracion.rubros.some(rubro => rubro.id === filtroActual)){
      filtroActual = RUBRO_TODOS;
    }
    actualizarOpcionesFiltro();
    mostrarNegocios();
  }catch(error){
    console.error('No se pudieron sincronizar los datos del mapa.', error);
  }
}

async function iniciarMapa() {
  try{
    const datos = await cargarDatosCompletos('no-cache');
    datosJsonActual = JSON.stringify(datos);
    configuracion = datos.configuracion;
    aplicarEstiloVentana();
    negocios = normalizarNegocios(datos);
    crearMapa();
    configurarControles();
    mostrarNegocios();
    window.setInterval(sincronizarDatosMapa, 15000);
    document.addEventListener('visibilitychange', sincronizarDatosMapa);
    window.addEventListener('focus', sincronizarDatosMapa);
  }catch(error){
    mostrarError(error);
  }
}

async function cargarDatosCompletos(cache) {
  const respuesta = await fetch(RUTA_DATOS, {cache});
  if(!respuesta.ok) throw new Error(`Error HTTP ${respuesta.status} al cargar ${RUTA_DATOS}`);
  const datos = await respuesta.json();
  if(!datos || !Array.isArray(datos.negocios) || !datos.configuracion || !Array.isArray(datos.configuracion.rubros)){
    throw new Error('El archivo de datos del mapa no tiene la estructura esperada.');
  }

  const categorias = datos.configuracion.rubros.filter(rubro => rubro.id !== RUBRO_TODOS && rubro.archivo);
  const grupos = await Promise.all(categorias.map(async rubro => {
    const respuestaCategoria = await fetch(rubro.archivo, {cache});
    if(!respuestaCategoria.ok){
      throw new Error(`Error HTTP ${respuestaCategoria.status} al cargar ${rubro.archivo}`);
    }
    const contenidoCategoria = await respuestaCategoria.json();
    if(!contenidoCategoria || !Array.isArray(contenidoCategoria.negocios)){
      throw new Error(`El archivo ${rubro.archivo} no tiene una lista de negocios válida.`);
    }
    return contenidoCategoria.negocios;
  }));
  datos.negocios = datos.negocios.concat(...grupos);
  return datos;
}

iniciarMapa();
