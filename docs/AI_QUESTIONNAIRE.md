# Deployment Configuration Questionnaire

This questionnaire collects all information needed to configure deployer-laravel-stack. AI agents should use this to systematically gather requirements.

---

## Instructions for AI Agents

1. Present questions in order (dependencies matter)
2. Mark fields as `[REQUIRED]` or `[OPTIONAL]`
3. Provide the default value in parentheses where applicable
4. If user skips an optional field, use the default
5. If user skips a required field, ASK AGAIN with clarification

---

## Section 1: Basic Information

### Q1.1 Application Name [REQUIRED]
**Question**: What is the name of your application?

**Format**: Human-readable string (e.g., "My Application", "Company Portal")

**Used for**: Display in deployment banners and logs

**Example**: `My Application`

---

### Q1.2 Git Repository URL [REQUIRED]
**Question**: What is the Git repository URL? (Must be SSH format)

**Format**: `git@{host}:{org}/{repo}.git`

**Validation**:
- Must start with `git@`
- Must end with `.git`
- Must contain host, org, and repo

**Examples**:
- `git@github.com:my-org/my-app.git`
- `git@gitlab.com:company/project.git`
- `git@bitbucket.org:team/repo.git`

---

### Q1.3 Server Hostname [REQUIRED]
**Question**: What is the server's IP address or hostname?

**Format**: IPv4 address or valid hostname

**Validation**:
- No protocol prefix (no `ssh://` or `https://`)
- Must be reachable via SSH

**Examples**:
- `192.168.1.100`
- `10.0.0.5`
- `server.example.com`
- `myserver.local`

---

### Q1.4 Production Domain [REQUIRED]
**Question**: What domain will this application use in production?

**Format**: Valid domain name without protocol

**Validation**:
- No `http://` or `https://` prefix
- No trailing slash
- Must be valid domain format

**Examples**:
- `myapp.com`
- `app.example.com`
- `www.mycompany.com`

---

## Section 2: Stack Configuration

### Q2.1 Database Type [REQUIRED]
**Question**: Which database will your application use?

**Options**:
| Option | Description | Best For |
|--------|-------------|----------|
| `pgsql` | PostgreSQL | Production apps, complex queries, data integrity |
| `mysql` | MySQL/MariaDB | WordPress-adjacent, existing MySQL infrastructure |
| `sqlite` | SQLite | Simple apps, prototypes, single-server deployments |

**Default**: `pgsql` (recommended for production)

**Follow-up**:
- If `sqlite`: Will configure file-based database in shared directory
- If `pgsql` or `mysql`: Will need database password in secrets

---

### Q2.2 Web Server Mode [REQUIRED]
**Question**: Which web server configuration do you want?

**Options**:
| Option | Description | Best For |
|--------|-------------|----------|
| `fpm` | PHP-FPM with Caddy | Traditional setups, compatibility, most Laravel apps |
| `octane` | Laravel Octane with FrankenPHP | High performance, real-time features, WebSockets |

**Default**: `fpm`

**Follow-up**:
- If `octane`: Ensure Laravel Octane is installed (`composer require laravel/octane`)

---

### Q2.3 Secrets Management [REQUIRED]
**Question**: How do you want to manage deployment secrets?

**Options**:
| Option | Description | Best For |
|--------|-------------|----------|
| `1password` | 1Password CLI integration | Teams, enhanced security, secret rotation |
| `env` | Plain .env file | Solo developers, simple setups, CI/CD environments |

**Default**: `1password` (recommended for security)

**Follow-up**:
- If `1password`: Will need vault name and item name
- If `env`: Will create `deploy/secrets.env` (must add to .gitignore)

---

### Q2.4 PHP Version [OPTIONAL]
**Question**: Which PHP version should be installed?

**Options**: `8.2`, `8.3`, `8.4`

**Default**: `8.4`

---

### Q2.5 Node.js Version [OPTIONAL]
**Question**: Which Node.js version should be installed for asset building?

**Options**: `18`, `20`, `22`

**Default**: `22`

---

## Section 3: Environment Configuration

### Q3.1 Staging Environment [OPTIONAL]
**Question**: Do you want to set up a staging environment?

**Options**: `yes`, `no`

**Default**: `yes`

**Follow-up if yes**:
- Q3.1a: Staging domain? (default: `staging.{production_domain}`)

---

### Q3.2 Redis Configuration [OPTIONAL]
**Question**: Will your application use Redis for caching or queues?

**Options**: `yes`, `no`

**Default**: `yes`

**Note**: Redis is installed by default. This question determines if Redis-specific environment variables are configured.

---

### Q3.3 Queue Workers [OPTIONAL]
**Question**: Do you need background queue workers (Supervisor-managed)?

**Options**: `yes`, `no`

**Default**: `no`

**Follow-up if yes**:
- Will configure Supervisor to manage Laravel queue workers

---

### Q3.4 TLS Mode [OPTIONAL]
**Question**: How should HTTPS certificates be managed?

