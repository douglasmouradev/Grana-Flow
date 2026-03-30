<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function getCategories(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT id, name
         FROM categories
         WHERE user_id = :user_id
         ORDER BY name ASC'
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll() ?: [];
}

function addRecurringEntry(
    int $userId,
    string $type,
    string $description,
    float $amount,
    int $dayOfMonth,
    ?int $categoryId = null
): array {
    $allowedTypes = ['cost', 'expense'];
    if (!in_array($type, $allowedTypes, true)) {
        return ['ok' => false, 'message' => 'Tipo de recorrencia invalido.'];
    }
    if ($description === '' || $amount <= 0 || $dayOfMonth < 1 || $dayOfMonth > 31) {
        return ['ok' => false, 'message' => 'Preencha os dados da recorrencia corretamente.'];
    }

    $stmt = db()->prepare(
        'INSERT INTO recurring_entries (user_id, type, description, amount, day_of_month, category_id, is_active)
         VALUES (:user_id, :type, :description, :amount, :day_of_month, :category_id, 1)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'type' => $type,
        'description' => $description,
        'amount' => $amount,
        'day_of_month' => $dayOfMonth,
        'category_id' => $categoryId,
    ]);

    return ['ok' => true, 'message' => 'Conta recorrente cadastrada com sucesso.'];
}

function getRecurringEntries(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT r.id, r.type, r.description, r.amount, r.day_of_month, r.is_active, r.last_generated_month, c.name AS category_name
         FROM recurring_entries r
         LEFT JOIN categories c ON c.id = r.category_id
         WHERE r.user_id = :user_id
         ORDER BY r.created_at DESC, r.id DESC'
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll() ?: [];
}

function deleteRecurringEntry(int $userId, int $recurringId): void
{
    $stmt = db()->prepare('DELETE FROM recurring_entries WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $recurringId, 'user_id' => $userId]);
}

function setRecurringEntryStatus(int $userId, int $recurringId, bool $active): void
{
    $stmt = db()->prepare('UPDATE recurring_entries SET is_active = :active WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        'active' => $active ? 1 : 0,
        'id' => $recurringId,
        'user_id' => $userId,
    ]);
}

function processRecurringEntries(int $userId): void
{
    $today = new DateTimeImmutable('today');
    $currentMonth = $today->format('Y-m');
    $currentDay = (int) $today->format('d');

    $stmt = db()->prepare(
        'SELECT id, type, description, amount, day_of_month, category_id, last_generated_month
         FROM recurring_entries
         WHERE user_id = :user_id AND is_active = 1'
    );
    $stmt->execute(['user_id' => $userId]);
    $entries = $stmt->fetchAll() ?: [];

    foreach ($entries as $entry) {
        $day = (int) $entry['day_of_month'];
        $lastMonth = is_string($entry['last_generated_month']) ? $entry['last_generated_month'] : null;
        $startMonth = $lastMonth ? (new DateTimeImmutable($lastMonth . '-01'))->modify('+1 month') : new DateTimeImmutable($currentMonth . '-01');

        for ($cursor = $startMonth; $cursor->format('Y-m') <= $currentMonth; $cursor = $cursor->modify('+1 month')) {
            $monthKey = $cursor->format('Y-m');
            if ($monthKey === $currentMonth && $currentDay < $day) {
                break;
            }

            $lastDayOfMonth = (int) $cursor->format('t');
            $effectiveDay = min($day, $lastDayOfMonth);
            $date = $cursor->format('Y-m-') . str_pad((string) $effectiveDay, 2, '0', STR_PAD_LEFT);

            addTransaction(
                $userId,
                (string) $entry['type'],
                (string) $entry['description'],
                (float) $entry['amount'],
                $date,
                isset($entry['category_id']) ? (int) $entry['category_id'] : null
            );

            $update = db()->prepare('UPDATE recurring_entries SET last_generated_month = :month_key WHERE id = :id');
            $update->execute(['month_key' => $monthKey, 'id' => (int) $entry['id']]);
        }
    }
}

function createDefaultCategories(int $userId): void
{
    $defaults = ['Moradia', 'Alimentacao', 'Transporte', 'Saude', 'Lazer', 'Educacao', 'Outros'];
    $stmt = db()->prepare(
        'INSERT IGNORE INTO categories (user_id, name) VALUES (:user_id, :name)'
    );
    foreach ($defaults as $name) {
        $stmt->execute(['user_id' => $userId, 'name' => $name]);
    }
}

