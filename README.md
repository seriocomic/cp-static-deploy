# CP Static Deploy

A ClassicPress/WordPress plugin that automates static site deployment. It mirrors a dev site via wget, processes the output (URL rewriting, feed conversion, minification), and deploys to GitHub Pages or Cloudflare Pages via pull request.

## How It Works

```
Content Published on Dev Site
    | Plugin hooks (publish_post, publish_page, post_updated)
WordPress REST API Change Detection
    | Queries /wp-json/wp/v2/posts and /pages with modified_after
Selective or Full wget Mirror
    | Only changed pages + dependencies, or full site crawl
PHP Post-Processing Pipeline
    | URL rewriting, feed processing, robots.txt, HTML minification
Git Commit + Push to Staging Branch
    | GitHub REST API creates/updates Pull Request
Auto-Merge via GitHub Actions
    | Hosting platform deploys from production branch
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
- PHP 7.4+ with OpenSSL extension (for token encryption)
- `git` and `wget` installed on the server
- A GitHub repository for the static site output
- A system user (the "deploy user") with SSH key access to GitHub and write access to the working directory
- A `sudoers` entry allowing the web server user to run the deploy script as the deploy user

## Installation

### Step 1: Upload the Plugin

Upload the `cp-static-deploy` directory to `wp-content/plugins/` and activate via the ClassicPress/WordPress admin. On activation, the plugin creates a working directory at `wp-content/static-deploy/` with subdirectories for build output, the git repo, and logs.

### Step 2: Working Directory Permissions

The working directory must be owned by the deploy user and writable by the web server group. This allows the deploy process (running as the deploy user) to write build output, and the web server (running as `www-data`) to read logs and status for the admin UI.

```bash
# Replace DEPLOY_USER with the system user configured in plugin settings
sudo chown -R DEPLOY_USER:www-data /path/to/wp-content/static-deploy
sudo chmod -R 775 /path/to/wp-content/static-deploy
```

**Why `DEPLOY_USER:www-data`?** The deploy process runs as the deploy user (via sudo) and needs to write files. The web server runs as `www-data` and needs to read log files for the admin status page. The `775` permission allows both.

### Step 3: Git Repository

Clone the static site GitHub repository into the working directory. This is where the deploy process commits and pushes build output.

```bash
cd /path/to/wp-content/static-deploy
sudo -u DEPLOY_USER git clone git@github.com:owner/repo.git repo
cd repo
sudo -u DEPLOY_USER git checkout staging    # or create: git checkout -b staging
sudo -u DEPLOY_USER git config user.name "Deploy Bot"
sudo -u DEPLOY_USER git config user.email "deploy@example.com"
```

**Why clone as the deploy user?** The repo directory must be owned by the deploy user so git operations work during automated deploys. Running the clone as the deploy user also ensures the SSH key authentication is tested.

### Step 4: SSH Key for Git Push

The deploy user needs an SSH key registered with GitHub to push commits. This avoids putting tokens in shell commands.

```bash
# Generate a key (as the deploy user)
sudo -u DEPLOY_USER ssh-keygen -t ed25519 -f ~DEPLOY_USER/.ssh/id_deploy -N "" -C "deploy@example.com"

# Add the public key to GitHub:
# 1. Copy the output of: sudo -u DEPLOY_USER cat ~DEPLOY_USER/.ssh/id_deploy.pub
# 2. Go to GitHub repo > Settings > Deploy keys > Add deploy key
# 3. Check "Allow write access"

# Configure SSH to use this key for github.com
sudo -u DEPLOY_USER bash -c 'cat >> ~/.ssh/config << EOF
Host github.com
    IdentityFile ~/.ssh/id_deploy
    IdentitiesOnly yes
EOF'

