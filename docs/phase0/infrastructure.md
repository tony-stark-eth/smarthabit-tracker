# Infrastructure — OpenTofu Skeleton (Hetzner Cloud)

Generic Hetzner Cloud skeleton. Only structure and variables — no `tofu apply` in the template. Projects parameterize `terraform.tfvars` after forking.

#### `infrastructure/versions.tf`

```hcl
terraform {
  required_version = ">= 1.9"

  required_providers {
    hcloud = {
      source  = "hetznercloud/hcloud"
      version = "~> 1.33"
    }
    cloudflare = {
      source  = "cloudflare/cloudflare"
      version = "~> 5.0"
    }
  }

  # State Backend — MUST be configured before the first `tofu apply`!
  # Without remote state, the state is local only and lost on server change.
  # Recommended: Hetzner Object Storage as S3 backend.
  # backend "s3" {
  #   bucket                      = "your-project-tfstate"
  #   key                         = "prod/terraform.tfstate"
  #   region                      = "eu-central-1"
  #   endpoint                    = "https://fsn1.your-objectstorage.com"
  #   skip_credentials_validation = true
  #   skip_region_validation      = true
  #   skip_metadata_api_check     = true
  # }
}
```

#### `infrastructure/main.tf`

```hcl
provider "hcloud" {
  token = var.hcloud_token
}

provider "cloudflare" {
  api_token = var.cloudflare_api_token
}

module "network" {
  source = "./modules/network"

  project_name = var.project_name
  location     = var.location
}

module "volume" {
  source = "./modules/volume"

  project_name = var.project_name
  location     = var.location
  size         = var.volume_size_gb
}

module "server" {
  source = "./modules/server"

  project_name = var.project_name
  server_type  = var.server_type
  location     = var.location
  ssh_key_ids  = var.ssh_key_ids
  network_id   = module.network.network_id
  subnet_id    = module.network.subnet_id
  firewall_id  = module.network.firewall_id
  volume_id    = module.volume.volume_id
}

module "dns" {
  source = "./modules/dns"

  domain     = var.domain
  zone_id    = var.cloudflare_zone_id
  server_ipv4 = module.server.ipv4_address
  server_ipv6 = module.server.ipv6_address
  subdomains  = var.subdomains
}
```

#### `infrastructure/variables.tf`

```hcl
# ── Project ───────────────────────────
variable "project_name" {
  description = "Project name (used for resource labels)"
  type        = string
}

# ── Hetzner ───────────────────────────
variable "hcloud_token" {
  description = "Hetzner Cloud API Token"
  type        = string
  sensitive   = true
}

variable "server_type" {
  description = "Hetzner server type (e.g. cx31, cx41, cx51)"
  type        = string
  default     = "cx31"
}

variable "location" {
  description = "Hetzner location (fsn1 = Falkenstein, nbg1 = Nuremberg, hel1 = Helsinki)"
  type        = string
  default     = "fsn1"
}

variable "ssh_key_ids" {
  description = "List of Hetzner SSH Key IDs for server access"
  type        = list(number)
}

variable "volume_size_gb" {
  description = "Volume size for DB data in GB"
  type        = number
  default     = 20
}

# ── Cloudflare DNS ────────────────────
variable "cloudflare_api_token" {
  description = "Cloudflare API Token (Zone:DNS:Edit)"
  type        = string
  sensitive   = true
}

variable "cloudflare_zone_id" {
  description = "Cloudflare Zone ID for the domain"
  type        = string
}

variable "domain" {
  description = "Root domain (e.g. example.com)"
  type        = string
}

variable "subdomains" {
  description = "Subdomains pointing to the server (e.g. ['app', 'api', 'errors', 'ntfy'])"
  type        = list(string)
  default     = ["app"]
}
```

#### `infrastructure/terraform.tfvars.example`

```hcl
# Copy to terraform.tfvars and fill in values.
# terraform.tfvars is in .gitignore — DO NOT commit secrets.

project_name = "my-project"

# Hetzner
# hcloud_token = "..." ← from ENV: export TF_VAR_hcloud_token=...
server_type    = "cx31"       # 2 vCPU, 4GB RAM, 40GB — sufficient for MVP
location       = "fsn1"       # Falkenstein (EU, GDPR)
ssh_key_ids    = [12345]      # Hetzner Console → SSH Keys
volume_size_gb = 20           # PostgreSQL data

# Cloudflare
# cloudflare_api_token = "..." ← from ENV: export TF_VAR_cloudflare_api_token=...
cloudflare_zone_id = "abc123..."
domain             = "example.com"
subdomains         = ["app", "api", "errors"]
```

