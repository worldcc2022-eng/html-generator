#!/usr/bin/env bash
set -euo pipefail
if [[ "$EUID" -ne 0 ]]; then
  echo "Run this installer as root." >&2
  exit 1
fi
TASK_ROOT="$(realpath -- "$(dirname -- "${BASH_SOURCE[0]}")/..")"
TASK_UNIT=eventforge-indexing.service
TASK_SOURCE="$TASK_ROOT/deploy/eventforge-indexing-recovery.conf"
TASK_DROPIN=/etc/systemd/system/eventforge-indexing.service.d
if [[ ! -f "$TASK_ROOT/app/IndexingWorkerRecovery.php" || ! -f "$TASK_SOURCE" ]]; then
  echo "Extract the complete recovery patch into public_html before running this installer." >&2
  exit 1
fi
if [[ -z "$(systemctl show "$TASK_UNIT" --property=FragmentPath --value)" ]]; then
  echo "The existing eventforge-indexing.service unit was not found. No service settings changed." >&2
  exit 1
fi
install -d -m 0755 "$TASK_DROPIN"
if [[ -f "$TASK_DROPIN/60-eventforge-recovery.conf" ]]; then
  cp -p -- "$TASK_DROPIN/60-eventforge-recovery.conf" "$TASK_DROPIN/60-eventforge-recovery.conf.backup-$(date +%Y%m%d-%H%M%S)"
fi
install -m 0644 "$TASK_SOURCE" "$TASK_DROPIN/60-eventforge-recovery.conf"
systemctl daemon-reload
systemctl reset-failed "$TASK_UNIT"
systemctl enable "$TASK_UNIT"
systemctl restart "$TASK_UNIT"
systemctl show "$TASK_UNIT" -p ActiveState -p SubState -p Restart -p RestartUSec -p RuntimeMaxUSec -p NRestarts
