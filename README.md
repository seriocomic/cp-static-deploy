# CP Static Deploy

A ClassicPress/WordPress plugin that automates static site deployment. It mirrors a dev site via wget, processes the output (URL rewriting, feed conversion, minification), and deploys to GitHub Pages or Cloudflare Pages via pull request.

## How It Works

```
Content Published on Dev Site
    ↓ Plugin hooks (publish_post, publish_page, post_updated)
WordPress REST API Change Detection
    ↓ Queries /wp-json/wp/v2/posts and /pages with modified_after
Selective or Full wget Mirror
    ↓ Only changed pages + dependencies, or full site crawl
PHP Post-Processing Pipeline
    ↓ URL rewriting, feed processing, robots.txt, HTML minification
Git Commit + Push to Staging Branch
    ↓ GitHub REST API creates/updates Pull Request
Auto-Merge via GitHub Actions
    ↓ Hosting platform deploys from production branch
Production Site Updated
```

## Features

- **Selective builds** - Only rebuilds changed pages using the WordPress REST API, reducing build time from ~30 minutes to ~30 seconds
- **No external dependencies** - Replaces Node.js/npm/gulp/GitHub CLI with pure PHP post-processing and the WordPress HTTP API
- **Configurable via admin UI** - All settings (URLs, credentials, branches, build options) managed through a tabbed settings page
- **Encrypted credential storage** - GitHub token encrypted with AES-256-CBC using WordPress auth keys
- **Admin status page** - Real-time deploy status, logs, and manual trigger with AJAX polling
- **Automatic merge conflict resolution** - Pre-syncs with production branch and auto-resolves conflicts
- **Lock file protection** - Prevents concurrent deploys
- **Fallback to full mirror** - If selective wget fails, automatically falls back to full site crawl

## Requirements

- ClassicPress 1.0+ or WordPress 4.9+
- PHP 7.4+
- `git` and `wget` installed on the server
- A GitHub repository for the static site output
- A system user with write access to the working directory and git push access

## Installation

1. Upload the `cp-static-deploy` directory to `wp-content/plugins/`
2. Activate the plugin via the ClassicPress admin
3. The plugin creates a working directory at `wp-content/static-deploy/` on activation

### Working Directory Setup

```bash
# Set ownership (replace 'webmin' with the deploy user)
sudo chown -R webmin:www-data /path/to/wp-content/static-deploy
sudo chmod -R 775 /path/to/wp-content/static-deploy
```

### Git Repository

Clone the static site repository into the working directory:

```bash
cd /path/to/wp-content/static-deploy
git clone git@github.com:owner/repo.git repo
cd repo
git checkout staging
git config user.name "Deploy Bot"
git config user.email "deploy@example.com"
```

### Sudoers

The web server user needs permission to run the deploy wrapper as the deploy user:

```bash
# /etc/sudoers.d/static-deploy
www-data ALL=(webmin) NOPASSWD: /usr/bin/bash /path/to/wp-content/static-deploy/run-deploy.sh
```

## Configuration

Navigate to **Settings > Static Deploy** in the admin panel.

### General Tab

| Setting | Description |
| ------- | ----------- |
| Source URL | Dev site URL to crawl (e.g. `https://dev.example.com`) |
| Production URL | URL to rewrite dev URLs to (e.g. `https://www.example.com`) |
| Exclude Domains | Comma-separated domains to exclude from wget (e.g. `assets.example.com`) |
| Deploy User | System user that runs the deploy process |
| Auto Deploy | Toggle automatic deploy on publish/update |

### GitHub Tab

| Setting | Description |
| ------- | ----------- |
| Repository | GitHub repo in `owner/repo` format |
| Personal Access Token | Token with `repo` scope (stored encrypted) |
| Staging Branch | Branch for deploy commits (default: `staging`) |
| Production Branch | Branch PRs merge into (default: `master`) |
| Auto-Merge Label | Label added to PRs for auto-merge (default: `auto-merge`) |

### Build Tab

| Setting | Description |
| ------- | ----------- |
| Cache Clean Pages | Comma-separated paths to delete from wget cache before each build |
| Selective Threshold | Max changed items before using full rebuild instead of selective |
| Extra wget Arguments | Additional flags (e.g. `--no-check-certificate`) |
| Robots.txt | Custom content with `{{production_url}}` placeholder support |
| README Content | Optional README.md included in the repository |

### Prerequisites Tab

Displays system check results for git, wget, working directory, repository, deploy user, sudo access, GitHub token, and source URL.

## Usage

### Automatic Deploy

When **Auto Deploy** is enabled, the plugin triggers a deploy whenever a post or page is published or updated. An admin notice appears with a link to the status page.

### Manual Deploy

Navigate to **Tools > Static Deploy** and click **Trigger Manual Deploy**. The status page shows real-time progress with automatic log refresh.

### Command Line

```bash
bash /path/to/wp-content/static-deploy/run-deploy.sh
```

## Post-Processing Pipeline

The PHP processor replaces the traditional gulp/Node.js build pipeline:

1. **Clean wget cache** - Removes key HTML files so wget fetches fresh copies
2. **wget mirror** - Full site crawl or selective download of changed URLs
3. **HTML URL rewriting** - Domain swap, quote normalization, self-closing tag cleanup, newline removal, feed URL rewrite
4. **XML/RSS URL rewriting** - Domain swap on `.xml` and `.rss` files
5. **Feed processing** - Updates GUIDs via SimpleXML, renames `feed/index.html` to `feed/all.rss`
6. **robots.txt generation** - From configurable template
7. **README generation** - Optional README.md
8. **Old file cleanup** - Removes converted `feed/index.html` files
9. **Copy to repo** - Recursive copy from build directory to git repository
10. **Numbered file cleanup** - Removes `sitemap.xml.1` etc.

## PR Behavior

- **New PR** is created when no open PR exists from staging to production branch
- **Existing PR** is updated (title refreshed) when commits are pushed while a PR is still open
- The auto-merge label is added in both cases
- A GitHub Actions workflow (not included in this plugin) handles the merge

## File Structure

```
wp-content/plugins/cp-static-deploy/
├── cp-static-deploy.php           # Entry point, hooks, activation
├── includes/
│   ├── class-settings.php         # Settings, encryption, prerequisites
│   ├── class-deployer.php         # Deploy orchestrator
│   ├── class-processor.php        # Post-processing pipeline
│   ├── class-github.php           # GitHub REST API wrapper
│   └── class-admin.php            # Admin UI, AJAX handlers
├── templates/
│   ├── settings-page.php          # Settings form
│   └── status-page.php            # Deploy status and logs
└── assets/
    └── css/admin.css              # Admin styles

wp-content/static-deploy/          # Working directory (outside plugin)
├── build/                         # wget output (persistent cache)
├── repo/                          # Git repository clone
├── logs/                          # deploy.log + trigger.log
├── run-deploy.sh                  # Bash wrapper for sudo execution
├── .last-build-time               # ISO timestamp of last build
└── .lock                          # Present during active deploy
```

## Security

- GitHub token encrypted at rest with AES-256-CBC using WordPress `AUTH_KEY` and `AUTH_SALT`
- SSH keys used for git push (no tokens in shell commands)
- `escapeshellarg()` on all shell arguments
- AJAX nonce verification on all admin endpoints
- Background execution prevents PHP timeout
- Lock file prevents concurrent deploys

## License

GPL-2.0+
