#!/usr/bin/env bash
# Arranca Ollama en este Ubuntu (WSL) escuchando en todas las interfaces,
# para que los contenedores Docker (bot / llm-worker) puedan usar:
#   BOT_LLM_URL=http://host.docker.internal:11434
#
# Requisitos:
#   curl -fsSL https://ollama.com/install.sh | sh
#
# Uso (en una terminal aparte, dejándola abierta):
#   bash scripts/wsl_run_ollama_for_bots.sh
#
# Comprobar desde WSL:
#   curl -sS http://127.0.0.1:11434/api/tags | head
#
# Comprobar desde un contenedor:
#   docker compose exec bot wget -qO- http://host.docker.internal:11434/api/tags | head

set -euo pipefail

if ! command -v ollama >/dev/null 2>&1; then
  echo "No está instalado 'ollama'. En Ubuntu/WSL ejecuta:" >&2
  echo "  curl -fsSL https://ollama.com/install.sh | sh" >&2
  exit 1
fi

export OLLAMA_HOST="${OLLAMA_HOST:-0.0.0.0:11434}"
echo "Iniciando Ollama con OLLAMA_HOST=${OLLAMA_HOST} (Ctrl+C para detener)"
exec ollama serve