function resolveCategoryId(int $userId, ?string $categoryName): ?int
{
    $name = trim((string) $categoryName);
    if ($name === '') {
        return null;
    }

    $stmt = db()->prepare('SELECT id FROM categories WHERE user_id = :user_id AND name = :name LIMIT 1');
    $stmt->execute([
        'user_id' => $userId,
        'name' => $name,
    ]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        return (int) $existing;
    }

    $insert = db()->prepare('INSERT INTO categories (user_id, name) VALUES (:user_id, :name)');
    $insert->execute([
        'user_id' => $userId,
        'name' => $name,
    ]);
    return (int) db()->lastInsertId();
}

function getSalary(int $userId): float
{
    $stmt = db()->prepare('SELECT monthly_salary FROM finance_settings WHERE user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch();

    return $row ? (float) $row['monthly_salary'] : 0.0;
}

function updateSalary(int $userId, float $salary): void
{
    $stmt = db()->prepare(
        'INSERT INTO finance_settings (user_id, monthly_salary)
         VALUES (:user_id, :monthly_salary)
         ON DUPLICATE KEY UPDATE monthly_salary = VALUES(monthly_salary)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'monthly_salary' => $salary,
    ]);
}

function addTransaction(int $userId, string $type, string $description, float $amount, string $date, ?int $categoryId = null): array
{
    $allowedTypes = ['cost', 'expense'];
    if (!in_array($type, $allowedTypes, true)) {
        return ['ok' => false, 'message' => 'Tipo de lancamento invalido.'];
    }

    if ($description === '' || $amount <= 0 || $date === '') {
        return ['ok' => false, 'message' => 'Preencha descricao, valor e data corretamente.'];
    }

    $stmt = db()->prepare(
        'INSERT INTO transactions (user_id, type, description, amount, transaction_date, category_id)
         VALUES (:user_id, :type, :description, :amount, :transaction_date, :category_id)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'type' => $type,
        'description' => $description,
        'amount' => $amount,
        'transaction_date' => $date,
        'category_id' => $categoryId,
    ]);

    return ['ok' => true, 'message' => 'Lancamento adicionado com sucesso.'];
}

function updateTransaction(
    int $userId,
    int $transactionId,
    string $type,
    string $description,
    float $amount,
    string $date,
    ?int $categoryId = null
): array {
    $allowedTypes = ['cost', 'expense'];
    if (!in_array($type, $allowedTypes, true)) {
        return ['ok' => false, 'message' => 'Tipo de lancamento invalido.'];
    }
    if ($description === '' || $amount <= 0 || $date === '') {
        return ['ok' => false, 'message' => 'Preencha descricao, valor e data corretamente.'];
    }

    $stmt = db()->prepare(
        'UPDATE transactions
         SET type = :type, description = :description, amount = :amount, transaction_date = :transaction_date, category_id = :category_id
         WHERE id = :id AND user_id = :user_id'
    );
    $stmt->execute([
        'type' => $type,
        'description' => $description,
        'amount' => $amount,
        'transaction_date' => $date,
        'category_id' => $categoryId,
        'id' => $transactionId,
        'user_id' => $userId,
    ]);

    return ['ok' => true, 'message' => 'Lancamento atualizado com sucesso.'];
}

function deleteTransaction(int $userId, int $transactionId): void
{
    $stmt = db()->prepare('DELETE FROM transactions WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        'id' => $transactionId,
        'user_id' => $userId,
    ]);
}