**Options**:
| Option | Description | Best For |
|--------|-------------|----------|
| `internal` | Self-signed certificates | Behind Cloudflare or other proxy |
| `acme` | Let's Encrypt automatic certificates | Direct traffic, no proxy |

**Default**: `internal`

---

## Section 4: Media & Storage

### Q4.1 User Uploads [OPTIONAL]
**Question**: Does your application handle user-uploaded files (images, documents, media)?

**Options**: `yes`, `no`

**Default**: `no`

**Follow-up if yes**:
- Q4.1a: What directory path do uploads use? (e.g., `public/media`, `public/uploads`)

---

### Q4.2 Storage Links [OPTIONAL]
**Question**: Do you need custom symlinks from `public/` to shared storage?

**Format**: Comma-separated pairs of `public_path:shared_path`

**Examples**:
- `media:media` (links `public/media` to `shared/media`)
- `uploads:uploads,images:images` (multiple links)

**Default**: None

---

## Section 5: 1Password Configuration (if applicable)

### Q5.1 1Password Vault Name [REQUIRED if secrets_management=1password]
**Question**: What is the name of your 1Password vault?

**Format**: Exact vault name (case-sensitive)

**Default**: `DevOps`

**Examples**: `DevOps`, `Servers`, `Production`

---

### Q5.2 1Password Item Name [REQUIRED if secrets_management=1password]
**Question**: What should the 1Password item be named?

**Format**: Lowercase, hyphenated (auto-generated from app name if not specified)

**Default**: `{application_name}` converted to slug (e.g., "My Application" → "my-application")

**Examples**: `my-app`, `company-portal`, `client-project`

---

## Section 6: Existing Data Migration

### Q6.1 Existing Database [OPTIONAL]
**Question**: Do you have an existing database to migrate to the server?

**Options**: `yes`, `no`

**Default**: `no`

**Follow-up if yes**:
- Only applicable for SQLite (file can be uploaded)
- For PostgreSQL/MySQL, recommend using database dump/restore

---

### Q6.2 Existing Media Files [OPTIONAL]
**Question**: Do you have existing media files to migrate to the server?

**Options**: `yes`, `no`

**Default**: `no`

**Follow-up if yes**:
- Will provide instructions for using `data:migrate` command

---

## Response Template

After collecting all information, compile into this format:

```yaml
# Basic Information
application_name: "{response to Q1.1}"
repository_url: "{response to Q1.2}"
server_hostname: "{response to Q1.3}"
production_domain: "{response to Q1.4}"

# Stack Configuration
database_type: "{response to Q2.1}"
web_server_mode: "{response to Q2.2}"
secrets_management: "{response to Q2.3}"
php_version: "{response to Q2.4 or default}"
node_version: "{response to Q2.5 or default}"

# Environment Configuration
staging_enabled: {response to Q3.1}
staging_domain: "{response to Q3.1a or default}"
redis_enabled: {response to Q3.2}
queue_workers_enabled: {response to Q3.3}
tls_mode: "{response to Q3.4 or default}"

# Media & Storage
has_uploads: {response to Q4.1}
storage_links:
  {parsed from Q4.2}

# 1Password (if applicable)
op_vault: "{response to Q5.1}"
op_item: "{response to Q5.2}"

# Migration
has_existing_database: {response to Q6.1}
has_existing_media: {response to Q6.2}
```

---

## Derived Values

Calculate these values from user responses:

| Derived Value | Formula |
|---------------|---------|
| `app_slug` | `lowercase(replace(application_name, " ", "-"))` |
| `db_name_prod` | `replace(app_slug, "-", "_")` |
| `db_name_staging` | `{db_name_prod}_staging` |
| `deploy_path_prod` | `/home/deployer/{app_slug}` |
| `deploy_path_staging` | `/home/deployer/{app_slug}-staging` |
| `staging_domain` | `staging.{production_domain}` (if not specified) |
| `op_item` | `{app_slug}` (if not specified) |

---

## Validation Rules

Before proceeding to configuration generation:

| Field | Validation |
|-------|------------|
| `repository_url` | Starts with `git@`, ends with `.git` |
| `server_hostname` | Valid IP or hostname, no protocol |
| `production_domain` | Valid domain, no protocol, no trailing slash |
| `staging_domain` | Valid domain (if staging enabled) |
| `database_type` | One of: `pgsql`, `mysql`, `sqlite` |
| `web_server_mode` | One of: `fpm`, `octane` |
| `secrets_management` | One of: `1password`, `env` |
| `php_version` | One of: `8.2`, `8.3`, `8.4` |
| `node_version` | One of: `18`, `20`, `22` |
| `tls_mode` | One of: `internal`, `acme` |

---

## Quick Start Questions (Minimum Viable)

For quick setup, these 6 questions are the minimum required:

1. **Application name?** (Q1.1)
2. **Git repository URL?** (Q1.2)
3. **Server hostname/IP?** (Q1.3)
4. **Production domain?** (Q1.4)
5. **Database type?** (Q2.1) - default: `pgsql`
6. **Secrets management?** (Q2.3) - default: `1password`

All other values can use sensible defaults.