#### `infrastructure/modules/server/main.tf`

```hcl
resource "hcloud_server" "app" {
  name        = "${var.project_name}-app"
  server_type = var.server_type
  location    = var.location
  image       = "ubuntu-24.04"
  ssh_keys    = var.ssh_key_ids

  labels = {
    managed_by = "opentofu"
    project    = var.project_name
  }

  public_net {
    ipv4_enabled = true
    ipv6_enabled = true
  }

  network {
    network_id = var.network_id
  }

  user_data = file("${path.module}/cloud-init.yml")

  lifecycle {
    ignore_changes = [user_data]   # cloud-init only on first boot
  }
}

resource "hcloud_volume_attachment" "data" {
  volume_id = var.volume_id
  server_id = hcloud_server.app.id
  automount = true
}
```

Cloud-Init (`cloud-init.yml`) installs Docker + Compose, mounts the volume to `/mnt/data`, and configures unattended-upgrades. Can be customized per project.

#### `infrastructure/modules/network/main.tf`

```hcl
resource "hcloud_network" "vpc" {
  name     = "${var.project_name}-vpc"
  ip_range = "10.0.0.0/16"
}

resource "hcloud_network_subnet" "app" {
  network_id   = hcloud_network.vpc.id
  type         = "cloud"
  network_zone = "eu-central"
  ip_range     = "10.0.1.0/24"
}

resource "hcloud_firewall" "app" {
  name = "${var.project_name}-fw"

  # SSH
  rule {
    direction  = "in"
    protocol   = "tcp"
    port       = "22"
    source_ips = ["0.0.0.0/0", "::/0"]   # Restrict to own IPs after setup
  }

  # HTTP
  rule {
    direction  = "in"
    protocol   = "tcp"
    port       = "80"
    source_ips = ["0.0.0.0/0", "::/0"]
  }

  # HTTPS
  rule {
    direction  = "in"
    protocol   = "tcp"
    port       = "443"
    source_ips = ["0.0.0.0/0", "::/0"]
  }
}
```

#### `infrastructure/modules/volume/main.tf`

```hcl
resource "hcloud_volume" "data" {
  name     = "${var.project_name}-data"
  size     = var.size
  location = var.location
  format   = "ext4"

  labels = {
    managed_by = "opentofu"
    project    = var.project_name
  }
}
```

#### `infrastructure/modules/dns/main.tf`

```hcl
resource "cloudflare_dns_record" "ipv4" {
  for_each = toset(var.subdomains)

  zone_id = var.zone_id
  name    = each.value
  content = var.server_ipv4
  type    = "A"
  proxied = true
  ttl     = 1     # Auto (proxied)
}

resource "cloudflare_dns_record" "ipv6" {
  for_each = toset(var.subdomains)

  zone_id = var.zone_id
  name    = each.value
  content = var.server_ipv6
  type    = "AAAA"
  proxied = true
  ttl     = 1
}
```

#### Makefile Addition

```makefile
# ── Infrastructure ──────────────────────
tofu-init:            ## OpenTofu init
	cd infrastructure && tofu init

tofu-plan:            ## OpenTofu plan (dry-run)
	cd infrastructure && tofu plan

tofu-apply:           ## OpenTofu apply (WARNING: creates real resources!)
	cd infrastructure && tofu apply
```

#### `.gitignore` — Complete

```
# ── Backend (Symfony) ───────────────
backend/vendor/
backend/var/
backend/.env.local
backend/.env.*.local
backend/config/jwt/*.pem
backend/.phpunit.result.cache
backend/.php-cs-fixer.cache

# ── Frontend (SvelteKit) ───────────
frontend/node_modules/
frontend/.svelte-kit/
frontend/build/
frontend/.env.local

# ── Docker ──────────────────────────
docker/frankenphp/conf.d/*.local.ini

# ── Infrastructure (OpenTofu) ───────
infrastructure/.terraform/
infrastructure/*.tfstate
infrastructure/*.tfstate.backup
infrastructure/terraform.tfvars
infrastructure/crash.log

# ── IDE ─────────────────────────────
.idea/
.vscode/
*.swp
*.swo
.DS_Store
Thumbs.db

# ── OS ──────────────────────────────
.DS_Store
Thumbs.db
```
