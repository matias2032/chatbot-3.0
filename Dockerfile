# Usa a imagem oficial do PHP com Apache
FROM php:8.2-apache

# Instala dependências do sistema e extensões do PostgreSQL para PHP
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Ativa o mod_rewrite do Apache (importante para APIs)
RUN a2enmod rewrite

# Copia os ficheiros do teu projeto para a pasta do servidor
COPY . /var/www/html/

# Dá permissões para a pasta de uploads (caso precises)
RUN chown -R www-data:www-data /var/www/html/uploads && chmod -R 755 /var/www/html/uploads

# Expõe a porta 80
EXPOSE 80

# O Apache inicia automaticamente