# Test the connection
sudo -u DEPLOY_USER ssh -T git@github.com
```

**Why SSH keys instead of tokens?** SSH keys are scoped to the deploy user on the server. The GitHub token stored in plugin settings is used only for the GitHub REST API (PR creation), not for git push operations.

### Step 5: Sudoers Configuration

The web server process (running as `www-data`) needs to execute the deploy script as the deploy user without a password prompt. This is configured via a sudoers drop-in file.

**Why is this needed?** When content is published, ClassicPress triggers the deploy via PHP's `exec()`. PHP runs as `www-data`, but the deploy process needs to run as the deploy user because that user owns the working directory, the git repo, and the SSH keys for pushing to GitHub.

**What gets allowed?** Two specific commands, nothing else:

1. `bash /path/to/run-deploy.sh` - The actual deploy script
2. `true` - Used by the plugin's Prerequisites check to verify sudo access is configured

```bash
# Create the sudoers file (replace paths and users as needed)
echo 'www-data ALL=(DEPLOY_USER) NOPASSWD: /usr/bin/bash /path/to/wp-content/static-deploy/run-deploy.sh, /usr/bin/true' | sudo tee /etc/sudoers.d/static-deploy

# Set correct permissions (required - sudo ignores files that aren't 0440)
sudo chmod 440 /etc/sudoers.d/static-deploy

# Validate syntax (critical - a syntax error here can lock out sudo entirely)
sudo visudo -cf /etc/sudoers.d/static-deploy
```

Expected output: `/etc/sudoers.d/static-deploy: parsed OK`

**Verify it works:**

```bash
# Test as www-data (should produce no output and exit 0)
sudo -u www-data sudo -n -u DEPLOY_USER true

# Test the actual deploy command
sudo -u www-data sudo -n -u DEPLOY_USER bash /path/to/wp-content/static-deploy/run-deploy.sh
```

**Common issues:**

| Symptom | Cause | Fix |
| ------- | ----- | --- |
| `sudo: a password is required` | No sudoers entry exists | Create the file as shown above |
| `sudo: no tty present` | Missing `-n` flag or wrong command | Verify the exact command path matches the sudoers entry |
| Prerequisite check fails but deploys work | Sudoers entry missing `/usr/bin/true` | Add `/usr/bin/true` to the allowed commands |
| `parsed OK` not shown | Syntax error in sudoers file | Delete the file and recreate it carefully |

**Where is this file?** `/etc/sudoers.d/static-deploy` - This directory is included by the main `/etc/sudoers` file on Debian/Ubuntu systems. Files here are loaded automatically.

### Step 6: GitHub Configuration

#### Personal Access Token

Generate a GitHub Personal Access Token (classic) with `repo` scope:

1. Go to GitHub > Settings > Developer settings > Personal access tokens > Tokens (classic)
2. Click "Generate new token (classic)"
3. Set a descriptive name (e.g. "CP Static Deploy")
4. Select the `repo` scope (full control of private repositories)
5. Generate and copy the token
6. Paste it into the plugin settings (Settings > Static Deploy > GitHub tab)

The token is encrypted with AES-256-CBC before storage in the database.

#### Staging Branch

Create a `staging` branch in the GitHub repository if it doesn't exist:

```bash
cd /path/to/wp-content/static-deploy/repo
sudo -u DEPLOY_USER git checkout -b staging
sudo -u DEPLOY_USER git push -u origin staging
```

#### Auto-Merge Label

Create a label named `auto-merge` in the GitHub repository:

1. Go to the repository > Issues > Labels
2. Click "New label"
3. Name: `auto-merge`, Color: `#0E8A16` (green)

This label is added to PRs by the plugin and triggers the auto-merge workflow.

#### GitHub Actions Workflow

Create `.github/workflows/auto-merge.yml` in the static site repository to handle automatic merging of deploy PRs:

