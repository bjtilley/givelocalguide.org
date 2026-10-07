### Old givelocalguide.org GoDoaddy IP Address
68.178.220.172


API Login ID: 25gR8GWt7rH

Current Transaction Key: 8G5Z8yRpvxJ542vv

Current Signature Key:
BD66E2A977A9EC4791D86BB72CB9A40EFDB9896D0F1ACF3F3158F99422617BAAA4250A37836A14F23799DA797F632D3502325C83EFDCFC5BA89197945CF96662

perl -0777 -pe \
's/\butf8mb4_0900_ai_ci\b/utf8mb4_unicode_ci/g;
s/\butf8mb4_0900_bin\b/utf8mb4_bin/g;
s/\butf8mb4_0900_as_cs\b/utf8mb4_unicode_ci/g;' \
-i.bak /mnt/shared/wordpress_backup_2025-11-11.sql


*Egl7oCoZ4zajZs2

### BackBlaze Master Key:
keyID: 00129dd17fd26c50000000002 \
keyName: brandont-master-key \
applicationKey: K001dHuvl5oyrT3yQvhri81923GLE4g

### TODO
- Logic fix for free product gift types, don't allow ACF fields for edits and fix MYSQL to only look at non profits for summing values.

## Deploy (DigitalOcean)

GitHub Actions rsyncs **only** these paths to the Droplet:

- `wp-content/themes/blocksy-child/`
- `wp-content/mu-plugins/`
- `wp-content/plugins/custom-checkout-fields/`

WordPress core, the Blocksy parent theme, other plugins, uploads, and MySQL stay on the server and are updated in WP admin. Do not edit those three git-owned folders through WP admin — it would fight git. Parent Blocksy is not in this repo; keep it installed on the Droplet.

Remote destination is the Actions variable `DROPLET_WP_CONTENT_PATH` (the Droplet’s `wp-content` directory). The workflow does not run on push. Deploy from **Actions** → **Deploy** → **Run workflow**, and choose branch **master**. A run from any other branch fails before rsync. Check **Dry run** to preview changes without writing to the Droplet. Leave it unchecked to deploy.

### Who creates the `deploy` user

**You do, once.** SSH into the Droplet as **root**. GitHub Actions never creates users.

### 1. SSH key (on your Mac)

```bash
ssh-keygen -t ed25519 -C "github-actions-glg-deploy" -f ~/.ssh/glg_deploy_ed25519 -N ""
```

- Private key `~/.ssh/glg_deploy_ed25519` → GitHub **Actions repository secret** named `DROPLET_SSH_PRIVATE_KEY` (never commit it; not a Deploy key)
- Public key `~/.ssh/glg_deploy_ed25519.pub` → Droplet `authorized_keys`

### 2. Droplet bootstrap (you, as root)

Set `WP_CONTENT` to the same path you will store as `DROPLET_WP_CONTENT_PATH`.

```bash
WP_CONTENT=/path/to/wp-content

adduser --disabled-password --gecos "" deploy
mkdir -p /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
```

Paste the public key (`cat ~/.ssh/glg_deploy_ed25519.pub` on your Mac):

```bash
nano /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys
chown -R deploy:deploy /home/deploy/.ssh

for rel in themes/blocksy-child mu-plugins plugins/custom-checkout-fields; do
  mkdir -p "${WP_CONTENT}/${rel}"
  chown -R deploy:www-data "${WP_CONTENT}/${rel}"
  find "${WP_CONTENT}/${rel}" -type d -exec chmod 775 {} \;
  find "${WP_CONTENT}/${rel}" -type f -exec chmod 664 {} \;
done
```

GitHub-hosted runners need SSH (port 22) reachable from the internet.

Smoke-test from your Mac (replace `DROPLET_HOST` and the `wp-content` path):

```bash
ssh -i ~/.ssh/glg_deploy_ed25519 deploy@DROPLET_HOST 'whoami && ls /path/to/wp-content/themes /path/to/wp-content/mu-plugins /path/to/wp-content/plugins'
```

### 3. GitHub secrets and variables

The private key is **not** a Deploy key and **not** an account SSH key. Those screens only accept a **public** key (`ssh-ed25519 AAAA…`) and will say “incorrect format” if you paste `glg_deploy_ed25519`.

Use **Actions repository secrets**:

1. Open the GitHub **repository** (not your profile).
2. **Settings** → **Secrets and variables** → **Actions**.
3. Tab **Secrets** → **New repository secret**.
4. Name: `DROPLET_SSH_PRIVATE_KEY`
5. Value: the **entire** private key file, including the BEGIN/END lines. Copy with:

```bash
pbcopy < ~/.ssh/glg_deploy_ed25519
```

The pasted value must look like:

```
-----BEGIN OPENSSH PRIVATE KEY-----
(several lines of base64)
-----END OPENSSH PRIVATE KEY-----
```

Do not wrap it in quotes. Leave a trailing newline after `END`. Then **Add secret**.

Also add secrets:

- `DROPLET_HOST` — Droplet IP or hostname
- `DROPLET_USER` — `deploy`

Then **Variables** (same Actions page, **Variables** tab, not Secrets) → **New repository variable**:

- `DROPLET_WP_CONTENT_PATH` — absolute `wp-content` path on the Droplet

Workflow: `.github/workflows/deploy.yml`.
