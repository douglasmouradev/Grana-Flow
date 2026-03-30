# GranaFlow (PHP + MySQL)

Mini SaaS de organizacao financeira para:
- cadastrar conta de usuario (multiusuario),
- lancar salario mensal,
- registrar receitas extras,
- configurar contas recorrentes automaticas,
- organizar lancamentos por categoria,
- registrar custos fixos e gastos,
- visualizar saldo e graficos com base na receita total,
- definir meta mensal e orcamento por categoria.

## Requisitos

- PHP 8.4+ (ou 8.3+)
- MySQL 8+
- Extensoes PHP: `pdo_mysql`, `mbstring`

## Configuracao

1. Copie o arquivo de ambiente:

```bash
cp .env.example .env
```

2. Ajuste as credenciais no `.env` conforme seu MySQL.
   Use `DB_NAME=custo`.

3. Crie o banco e tabelas:

```bash
mysql -u root -p < database/schema.sql
```

Se o banco ja existia antes desta funcionalidade, rode tambem:

```bash
mysql -u root -p -D custo -e "CREATE TABLE IF NOT EXISTS extra_incomes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, description VARCHAR(255) NOT NULL, amount DECIMAL(12,2) NOT NULL, income_date DATE NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_extra_incomes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, INDEX idx_extra_incomes_user_date (user_id, income_date));"
```

E para categorias:

```bash
mysql -u root -p -D custo -e "CREATE TABLE IF NOT EXISTS categories (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, name VARCHAR(80) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_categories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, UNIQUE KEY uk_user_category (user_id, name)); ALTER TABLE transactions ADD COLUMN category_id BIGINT UNSIGNED NULL;"
```

E para metas/orcamentos:

```bash
mysql -u root -p -D custo -e "CREATE TABLE IF NOT EXISTS monthly_goals (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, month_key CHAR(7) NOT NULL, target_amount DECIMAL(12,2) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT fk_monthly_goals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, UNIQUE KEY uk_user_month_goal (user_id, month_key)); CREATE TABLE IF NOT EXISTS category_budgets (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, category_id BIGINT UNSIGNED NOT NULL, month_key CHAR(7) NOT NULL, budget_amount DECIMAL(12,2) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT fk_category_budgets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_category_budgets_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE, UNIQUE KEY uk_user_category_month_budget (user_id, category_id, month_key));"
```

E para contas recorrentes:

```bash
mysql -u root -p -D custo -e "CREATE TABLE IF NOT EXISTS recurring_entries (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, type ENUM('cost','expense') NOT NULL, description VARCHAR(255) NOT NULL, amount DECIMAL(12,2) NOT NULL, day_of_month TINYINT UNSIGNED NOT NULL, category_id BIGINT UNSIGNED NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, last_generated_month CHAR(7) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT fk_recurring_entries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_recurring_entries_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL, INDEX idx_recurring_entries_user_active (user_id, is_active));"
```

## Rodando localmente

No diretorio raiz do projeto:

```bash
php -S localhost:8000 -t public
```

Acesse: `http://localhost:8000`

## Estrutura

- `public/index.php`: interface web + rotas basicas
- `src/auth.php`: registro, login e logout
- `src/finance.php`: salario, lancamentos e totais
- `src/db.php`: conexao PDO com MySQL
- `database/schema.sql`: schema do banco

## Observacoes

- O grafico de pizza exibe: custos fixos, gastos e saldo restante.
- O saldo considera `receita total - (custos + gastos)`.
- Cada usuario enxerga apenas os proprios dados.
