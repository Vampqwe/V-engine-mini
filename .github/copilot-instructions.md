# Copilot instructions for V-engine-mini

## Project overview
- This repository is a lightweight PHP content site that serves static HTML files from `pages/`.
- Traffic is routed through `index.php` and `.htaccess`, which rewrite unmatched URLs to the PHP entry point.
- `build_map.php` scans every HTML page, reads the canonical URL from `<link rel="canonical" ...>`, and writes `routes.json` with the route-to-file mapping.
- `routes.json` and `route_aliases.json` are generated/configuration files. Prefer regenerating them over editing them by hand.
- `admin.php` is a local-only admin panel for bulk metadata updates, HTML import, and legacy redirects. It restricts access to localhost and uses CSRF checks.

## Architecture and conventions
- Keep content pages as static HTML under `pages/`; do not introduce a framework or app server layer.
- Every page should have a valid canonical URL in the `<head>` section. The canonical path drives the route map used by `index.php`.
- If you add, rename, move, or delete a page, update the canonical URL and regenerate `routes.json` so the route map matches the content.
- Preserve existing SEO metadata (`<title>`, canonical, Open Graph/JSON-LD, h1, etc.) when editing content.
- The site content is veterinary/nutrition-oriented and mostly in Russian; maintain the existing tone, terminology, and structure.
- Avoid changing the generic routing logic unless the task specifically calls for it.

## Content and routing guidance
- Use the existing HTML structure in nearby pages as a template for new articles or section landing pages.
- Do not rely on file names for routing; routing is canonical-driven.
- For legacy or outdated URLs, add approved redirect mappings in `route_aliases.json` rather than creating duplicate content pages.
- When a canonical URL is changed, make sure the previous path is either redirected or intentionally removed.
- Keep routes clean and human-readable. Preserve the existing path conventions like `/gastro/...`, `/dental/...`, `/dermatology/...`, and top-level section pages.

## Editing and validation workflow
- After modifying PHP routing, admin logic, or any HTML that changes canonical paths, run:
  - `php -l index.php`
  - `php -l build_map.php`
  - `php -l admin.php`
  - `php build_map.php`
- Validate before merging content changes that affect site navigation or route generation.
- If a page is missing a canonical tag, `build_map.php` will skip it and it will not be served by the routing layer.
- Prefer targeted, repo-native fixes: bulk file updates should happen through the existing admin functions, not ad hoc regex replacements across the whole `pages/` tree.

## Important files
- `index.php` — request routing and 301 handling
- `build_map.php` — route map generation from canonical URLs
- `routes.json` — generated mapping of canonical paths to page files
- `route_aliases.json` — legacy redirect map
- `pages/` — static site content
- `admin.php` — local content management interface
- `.htaccess` — rewrite rule forwarding requests to `index.php`

## Use these rules when working in this repo
- Keep the project lightweight and static.
- Preserve SEO and canonical correctness.
- Prefer generated route management over manual JSON editing.
- Do not introduce new dependencies, frameworks, or build steps unless explicitly required.