```yaml
name: Auto-Merge Staging to Master

on:
  pull_request:
    types: [labeled, synchronize, opened, reopened]

jobs:
  auto-merge:
    runs-on: ubuntu-latest
    if: |
      contains(github.event.pull_request.labels.*.name, 'auto-merge') &&
      github.event.pull_request.base.ref == 'master'

    steps:
      - name: Checkout repository
        uses: actions/checkout@v4

      - name: Wait for status checks
        uses: lewagon/wait-on-check-action@v1.3.1
        with:
          ref: ${{ github.event.pull_request.head.sha }}
          check-name: 'auto-merge'
          repo-token: ${{ secrets.GITHUB_TOKEN }}
          wait-interval: 10
          allowed-conclusions: success,skipped,neutral
        continue-on-error: true

      - name: Auto-merge PR
        uses: pascalgn/automerge-action@v0.16.3
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          MERGE_LABELS: "auto-merge"
          MERGE_METHOD: "squash"
          MERGE_COMMIT_MESSAGE: "pull-request-title"
          MERGE_DELETE_BRANCH: false
          UPDATE_LABELS: ""

  notify-failure:
    runs-on: ubuntu-latest
    needs: auto-merge
    if: failure()
    steps:
      - name: Comment on PR about failure
        uses: actions/github-script@v7
        with:
          script: |
            github.rest.issues.createComment({
              issue_number: context.issue.number,
              owner: context.repo.owner,
              repo: context.repo.repo,
              body: 'Auto-merge failed. Check the workflow logs and merge manually.'
            })
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

Displays system check results for git, wget, working directory, repository, deploy user, sudo access, GitHub token, and source URL. All checks should show green before attempting a deploy.

### Help Tab

In-app reference covering setup instructions, sudoers configuration, troubleshooting, and FAQ.

## Usage

### Automatic Deploy

When **Auto Deploy** is enabled, the plugin triggers a deploy whenever a post or page is published or updated. An admin notice appears with a link to the status page.

### Manual Deploy

Navigate to **Tools > Static Deploy** and click **Trigger Manual Deploy**. The status page shows real-time progress with automatic log refresh.

### Command Line

```bash
# As the deploy user directly
bash /path/to/wp-content/static-deploy/run-deploy.sh

# Via SSH
ssh user@server "bash /path/to/wp-content/static-deploy/run-deploy.sh"
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

## Build Strategy

| Condition | Strategy | Duration |
| --------- | -------- | -------- |
| No `.last-build-time` file | Full mirror | ~30 min (first run) |
| Changes > selective threshold | Full mirror | ~50 sec (cached) |
| Changes <= threshold | Selective (changed URLs + dependencies) | ~30 sec |
| No changes detected | Skip (no deploy) | ~2 sec |

The wget cache persists across builds in `wp-content/static-deploy/build/`, so even full mirrors are fast after the initial crawl (wget uses 304 Not Modified responses).

## PR Behavior

- **New PR** is created when no open PR exists from staging to production branch
- **Existing PR** is updated (title refreshed) when commits are pushed while a PR is still open
- The auto-merge label is added in both cases
- A GitHub Actions workflow (not included in this plugin) handles the merge

## File Structure

```
wp-content/plugins/cp-static-deploy/
|- cp-static-deploy.php           # Entry point, hooks, activation
|- includes/
|   |- class-settings.php         # Settings, encryption, prerequisites
|   |- class-deployer.php         # Deploy orchestrator
|   |- class-processor.php        # Post-processing pipeline
|   |- class-github.php           # GitHub REST API wrapper
|   +- class-admin.php            # Admin UI, AJAX handlers
|- templates/
|   |- settings-page.php          # Settings form
|   +- status-page.php            # Deploy status and logs
+- assets/
    +- css/admin.css              # Admin styles

wp-content/static-deploy/          # Working directory (outside plugin)
|- build/                         # wget output (persistent cache)
|- repo/                          # Git repository clone
|- logs/                          # deploy.log + trigger.log
|- run-deploy.sh                  # Bash wrapper for sudo execution
|- .last-build-time               # ISO timestamp of last build
+- .lock                          # Present during active deploy
```

## Security

- GitHub token encrypted at rest with AES-256-CBC using WordPress `AUTH_KEY` and `AUTH_SALT`
- SSH keys used for git push (no tokens in shell commands)
- `escapeshellarg()` on all shell arguments
- AJAX nonce verification on all admin endpoints
- Background execution prevents PHP timeout
- Lock file prevents concurrent deploys
- Sudoers entry restricted to two specific commands

