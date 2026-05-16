#!/bin/bash

# Sistema de Gerenciamento de Pausas - Script de Configuração (Linux/macOS)
# Requer bash 4.0 ou superior

echo "========================================"
echo "  Sistema de Gerenciamento de Pausas"
echo "  Configuração Automática - Linux/macOS"
echo "========================================"
echo ""

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Verificar se está executando como root (opcional)
if [ "$EUID" -eq 0 ]; then 
    echo -e "${YELLOW}[AVISO] Executando como root${NC}"
    echo "Algumas operações podem não requerer privilégios elevados"
    echo ""
fi

# Detectar sistema operacional
if [[ "$OSTYPE" == "linux-gnu"* ]]; then
    OS="linux"
    XAMPP_PATH="/opt/lampp"
    APACHE_USER="daemon"
elif [[ "$OSTYPE" == "darwin"* ]]; then
    OS="macos"
    XAMPP_PATH="/Applications/XAMPP"
    APACHE_USER="_www"
else
    echo -e "${RED}[ERRO] Sistema operacional não suportado: $OSTYPE${NC}"
    exit 1
fi

INSTALL_DIR="$XAMPP_PATH/htdocs/Sistema_Pausas"

# Verificar se o XAMPP está instalado
echo -e "${YELLOW}[1/5] Verificando instalação do XAMPP...${NC}"
if [ ! -f "$XAMPP_PATH/apache/bin/httpd" ]; then
    echo -e "${RED}[ERRO] XAMPP não encontrado em $XAMPP_PATH${NC}"
    echo ""
    echo "Por favor, instale o XAMPP ou ajuste o caminho no script."
    echo "Download: https://www.apachefriends.org/"
    exit 1
fi
echo -e "${GREEN}[OK] XAMPP encontrado!${NC}"

# Verificar PHP
echo -e "${YELLOW}[2/5] Verificando PHP...${NC}"
PHP_PATH="$XAMPP_PATH/bin/php"
if [ ! -f "$PHP_PATH" ]; then
    echo -e "${RED}[ERRO] PHP não encontrado no XAMPP${NC}"
    exit 1
fi

PHP_VERSION=$($PHP_PATH -v 2>&1 | head -n 1)
echo -e "${GREEN}[OK] PHP encontrado: $PHP_VERSION${NC}"

# Verificar extensão ZipArchive
echo -e "${YELLOW}[3/5] Verificando extensão ZipArchive...${NC}"
if $PHP_PATH -m 2>&1 | grep -qi "zip"; then
    echo -e "${GREEN}[OK] Extensão ZipArchive encontrada!${NC}"
else
    echo -e "${YELLOW}[AVISO] Extensão ZipArchive não encontrada. Relatórios ZIP podem não funcionar.${NC}"
fi

# Criar diretório de instalação
echo -e "${YELLOW}[4/5] Preparando diretório de instalação...${NC}"
if [ ! -d "$INSTALL_DIR" ]; then
    mkdir -p "$INSTALL_DIR"
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}[OK] Diretório criado: $INSTALL_DIR${NC}"
    else
        echo -e "${RED}[ERRO] Falha ao criar diretório${NC}"
        exit 1
    fi
else
    echo -e "${GREEN}[OK] Diretório já existe: $INSTALL_DIR${NC}"
fi

# Verificar arquivos necessários
echo -e "${YELLOW}[5/5] Verificando arquivos do sistema...${NC}"
REQUIRED_FILES=(
    "config.php"
    "init.php"
    "index.php"
    "login.php"
    "metricas.php"
    "classes/Funcionario.php"
    "classes/GerenciadorPausas.php"
)

MISSING_FILES=()
for file in "${REQUIRED_FILES[@]}"; do
    if [ ! -f "$INSTALL_DIR/$file" ]; then
        MISSING_FILES+=("$file")
    fi
done

if [ ${#MISSING_FILES[@]} -gt 0 ]; then
    echo -e "${YELLOW}[AVISO] Alguns arquivos não foram encontrados:${NC}"
    for file in "${MISSING_FILES[@]}"; do
        echo -e "  ${YELLOW}- $file${NC}"
    done
    echo ""
    echo "Por favor, copie todos os arquivos do projeto para:"
    echo -e "${CYAN}$INSTALL_DIR${NC}"
else
    echo -e "${GREEN}[OK] Todos os arquivos necessários encontrados!${NC}"
fi

# Configurar permissões
echo ""
echo -e "${YELLOW}Configurando permissões...${NC}"
chmod -R 755 "$INSTALL_DIR" 2>/dev/null
if [ -f "$INSTALL_DIR/pausas.csv" ]; then
    chmod 666 "$INSTALL_DIR/pausas.csv" 2>/dev/null
fi
if [ -f "$INSTALL_DIR/estado.json" ]; then
    chmod 666 "$INSTALL_DIR/estado.json" 2>/dev/null
fi
echo -e "${GREEN}[OK] Permissões configuradas!${NC}"

echo ""
echo "========================================"
echo -e "${GREEN}  Configuração Concluída!${NC}"
echo "========================================"
echo ""
echo "Diretório de instalação: $INSTALL_DIR"
echo ""
echo "Próximos passos:"
echo "1. Se ainda não fez, copie todos os arquivos para: $INSTALL_DIR"
echo "2. Execute o script 'iniciar.sh' para iniciar o servidor"
echo ""
echo "Ou inicie manualmente pelo Painel de Controle do XAMPP"
echo ""

