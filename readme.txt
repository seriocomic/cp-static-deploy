=== CP Static Deploy ===
Contributors: seriocomic
Tags: static site, deployment, github pages, cloudflare pages, jamstack, ci/cd
Requires at least: 1.0.0
Tested up to: 2.0.0
Requires CP: 1.0.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated static site deployment for ClassicPress. Mirrors your site via wget, processes output, and deploys to GitHub Pages or Cloudflare Pages via pull request.

== Description ==

CP Static Deploy automates the entire workflow of converting your ClassicPress site into a static site and deploying it to hosting platforms like GitHub Pages or Cloudflare Pages.

**Key Features:**

* **Selective Builds** - Only rebuilds changed pages using the WordPress REST API, reducing build time from ~30 minutes to ~30 seconds
* **No External Dependencies** - Replaces Node.js/npm/gulp/GitHub CLI with pure PHP post-processing
* **Encrypted Credential Storage** - GitHub token encrypted with AES-256-CBC using WordPress auth keys
* **Admin Status Page** - Real-time deploy status, logs, and manual trigger with AJAX polling
* **Automatic Merge Conflict Resolution** - Pre-syncs with production branch and auto-resolves conflicts
* **Lock File Protection** - Prevents concurrent deploys
* **Pull Request Workflow** - Creates PRs with auto-merge labels for audit trail and GitHub Actions integration

**How It Works:**

1. Content published on your ClassicPress site triggers plugin hooks
2. REST API detects changes since last build
3. Selective wget mirrors only changed pages (~30 sec) or full site (~30 min)
4. PHP post-processing: URL rewriting, feed processing, robots.txt generation
5. Git commit + push to staging branch
6. GitHub REST API creates/updates pull request with auto-merge label
7. GitHub Actions auto-merges staging to production branch
8. Hosting platform deploys from production branch

**Requirements:**

* ClassicPress 1.0+ or WordPress 4.9+
* PHP 7.4+ with OpenSSL extension
* git and wget installed on the server
* A GitHub repository for static site output
* A system user (deploy user) with SSH key access to GitHub
* Sudo configuration allowing web server to run deploy script

**Privacy & External Services:**

This plugin requires explicit opt-in consent before communicating with external servers. You must manually configure a GitHub Personal Access Token to authorize GitHub API access. The plugin only contacts GitHub's API (api.github.com) to create and manage pull requests. No user data, analytics, or telemetry is collected. See the Privacy & Data Collection section in README.md for full details.

== Installation ==

**Step 1: Install the Plugin**

1. Upload the `cp-static-deploy` directory to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in ClassicPress
3. The working directory (`wp-content/static-deploy/`) is created automatically

**Step 2: Configure Permissions**

Set ownership and permissions on the working directory:

`sudo chown -R DEPLOY_USER:www-data /path/to/wp-content/static-deploy`
`sudo chmod -R 775 /path/to/wp-content/static-deploy`

**Step 3: Clone Git Repository**

Clone your static site repository into the working directory:

`cd /path/to/wp-content/static-deploy`
`sudo -u DEPLOY_USER git clone git@github.com:owner/repo.git repo`
`cd repo`
`sudo -u DEPLOY_USER git checkout staging`
`sudo -u DEPLOY_USER git config user.name "Deploy Bot"`
`sudo -u DEPLOY_USER git config user.email "deploy@example.com"`

**Step 4: Configure SSH Key**

Generate an SSH key for the deploy user and add it as a GitHub deploy key with write access:

`sudo -u DEPLOY_USER ssh-keygen -t ed25519 -f ~/.ssh/id_deploy -N ""`

**Step 5: Configure Sudoers**

Create `/etc/sudoers.d/static-deploy`:

`echo 'www-data ALL=(DEPLOY_USER) NOPASSWD: /usr/bin/bash /path/to/wp-content/static-deploy/run-deploy.sh, /usr/bin/true' | sudo tee /etc/sudoers.d/static-deploy`
`sudo chmod 440 /etc/sudoers.d/static-deploy`
`sudo visudo -cf /etc/sudoers.d/static-deploy`

**Step 6: Configure Plugin Settings**

1. Go to Settings > Static Deploy
2. Fill in Source URL, Production URL, Deploy User
3. Go to GitHub tab and enter your repository and Personal Access Token
4. Create an `auto-merge` label in your GitHub repository
5. Add the GitHub Actions workflow file (see README.md for YAML)

See the comprehensive setup guide in README.md or the in-plugin Help tab for detailed instructions.

== Frequently Asked Questions ==

= What is the deploy user? =

A system user (e.g., a dedicated account on your server) that owns the working directory and has SSH access to GitHub. The web server (www-data) delegates to this user via sudo for deploy operations to avoid permission issues.

= Why are two branches needed? =

The staging branch receives automated commits from each deploy. A PR is created from staging to the production branch (e.g., master), providing an audit trail and allowing GitHub Actions to auto-merge. The hosting platform deploys from the production branch.

= What is selective vs full build? =

Selective builds query the WordPress REST API for content modified since the last deploy, then only download those specific URLs plus dependencies (homepage, feeds, archives). If more items changed than the threshold setting, or no previous build exists, a full wget mirror is used instead.

= Where are the logs? =

Deploy logs are at `wp-content/static-deploy/logs/deploy.log` (main pipeline) and `trigger.log` (ClassicPress events). Both are viewable from Tools > Static Deploy.

= How is the GitHub token stored? =

Encrypted with AES-256-CBC using keys derived from `AUTH_KEY` and `AUTH_SALT` in `wp-config.php`. The token is only decrypted in memory when needed for API calls.

= Can deploys be triggered from the command line? =

Yes: `bash /path/to/wp-content/static-deploy/run-deploy.sh` (run as the deploy user). The admin status page detects externally triggered deploys automatically.

= Does this work with WordPress? =

Yes, the plugin is compatible with both ClassicPress and WordPress 4.9+.

== Screenshots ==

1. Settings page with General, GitHub, Build, Prerequisites, and Help tabs
2. Status page showing real-time deploy progress and logs
3. Prerequisites check showing system requirements status

== Changelog ==

= 1.0.3 =
* Fix: Use database query for change detection with REST API fallback
* Switch to direct database queries for detecting changed posts/pages
* Bypasses Apache/HTTP issues when server accesses itself internally
* Maintains REST API as fallback method for reliability
* Resolves HTTP 404 errors when accessing WordPress REST API via localhost

= 1.0.2 =
* Fix: Add REST API support for modified_after parameter
* Register custom query parameter with WordPress REST API
* Enable filtering posts by modification date via REST API

= 1.0.1 =
* Fix RSS feed not updating on selective builds - reorder post-processing pipeline so feed conversion (index.html to all.rss) runs before HTML rewriting, preventing XML corruption

= 1.0.0 =
* Initial release
* Selective build support via WordPress REST API
* PHP post-processing pipeline (replaces gulp/Node.js)
* GitHub REST API integration for PR creation
* AES-256-CBC encrypted token storage
* Real-time status dashboard with AJAX polling
* Lock file protection for concurrent deploys
* Automatic merge conflict resolution
* Comprehensive admin UI with 5 tabs
* Privacy-first design with explicit consent for external API usage

== Upgrade Notice ==

= 1.0.0 =
Initial release. Requires manual setup of working directory, git repository, SSH keys, and sudoers configuration. See Installation section for details.