function getTransactions(
    int $userId,
    int $limit = 100,
    int $offset = 0,
    ?string $month = null,
    ?string $search = null
): array
{
    $limit = max(1, min($limit, 300));
    $offset = max(0, $offset);

    $where = ['t.user_id = :user_id'];
    $params = ['user_id' => $userId];

    if ($month !== null && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
        $where[] = 'DATE_FORMAT(t.transaction_date, "%Y-%m") = :month_key';
        $params['month_key'] = $month;
    }
    if ($search !== null && trim($search) !== '') {
        $where[] = 't.description LIKE :search';
        $params['search'] = '%' . trim($search) . '%';
    }

    $sql = 'SELECT t.id, t.type, t.description, t.amount, t.transaction_date, t.category_id, c.name AS category_name
            FROM transactions t
            LEFT JOIN categories c ON c.id = t.category_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY t.transaction_date DESC, t.id DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function countTransactions(int $userId, ?string $month = null, ?string $search = null): int
{
    $where = ['user_id = :user_id'];
    $params = ['user_id' => $userId];

    if ($month !== null && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
        $where[] = 'DATE_FORMAT(transaction_date, "%Y-%m") = :month_key';
        $params['month_key'] = $month;
    }
    if ($search !== null && trim($search) !== '') {
        $where[] = 'description LIKE :search';
        $params['search'] = '%' . trim($search) . '%';
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM transactions WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function getTotals(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN type = "cost" THEN amount ELSE 0 END), 0) AS total_costs,
            COALESCE(SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END), 0) AS total_expenses
         FROM transactions
         WHERE user_id = :user_id'
    );
    $stmt->execute(['user_id' => $userId]);
    $totals = $stmt->fetch() ?: ['total_costs' => 0, 'total_expenses' => 0];

    $incomeStmt = db()->prepare(
        'SELECT COALESCE(SUM(amount), 0) AS total_extra_incomes
         FROM extra_incomes
         WHERE user_id = :user_id'
    );
    $incomeStmt->execute(['user_id' => $userId]);
    $incomeRow = $incomeStmt->fetch() ?: ['total_extra_incomes' => 0];

    $salary = getSalary($userId);
    $extraIncomes = (float) $incomeRow['total_extra_incomes'];
    $totalIncome = $salary + $extraIncomes;
    $costs = (float) $totals['total_costs'];
    $expenses = (float) $totals['total_expenses'];
    $spent = $costs + $expenses;
    $remaining = $totalIncome - $spent;

    return [
        'salary' => $salary,
        'extra_incomes' => $extraIncomes,
        'total_income' => $totalIncome,
        'costs' => $costs,
        'expenses' => $expenses,
        'spent' => $spent,
        'remaining' => $remaining,
        'costs_percent_of_total_income' => $totalIncome > 0 ? ($costs / $totalIncome) * 100 : 0,
        'expenses_percent_of_total_income' => $totalIncome > 0 ? ($expenses / $totalIncome) * 100 : 0,
    ];
}

function getMonthlySpending(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT
            DATE_FORMAT(transaction_date, "%Y-%m") AS month_key,
            COALESCE(SUM(CASE WHEN type = "cost" THEN amount ELSE 0 END), 0) AS total_costs,
            COALESCE(SUM(CASE WHEN type = "expense" THEN amount ELSE 0 END), 0) AS total_expenses
         FROM transactions
         WHERE user_id = :user_id
         GROUP BY DATE_FORMAT(transaction_date, "%Y-%m")
         ORDER BY month_key DESC'
    );
    $stmt->execute(['user_id' => $userId]);
    $expenseRows = $stmt->fetchAll() ?: [];

    $incomeStmt = db()->prepare(
        'SELECT
            DATE_FORMAT(income_date, "%Y-%m") AS month_key,
            COALESCE(SUM(amount), 0) AS total_extra_incomes
         FROM extra_incomes
         WHERE user_id = :user_id
         GROUP BY DATE_FORMAT(income_date, "%Y-%m")
         ORDER BY month_key DESC'
    );
    $incomeStmt->execute(['user_id' => $userId]);
    $incomeRows = $incomeStmt->fetchAll() ?: [];

    $byMonth = [];
    foreach ($expenseRows as $row) {
        $month = (string) $row['month_key'];
        $costs = (float) $row['total_costs'];
        $expenses = (float) $row['total_expenses'];
        $byMonth[$month] = [
            'month_key' => $month,
            'total_costs' => $costs,
            'total_expenses' => $expenses,
            'total_extra_incomes' => 0.0,
        ];
    }

    foreach ($incomeRows as $row) {
        $month = (string) $row['month_key'];
        if (!isset($byMonth[$month])) {
            $byMonth[$month] = [
                'month_key' => $month,
                'total_costs' => 0.0,
                'total_expenses' => 0.0,
                'total_extra_incomes' => 0.0,
            ];
        }
        $byMonth[$month]['total_extra_incomes'] = (float) $row['total_extra_incomes'];
    }

    if (empty($byMonth)) {
        return [];
    }

    krsort($byMonth);
    $byMonth = array_slice($byMonth, 0, 24, true);

    $salary = getSalary($userId);
    foreach ($byMonth as &$row) {
        $totalIncome = $salary + (float) $row['total_extra_incomes'];
        $totalSpent = (float) $row['total_costs'] + (float) $row['total_expenses'];
        $row['salary_reference'] = $salary;
        $row['total_income'] = $totalIncome;
        $row['total_spent'] = $totalSpent;
        $row['monthly_balance'] = $totalIncome - $totalSpent;
        $row['percent_of_total_income'] = $totalIncome > 0 ? ($totalSpent / $totalIncome) * 100 : 0;
    }
    unset($row);

    return array_values($byMonth);
}

