<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.3-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP"/>
  <img src="https://img.shields.io/badge/MySQL-8-4479A1?style=flat-square&logo=mysql&logoColor=white" alt="MySQL"/>
  <img src="https://img.shields.io/badge/SaaS-Finanças-0ea5e9?style=flat-square" alt="SaaS"/>
</p>

<h1 align="center">GranaFlow</h1>

<p align="center">
  <strong>SaaS de finanças pessoais</strong> — receitas, custos fixos, recorrências, gráficos, metas e orçamento por categoria.
</p>

<p align="center">
  <a href="https://granaflow.tdesksolutions.com.br"><strong>Ver demo</strong></a> ·
  <a href="https://portifolio-douglas-moura.vercel.app">Portfólio</a> ·
  <a href="https://github.com/douglasmouradev/Grana-Flow">Repositório</a>
</p>

## Preview

<p align="center">
  <img src="https://raw.githubusercontent.com/douglasmouradev/portifolio/main/assets/projects/granaflow.jpg" alt="GranaFlow dashboard" width="720"/>
</p>

---

# GranaFlow (PHP + MySQL)

Mini SaaS de organização financeira para:

- cadastrar conta de usuário (multiusuário)
- lançar salário mensal
- registrar receitas extras
- configurar contas recorrentes automáticas
- organizar lançamentos por categoria
- registrar custos fixos e gastos
- visualizar saldo e gráficos com base na receita total
- definir meta mensal e orçamento por categoria

## Requisitos

- PHP 8.3+
- MySQL 8+
- Extensões PHP: `pdo_mysql`, `mbstring`

## Configuração

1. Copie o arquivo de ambiente:

```bash
cp .env.example .env
```

2. Ajuste as credenciais no `.env` conforme seu MySQL (`DB_NAME=custoflow`).

3. Crie o banco e tabelas:

```bash
mysql -u root -p < database/schema.sql
```

4. Sirva a aplicação (ajuste o caminho conforme seu ambiente):

```bash
php -S localhost:8000 -t public
```

## Destaques

- Dashboard com gráficos e saldo em tempo real
- Orçado vs real por categoria e metas mensais
- PDO e front estático em `public/assets`
- Deploy em VPS (Nginx, PHP-FPM, MariaDB)

## Licença

MIT — ver [LICENSE](LICENSE).