## Privacy & Data Collection

This plugin is designed with privacy as a priority. Here's what data is collected and transmitted:

### Data Stored Locally

- **GitHub Personal Access Token**: Stored encrypted in the WordPress database using AES-256-CBC encryption. The token is only decrypted in memory when making GitHub API calls and is never transmitted to any server other than GitHub's official API.
- **Plugin Settings**: All configuration (URLs, branch names, deploy user, etc.) is stored in the WordPress options table.
- **Deploy Logs**: Timestamped log entries of deploy operations stored locally at `wp-content/static-deploy/logs/`.
- **Build Cache**: Static site mirror stored locally at `wp-content/static-deploy/build/` for faster subsequent builds.

### External Server Communication

**This plugin requires explicit opt-in consent before communicating with external servers.** You must manually configure and authorize all external connections:

1. **GitHub API** (api.github.com)
   - **When**: Only when you provide a GitHub Personal Access Token in plugin settings
   - **What data is sent**: Repository name, pull request metadata (title, branch names, labels)
   - **Why**: To create and manage pull requests for static site deployments
   - **User consent**: Required - you must generate and manually enter a GitHub token
   - **No identifiable data**: Only repository content and commit metadata is transmitted

2. **WordPress REST API** (Local Server)
   - **When**: During selective build process
   - **What data is accessed**: Post and page metadata (titles, URLs, modification dates)
   - **Why**: To detect which content has changed since the last build
   - **Where**: Queries your own WordPress installation (`/wp-json/wp/v2/posts` and `/pages`)
   - **No external transmission**: This data never leaves your server

### What is NOT Collected

- No user browsing data
- No visitor analytics or tracking
- No personal information about site administrators or authors
- No content is transmitted to third-party services (other than GitHub for deployment)
- No telemetry, phone-home features, or usage statistics

### Third-Party Services

The plugin integrates with **GitHub** only. You must have:

- A GitHub account
- A GitHub repository for static site output
- A GitHub Personal Access Token with `repo` scope

GitHub's privacy policy applies to data transmitted to their API: <https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement>

### Data Retention

- Deploy logs are retained indefinitely until manually deleted
- Build cache is retained until manually cleared or the working directory is deleted
- GitHub token remains encrypted in the database until you change or remove it via plugin settings

### Your Control

You maintain full control over all data:

- Deactivating the plugin stops all external communication immediately
- Deleting the plugin removes all settings and the encrypted GitHub token
- Build cache and logs can be manually deleted at any time from `wp-content/static-deploy/`

## Troubleshooting

### Prerequisites check shows "Sudo not configured"

The sudoers entry is missing or doesn't include `/usr/bin/true`. See [Step 5: Sudoers Configuration](#step-5-sudoers-configuration).

### Deploy triggered but nothing happens

Check the trigger log and deploy log:

```bash
tail -20 /path/to/wp-content/static-deploy/logs/trigger.log
tail -50 /path/to/wp-content/static-deploy/logs/deploy.log
```

Common causes: lock file from a previous failed deploy (delete `.lock`), deploy user can't write to working directory (fix permissions), SSH key not accepted by GitHub (test with `ssh -T git@github.com`).

### Deploy stuck (lock file persists)

```bash
# Check if deploy process is still running
ps aux | grep run-deploy

# If not running, remove the stale lock
rm /path/to/wp-content/static-deploy/.lock
```

### PR not auto-merging

1. Verify the `auto-merge` label exists in the GitHub repository
2. Check GitHub Actions is enabled and the workflow file exists at `.github/workflows/auto-merge.yml`
3. View workflow runs at the repository's Actions tab

### wget fails or times out

- Verify the source URL is reachable: `curl -I https://dev.example.com`
- Check disk space: `df -h /var/www`
- For self-signed certificates, add `--no-check-certificate` to Extra wget Arguments in Build settings

### GitHub API errors

Test the connection via Settings > Static Deploy > Prerequisites tab, or check the deploy log for specific error messages. Common causes: expired token, incorrect repository format, network issues.

## License

GPL-2.0+
