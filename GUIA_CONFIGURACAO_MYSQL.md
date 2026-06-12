# Guia de Configuração do MySQL no XAMPP

Este guia irá ajudá-lo a configurar o banco de dados MySQL no XAMPP para o Sistema de Pausas.

## Passo 1: Iniciar o Servidor MySQL

1.  Abra o **XAMPP Control Panel**.
2.  Certifique-se de que o módulo **Apache** e **MySQL** estejam iniciados (botão "Start" deve estar clicado e os nomes com fundo verde).

## Passo 2: Acessar o phpMyAdmin

1.  Abra seu navegador e digite: `http://localhost/phpmyadmin`
2.  Você verá a interface de gerenciamento do banco de dados.

## Passo 3: Criar o Banco de Dados

1.  No menu lateral esquerdo, clique em **Novo** (ou "New").
2.  No campo "Nome do banco de dados", digite: `sistema_pausas`
3.  No campo de codificação (ao lado do nome), selecione `utf8mb4_unicode_ci`.
4.  Clique no botão **Criar**.

## Passo 4: Importar a Estrutura (Tabelas)

1.  Com o banco `sistema_pausas` selecionado na barra lateral esquerda:
2.  Clique na aba **Importar** (na parte superior).
3.  Clique no botão **Escolher arquivo** (ou "Choose File").
4.  Navegue até a pasta do projeto (`c:\xampp\htdocs\Sistema_Pausas`) e selecione o arquivo `database.sql`.
5.  Role até o final da página e clique no botão **Importar** (ou "Go").
6.  Você deve ver uma mensagem verde confirmando que a importação foi executada com sucesso.

## Passo 5: Criar Usuário Dedicado

Em produção, não use `root`. Crie um usuário dedicado com permissões mínimas para o banco do ChronoDesk:

```sql
CREATE USER 'chronodesk_user'@'localhost' IDENTIFIED BY 'troque_esta_senha';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX
  ON sistema_pausas.* TO 'chronodesk_user'@'localhost';
FLUSH PRIVILEGES;
```

Não conceda `GRANT OPTION`, `SUPER`, `FILE` nem acesso a outros bancos.

Mesmo em desenvolvimento, configure o usuário dedicado da aplicação:

```env
DB_HOST=localhost
DB_NAME=sistema_pausas
DB_USER=chronodesk_user
DB_PASS=troque_esta_senha
```

Em produção, `APP_ENV=production` exige usuário diferente de `root` e senha definida.

## Passo 6: Migrar Dados Antigos (Opcional)

Se você já usava o sistema com arquivos CSV e deseja transferir o histórico de pausas para o novo banco de dados MySQL:

1.  Abra o navegador e acesse: `http://localhost/Sistema_Pausas/migrar_csv_para_mysql.php`
2.  Se tudo correr bem, você verá uma mensagem de sucesso indicando quantos registros foram importados.
3.  Após a migração, o sistema passará a usar o MySQL como armazenamento principal e o CSV apenas como backup de segurança.

---

## Solução de Problemas Comuns

- **Erro "Access denied for user 'root'@'localhost'":** Verifique se a senha no arquivo `config.php` está correta.
- **Erro "Unknown database 'sistema_pausas'":** Certifique-se de ter criado o banco de dados com o nome exato no Passo 3.
- **Erro de fuso horário:** O sistema está configurado para `America/Sao_Paulo`. Verifique se o horário do seu PC está correto.

Agora seu sistema está rodando com MySQL! 🚀