function addExtraIncome(int $userId, string $description, float $amount, string $date): array
{
    if ($description === '' || $amount <= 0 || $date === '') {
        return ['ok' => false, 'message' => 'Preencha descricao, valor e data da receita extra.'];
    }

    $stmt = db()->prepare(
        'INSERT INTO extra_incomes (user_id, description, amount, income_date)
         VALUES (:user_id, :description, :amount, :income_date)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'description' => $description,
        'amount' => $amount,
        'income_date' => $date,
    ]);

    return ['ok' => true, 'message' => 'Receita extra adicionada com sucesso.'];
}

function updateExtraIncome(int $userId, int $incomeId, string $description, float $amount, string $date): array
{
    if ($description === '' || $amount <= 0 || $date === '') {
        return ['ok' => false, 'message' => 'Preencha descricao, valor e data da receita extra.'];
    }

    $stmt = db()->prepare(
        'UPDATE extra_incomes
         SET description = :description, amount = :amount, income_date = :income_date
         WHERE id = :id AND user_id = :user_id'
    );
    $stmt->execute([
        'description' => $description,
        'amount' => $amount,
        'income_date' => $date,
        'id' => $incomeId,
        'user_id' => $userId,
    ]);

    return ['ok' => true, 'message' => 'Receita extra atualizada com sucesso.'];
}

function getExtraIncomes(int $userId, int $limit = 100, int $offset = 0): array
{
    $limit = max(1, min($limit, 300));
    $offset = max(0, $offset);
    $sql = 'SELECT id, description, amount, income_date
            FROM extra_incomes
            WHERE user_id = :user_id
            ORDER BY income_date DESC, id DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll() ?: [];
}

function countExtraIncomes(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM extra_incomes WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    return (int) $stmt->fetchColumn();
}

function deleteExtraIncome(int $userId, int $incomeId): void
{
    $stmt = db()->prepare('DELETE FROM extra_incomes WHERE id = :id AND user_id = :user_id');
    $stmt->execute([
        'id' => $incomeId,
        'user_id' => $userId,
    ]);
}

