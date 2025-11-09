# HPPRy Timber Theme

Custom WordPress theme for Humaania Päihdepolitiikkaa Ry built with Timber (Twig), Tailwind CSS, and Vite.

## Local Development

1. Install PHP dependencies:

```bash
composer install
```

2. Install Node dependencies:

```bash
npm install
```

3. Run the development server with hot reload:

```bash
npm run dev
```

4. Build production assets:

```bash
npm run build
```

Copy the theme into `wp-content/themes/hpp-timber` in your WordPress installation and activate it from the WordPress admin.

## Docker Compose Stack

The repository includes a ready-to-run Docker Compose setup that provisions WordPress and MySQL with the theme prebuilt inside the image.

1. Copy the environment template and adjust the secrets:

   ```bash
   cp .env.example .env
   ```

2. Build and start the stack:

   ```bash
   docker compose up -d --build
   ```

3. Visit `http://localhost:8088` (or the port from `.env`) to finish the WordPress install wizard.

### Services

- `wordpress`: Custom image based on `wordpress:6.7.1-php8.2-apache` that installs dependencies, compiles assets, and copies the theme into `/usr/src/wordpress/wp-content/themes/hpp-timber`. Exposed on port 8088 by default.
- `db`: MySQL 8.0 with persistent storage in the `db_data` volume.

Persistent data lives in the named volumes `wordpress_data` (WordPress core + uploads) and `db_data` (database). Local uploads are also mirrored to `./wp-data/uploads`.

## Deployment (Coolify)

1. Connect Coolify to this repository and choose the Docker Compose template.
2. Provide environment variables for database credentials, `WP_HOME`, and `WP_SITEURL`.
3. Coolify will build the custom WordPress image, run `composer install`, `npm ci`, and `npm run build` inside the container, and deploy automatically.

Optional: add a GitHub Action to build and push a container image or run theme tests before deployment.

