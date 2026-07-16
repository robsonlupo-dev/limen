#!/bin/bash
#
# Backup do Limen — banco MySQL + storage privado (mídia) + documentos KYC.
# Cobre dois discos separados: storage/app/private e storage/app/kyc (.enc).
# Instalar em /home/deploy/backup.sh no servidor e agendar via cron, ex.:
#   0 3 * * * /home/deploy/backup.sh >> /home/deploy/backup.log 2>&1
#
# Preencha as variáveis abaixo (ou exporte via ambiente) antes de usar.
#
set -euo pipefail

# ── Configuração ────────────────────────────────────────────────────────────
DB_NAME="${DB_NAME:-limen}"
DB_USER="${DB_USER:-limen}"
DB_PASS="${DB_PASS:?Defina DB_PASS}"
APP_DIR="/var/www/limen"
BACKUP_DIR="/home/deploy/backups"
RETENTION_DAYS=14
STAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$BACKUP_DIR"

# Chave pública GPG para criptografar backups em repouso (contêm PII: CPF/KYC).
# Gere um par dedicado e exporte só a pública para o servidor.
GPG_RECIPIENT="${GPG_RECIPIENT:?Defina GPG_RECIPIENT (backups têm PII e devem ser criptografados)}"

# Senha via MYSQL_PWD, não na linha de comando (evita exposição em ps/proc).
export MYSQL_PWD="$DB_PASS"

# ── Dump do banco (comprimido + criptografado) ──────────────────────────────
echo "▶ Dump do banco $DB_NAME"
mysqldump --single-transaction --quick --lock-tables=false \
  -u "$DB_USER" "$DB_NAME" \
  | gzip \
  | gpg --batch --yes --encrypt --recipient "$GPG_RECIPIENT" \
  > "$BACKUP_DIR/db-$STAMP.sql.gz.gpg"

unset MYSQL_PWD

# ── Arquivos privados de storage (mídia + documentos KYC) ───────────────────
# Dois discos distintos: 'private' (storage/app/private) e 'kyc'
# (storage/app/kyc, docs .enc). Ambos entram no mesmo tarball criptografado.
echo "▶ Backup de storage/app/private + storage/app/kyc (criptografado)"
# Garante que os diretórios existam: num servidor novo que ainda não
# recebeu upload, o disco 'kyc' (ou 'private') pode não existir, e com
# 'set -e' o tar abortaria o backup inteiro.
mkdir -p "$APP_DIR/storage/app/private" "$APP_DIR/storage/app/kyc"
tar -czf - -C "$APP_DIR" storage/app/private storage/app/kyc \
  | gpg --batch --yes --encrypt --recipient "$GPG_RECIPIENT" \
  > "$BACKUP_DIR/storage-$STAMP.tar.gz.gpg"

# ── Retenção ────────────────────────────────────────────────────────────────
echo "▶ Removendo backups com mais de $RETENTION_DAYS dias"
find "$BACKUP_DIR" -type f -name "*.gpg" -mtime +"$RETENTION_DAYS" -delete

echo "✅ Backup concluído: $STAMP"

# Recomendado: enviar $BACKUP_DIR para storage externo (S3/Backblaze) fora do
# servidor. Os arquivos já estão criptografados com GPG. Ex.:
#   rclone copy "$BACKUP_DIR" remote:limen-backups
