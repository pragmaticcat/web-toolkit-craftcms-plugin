# Pragmatic +18 Extension

Extension reference for the +18 domain in the Pragmatic Web Toolkit ecosystem. This document is based on the standalone plugin README.

## Qué incluye
- Sección CP `Pragmatic > +18` con pestañas `General` y `Opciones`.
- Inyección automática del popup al final del `<body>` en peticiones de sitio.
- Persistencia por settings de plugin (Project Config).
- Configuración multiidioma por idioma de sitio.

## Configuración en CP
### General
- Activar/desactivar popup.
- Nombre de cookie.
- Duración de cookie en días.
- Edad mínima informativa.
- URL del logo.
- Mostrar/ocultar botón `No`.
- URL de salida para menores (si no hay valor, usa Google).

### Opciones
Para cada idioma detectado en los sitios de Craft:
- Texto principal de confirmación.
- Texto botón `Sí`.
- Texto botón `No`.
- Texto legal inferior.

## Comportamiento frontend
- Si la cookie configurada existe con valor `true`, no muestra popup.
- Al pulsar `Sí`, guarda cookie y cierra popup.
- Botón `No` opcional, redirige a la URL configurada.
- Fallback de idioma: idioma exacto (`es-ES`) -> base (`es`) -> `es` por defecto.

## Requisitos
- PHP >= 8.2
- Craft CMS ^5.0
