# Mapa de negocios

## Archivos

- `mapa.html`: estructura de la página.
- `estilos/mapa.css`: apariencia del mapa, las fichas y las etiquetas.
- `scripts/mapa.js`: filtros, marcadores, capas, fichas y visor de fotos.
- `datos/mapa.json`: configuración, categorías y estilo predeterminado de las etiquetas.
- `negocios/<categoria>/clientes.json`: puntos y datos de los negocios de cada categoría.
- `admin.php` y `estilos/admin-clientes.css`: panel PHP local para agregar, editar, ocultar, mostrar y eliminar clientes.
- `admin-etiqueta.php` y `estilos/admin-etiqueta.css`: panel PHP local para personalizar globalmente las etiquetas y las ventanas flotantes del mapa.
- `admin-menu.php` y `estilos/admin-menu.css`: panel PHP para crear, renombrar y borrar categorías sin clientes asignados.
- `negocios/panaderias/panadería-La-Unión/estilos/panaderia.css`: apariencia del sitio de la panadería.

Cada negocio tiene un `id` estable y sus propios datos, incluidos ubicación, foto, WhatsApp y `whatsappActivo`, comentario, página y etiqueta. El admin de clientes permite activar o desactivar el botón «Escribir al WhatsApp» de la ventana flotante. En registros anteriores que no tengan `whatsappActivo`, el botón permanece activo por compatibilidad. La carpeta de cada cliente se crea dentro de su categoría; las fotos se guardan en una subcarpeta `images`. La configuración de `etiquetaPredeterminada` se aplica cuando un punto no define un valor propio; cada etiqueta puede personalizar su posición, desplazamiento y estilo.

## Abrir el mapa

El mapa carga los datos JSON por HTTP, así que no debe abrirse haciendo doble clic en `mapa.html`. El mapa público se puede abrir desde el servidor Node con `npm start` desde la carpeta principal en `http://localhost:3000/mapa/mapa.html`. Node no ejecuta PHP y oculta los archivos PHP para no exponer su código.

Al pulsar «Cómo llegar» en la ficha de un negocio, el mapa solicita permiso para usar la ubicación del dispositivo y dibuja la ruta con sus indicaciones. La ubicación requiere HTTPS (o `localhost` durante el desarrollo), que el usuario conceda el permiso y conexión al servicio público de rutas OSRM. Si no se puede obtener la ubicación o calcular la ruta, se navega a Google Maps en la misma pestaña con el destino seleccionado; no se abre una pestaña emergente. Como la ruta depende de servicios externos, no se puede garantizar el cálculo sin conexión o si el servicio de rutas no está disponible. Las páginas añaden una versión a la URL del script para que los navegadores no reutilicen una copia anterior en caché después de publicar cambios.

## Administradores locales

El panel no pide clave durante esta etapa y está limitado a conexiones locales. Necesita PHP 5.4 o posterior; en PowerShell, desde la carpeta principal del proyecto, arranca el servidor PHP:

```powershell
& "C:\laragon-portable\bin\php\php-5.4.9-nts-Win32-VC9-x86\php.exe" -S 127.0.0.1:8080 -t mapa
```

Después abre `http://127.0.0.1:8080/admin.php` para administrar clientes, `http://127.0.0.1:8080/admin-menu.php` para administrar categorías o `http://127.0.0.1:8080/admin-etiqueta.php` para cambiar la apariencia global de las etiquetas. PHP necesita permiso de escritura en `datos/mapa.json`, en los JSON dentro de `negocios/` y en las carpetas de esa sección.

El admin de clientes permite colocar coordenadas haciendo clic en el mapa, agregar una categoría y su carpeta al crear un cliente, subir la fachada, mantener una futura ruta web y activar/desactivar el punto. La eliminación de un cliente también borra su carpeta, fotos y página web; confirma que quieres perder esos archivos antes de usar esa acción. Las categorías con clientes asignados no se pueden borrar. El panel de apariencia cambia globalmente la posición y estilo de las etiquetas y las dimensiones, colores, espaciado, bordes y sombra de las ventanas flotantes de todos los clientes. Los formularios están protegidos con token CSRF.

Ambos administradores se enlazan a `127.0.0.1` y rechazan conexiones que no sean locales. **No publiques `admin.php` ni `admin-menu.php` ni los expongas a internet o a la red local.** Para publicar los cambios, sube los JSON de las categorías y las carpetas de clientes/fotos o páginas correspondientes, además de los archivos del mapa que hayas cambiado.

El mapa comprueba la configuración y los JSON de categorías cada 15 segundos y al volver a la pestaña, así los nuevos filtros y cambios guardados aparecen sin recargar la página. Los negocios de los JSON son la fuente principal; los negocios adicionales de `localStorage` se conservan por compatibilidad. El campo `activo` permite ocultar un punto del mapa cuando sea `false` sin borrar sus datos. El botón «Entra a mi web» aparece desactivado hasta que se indique una ruta o URL.