function getTransactionById(int $userId, int $transactionId): ?array
{
    $stmt = db()->prepare(
        'SELECT t.id, t.type, t.description, t.amount, t.transaction_date, t.category_id, c.name AS category_name
         FROM transactions t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.id = :id AND t.user_id = :user_id LIMIT 1'
    );
    $stmt->execute(['id' => $transactionId, 'user_id' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getExtraIncomeById(int $userId, int $incomeId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, description, amount, income_date
         FROM extra_incomes
         WHERE id = :id AND user_id = :user_id LIMIT 1'
    );
    $stmt->execute(['id' => $incomeId, 'user_id' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function upsertMonthlyGoal(int $userId, string $monthKey, float $targetAmount): array
{
    if (preg_match('/^\d{4}-\d{2}$/', $monthKey) !== 1) {
        return ['ok' => false, 'message' => 'Mes invalido para meta mensal.'];
    }
    if ($targetAmount < 0) {
        return ['ok' => false, 'message' => 'Valor da meta mensal invalido.'];
    }
    $stmt = db()->prepare(
        'INSERT INTO monthly_goals (user_id, month_key, target_amount)
         VALUES (:user_id, :month_key, :target_amount)
         ON DUPLICATE KEY UPDATE target_amount = VALUES(target_amount)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'month_key' => $monthKey,
        'target_amount' => $targetAmount,
    ]);
    return ['ok' => true, 'message' => 'Meta mensal salva com sucesso.'];
}

function getMonthlyGoal(int $userId, string $monthKey): float
{
    if (preg_match('/^\d{4}-\d{2}$/', $monthKey) !== 1) {
        return 0.0;
    }
    $stmt = db()->prepare('SELECT target_amount FROM monthly_goals WHERE user_id = :user_id AND month_key = :month_key LIMIT 1');
    $stmt->execute(['user_id' => $userId, 'month_key' => $monthKey]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (float) $val : 0.0;
}

function saveCategoryBudget(int $userId, string $monthKey, int $categoryId, float $budgetAmount): array
{
    if (preg_match('/^\d{4}-\d{2}$/', $monthKey) !== 1) {
        return ['ok' => false, 'message' => 'Mes invalido para orçamento por categoria.'];
    }
    if ($budgetAmount < 0 || $categoryId <= 0) {
        return ['ok' => false, 'message' => 'Dados invalidos para orçamento por categoria.'];
    }
    $stmt = db()->prepare(
        'INSERT INTO category_budgets (user_id, category_id, month_key, budget_amount)
         VALUES (:user_id, :category_id, :month_key, :budget_amount)
         ON DUPLICATE KEY UPDATE budget_amount = VALUES(budget_amount)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'category_id' => $categoryId,
        'month_key' => $monthKey,
        'budget_amount' => $budgetAmount,
    ]);
    return ['ok' => true, 'message' => 'Orçamento por categoria salvo com sucesso.'];
}

function getCategoryBudgetsByMonth(int $userId, string $monthKey): array
{
    if (preg_match('/^\d{4}-\d{2}$/', $monthKey) !== 1) {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT b.category_id, c.name AS category_name, b.budget_amount
         FROM category_budgets b
         INNER JOIN categories c ON c.id = b.category_id
         WHERE b.user_id = :user_id AND b.month_key = :month_key
         ORDER BY c.name ASC'
    );
    $stmt->execute(['user_id' => $userId, 'month_key' => $monthKey]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Orçado vs real por categoria no mês (custos + gastos com categoria).
 * Retorna linhas com budget, spent, percentual do orçamento e status visual (ok|warn|over|none).
 */
function getBudgetVsActualByCategory(int $userId, string $monthKey): array
{
    if (preg_match('/^\d{4}-\d{2}$/', $monthKey) !== 1) {
        return [];
    }

    $stmt = db()->prepare(
        'SELECT t.category_id, c.name AS category_name, COALESCE(SUM(t.amount), 0) AS spent
         FROM transactions t
         INNER JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = :user_id
           AND DATE_FORMAT(t.transaction_date, "%Y-%m") = :month_key
         GROUP BY t.category_id, c.name'
    );
    $stmt->execute(['user_id' => $userId, 'month_key' => $monthKey]);
    $spentRows = $stmt->fetchAll() ?: [];

    $spentByCat = [];
    foreach ($spentRows as $r) {
        $cid = (int) $r['category_id'];
        $spentByCat[$cid] = [
            'category_id' => $cid,
            'category_name' => (string) $r['category_name'],
            'spent' => (float) $r['spent'],
        ];
    }

    $budgets = getCategoryBudgetsByMonth($userId, $monthKey);
    $merged = [];

    foreach ($budgets as $b) {
        $cid = (int) $b['category_id'];
        $budget = (float) $b['budget_amount'];
        $spent = $spentByCat[$cid]['spent'] ?? 0.0;
        $merged[$cid] = [
            'category_id' => $cid,
            'category_name' => (string) $b['category_name'],
            'budget' => $budget,
            'spent' => $spent,
        ];
    }

    foreach ($spentByCat as $cid => $data) {
        if (!isset($merged[$cid])) {
            $merged[$cid] = [
                'category_id' => $cid,
                'category_name' => $data['category_name'],
                'budget' => 0.0,
                'spent' => $data['spent'],
            ];
        }
    }

    $out = [];
    foreach ($merged as $row) {
        $budget = $row['budget'];
        $spent = $row['spent'];
        $pct = 0.0;
        $status = 'none';
        $barWidth = 0.0;

        if ($budget > 0) {
            $pct = ($spent / $budget) * 100;
            if ($pct <= 85) {
                $status = 'ok';
            } elseif ($pct <= 100) {
                $status = 'warn';
            } else {
                $status = 'over';
            }
            $barWidth = min(100.0, $pct);
        } elseif ($spent > 0) {
            $status = 'none';
            $barWidth = 0.0;
        }

        $row['percent_of_budget'] = $pct;
        $row['status'] = $status;
        $row['bar_width'] = $barWidth;
        $out[] = $row;
    }

    usort($out, static function (array $a, array $b): int {
        return strcmp((string) $a['category_name'], (string) $b['category_name']);
    });

    return $out;
}
