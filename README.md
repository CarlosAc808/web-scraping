# Maxun Console — Backend

API REST para la interfaz React de `../Fronted`, implementada con Laravel 12 y Sanctum. Los robots se guardan en MySQL; al crearlos, el backend consulta el sitio HTTPS y persiste los productos encontrados mediante metadatos Open Graph, microdatos Schema.org y JSON-LD.

## Requisitos

- PHP 8.2 o superior con `curl`, `dom`, `mbstring`, `openssl`, `pdo_mysql` y `json`
- Composer
- MySQL 8 compatible, escuchando en `127.0.0.1:3306`

## Configuración local

1. Crea la base de datos vacía `webscraping` desde MySQL Workbench si aún no existe.
2. Copia `.env.example` como `.env` y completa `DB_PASSWORD` localmente. `.env` está excluido de Git; no publiques sus credenciales.
3. Ejecuta desde `Backend`:

```bash
composer install
php artisan key:generate
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

La configuración local de esta entrega apunta a `webscraping` en `localhost:3306`. Si cambian las credenciales, actualiza solo el `.env` ignorado.

## API

| Método | Ruta | Autenticación | Uso |
| --- | --- | --- | --- |
| `POST` | `/api/auth/register` | No | Registrar usuario (`name`, `email`, `password`, `password_confirmation`) |
| `POST` | `/api/auth/login` | No | Iniciar sesión y recibir token Bearer |
| `GET` | `/api/auth/me` | Bearer | Consultar usuario actual |
| `POST` | `/api/auth/logout` | Bearer | Revocar el token actual |
| `GET` | `/api/robots` | Bearer | Listar robots del usuario |
| `POST` | `/api/robots` | Bearer | Crear robot y ejecutar scraping |
| `POST` | `/api/robots/{robot}/executions` | Bearer | Volver a ejecutar un robot |
| `GET` | `/api/executions` | Bearer | Listar ejecuciones y productos extraídos; acepta `page`, `per_page` (1–100) y `search` |
| `GET` | `/api/executions/{execution}` | Bearer | Consultar una ejecución |
| `DELETE` | `/api/executions/{execution}` | Bearer | Eliminar una ejecución |

Las solicitudes autenticadas usan `Authorization: Bearer <token>`. El token se emite por 30 días. En desarrollo, Vite reenvía `/api` desde React a `http://127.0.0.1:8000`; inicia ambos servidores en terminales separadas.

## Scraping

El extractor interpreta productos publicados en JSON-LD y microdatos de Schema.org; para páginas sin esos formatos recurre a Open Graph y selectores comunes de precio/compra, incluidos formatos habituales de Amazon y Mercado Libre. En estos dos comercios se intenta primero la API oficial configurada y, si no hay credenciales o la API no está disponible, se prueba la extracción HTML pública como en Liverpool. Se siguen hasta cinco redirecciones HTTPS; cada destino vuelve a validarse y las direcciones privadas y locales se rechazan. La extracción depende de los datos que publica cada sitio. No es posible garantizar todos los enlaces: algunos comercios bloquean solicitudes automatizadas, requieren sesión/código postal o cargan precios e inventario exclusivamente con JavaScript. Por ejemplo, Mercado Libre puede redirigir a verificación anti-automatización, Amazon puede requerir acceso autorizado a Creators API y Telcel puede denegar solicitudes directas. Estas respuestas se identifican con mensajes específicos; no se intenta eludir verificaciones ni bloqueos. Para esos comercios se requiere acceso permitido por el sitio o una API oficial con credenciales configuradas fuera del código.

La interfaz muestra una vista previa del producto con la imagen y los datos extraídos. No se incrusta la página remota: algunos sitios, como Liverpool, bloquean los `iframe` con `X-Frame-Options` o CSP. Esta versión no genera capturas de pantalla ni ejecuta JavaScript en un navegador headless; para ver la página completa, usa el enlace al sitio original. En Liverpool, el estado de disponibilidad refleja si los botones de compra en línea están habilitados; la existencia para envío o recolección depende del código postal y no se puede confirmar sin consultarlo. Las ejecuciones anteriores conservan sus resultados originales; el botón «Actualizar precio y disponibilidad» vuelve a ejecutar el robot y guarda un resultado nuevo.

### APIs oficiales para comercios

Los enlaces de Amazon con ASIN y los enlaces de producto de Mercado Libre se consultan mediante sus APIs oficiales cuando hay credenciales configuradas. Si las credenciales faltan o la API no responde, el sistema intenta extraer los metadatos y el HTML público de la ficha; esta alternativa depende de que la tienda permita la solicitud. Añade estas variables únicamente al archivo local `Backend/.env` (nunca al frontend ni a archivos versionados):

```dotenv
AMAZON_CREATORS_CLIENT_ID=
AMAZON_CREATORS_CLIENT_SECRET=
AMAZON_CREATORS_PARTNER_TAG=
MERCADOLIBRE_ACCESS_TOKEN=
```

Amazon requiere acceso aprobado a Creators API para el marketplace, cumplir los requisitos vigentes del programa Amazon Associates y un Partner Tag válido; Mercado Libre requiere un token OAuth con permisos para consultar el artículo. Al faltar credenciales o permisos, la ejecución informa el requisito sin intentar eludir las protecciones de la tienda. Telcel no tiene un adaptador oficial de catálogo en esta aplicación; requiere una API o autorización que proporcione el comercio. Los demás dominios siguen usando HTML y metadatos públicos, sujetos a las reglas y disponibilidad de cada sitio.

La opción de monitorización se guarda en el robot, pero el scheduler de Laravel todavía no crea ejecuciones automáticas.

## Pruebas

```bash
php artisan test
```
