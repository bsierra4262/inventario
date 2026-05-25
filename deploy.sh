#!/bin/bash
# Script de deploy — Hostinger lo ejecuta automáticamente después de cada git pull
# Configurar en hPanel → Git → Manage → Deploy Script

echo "=== Deploy INTELLIHELP Asset Vault ==="
echo "Directorio: $(pwd)"
echo "PHP: $(php -v | head -1)"

# Instalar/actualizar dependencias PHP
if command -v composer &> /dev/null; then
    echo "Corriendo composer install..."
    composer install --no-dev --optimize-autoloader --no-interaction
elif [ -f "/usr/local/bin/composer" ]; then
    echo "Corriendo composer install (path completo)..."
    /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction
elif [ -f "/opt/cpanel/composer/bin/composer" ]; then
    /opt/cpanel/composer/bin/composer install --no-dev --optimize-autoloader --no-interaction
else
    echo "ERROR: Composer no encontrado. Instalar manualmente."
    exit 1
fi

echo "=== Deploy completado ==="
