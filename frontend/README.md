# ChronoDesk frontend

## Desenvolvimento

```bash
cd frontend
npm ci
npm run dev
```

O Vite encaminha `/api` para `http://127.0.0.1`. Para testar a aplicação compilada no Apache:

```bash
npm run build
sudo mkdir -p /var/www/chronodesk/app
sudo rsync -a --delete dist/ /var/www/chronodesk/app/
```

Acesse `/app/`. A interface antiga continua disponível em `/index.php`, `/admin.php` e `/metricas.php`.

O bundle não contém credenciais ou configuração LDAP. Cookies de sessão são enviados apenas para a mesma origem, e todo `POST` inclui o token CSRF obtido em `/api/session.php`.
