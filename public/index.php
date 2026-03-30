<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/finance.php';

startSessionIfNeeded();

$page = $_GET['page'] ?? (isLoggedIn() ? 'dashboard' : 'login');
$tab = $_GET['tab'] ?? 'dashboard';
$allowedTabs = ['dashboard', 'monthly'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'dashboard';
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$transactionsPerPage = 20;
$extraIncomesPerPage = 20;
$transactionsPage = max(1, (int) ($_GET['tp'] ?? 1));
$incomesPage = max(1, (int) ($_GET['ip'] ?? 1));
$filterMonth = (string) ($_GET['month'] ?? '');
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$selectedMonth = (string) ($_GET['selected_month'] ?? date('Y-m'));
$editTransactionId = max(0, (int) ($_GET['edit_tx'] ?? 0));
$editIncomeId = max(0, (int) ($_GET['edit_income'] ?? 0));
$transactionOffset = ($transactionsPage - 1) * $transactionsPerPage;
$incomeOffset = ($incomesPage - 1) * $extraIncomesPerPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $returnTab = (string) ($_POST['return_tab'] ?? 'dashboard');
    if (!in_array($returnTab, $allowedTabs, true)) {
        $returnTab = 'dashboard';
    }
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash'] = ['ok' => false, 'message' => 'Sessao expirada. Atualize a pagina e tente novamente.'];
        header('Location: /?tab=' . urlencode($returnTab));
        exit;
    }

    if ($action === 'register') {
        $result = registerUser(
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['password'] ?? '')
        );
        $_SESSION['flash'] = $result;
        header('Location: /');
        exit;
    }

    if ($action === 'login') {
        $result = loginUser(
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['password'] ?? '')
        );
        $_SESSION['flash'] = $result;
        header('Location: /');
        exit;
    }

    if ($action === 'logout') {
        logoutUser();
        $_SESSION['flash'] = ['ok' => true, 'message' => 'Sessao encerrada.'];
        header('Location: /?page=login');
        exit;
    }

    if (!isLoggedIn()) {
        header('Location: /?page=login');
        exit;
    }

    $userId = (int) currentUserId();

    if ($action === 'save_salary') {
        $salary = (float) str_replace(',', '.', (string) ($_POST['monthly_salary'] ?? '0'));
        if ($salary < 0) {
            $_SESSION['flash'] = ['ok' => false, 'message' => 'Salario invalido.'];
        } else {
            updateSalary($userId, $salary);
            $_SESSION['flash'] = ['ok' => true, 'message' => 'Salario atualizado com sucesso.'];
        }
        header('Location: /?tab=' . urlencode($returnTab));
        exit;
    }

    if ($action === 'add_transaction') {
        $type = (string) ($_POST['type'] ?? '');
        $description = trim((string) ($_POST['description'] ?? ''));
        $amount = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
        $date = (string) ($_POST['transaction_date'] ?? '');
        $categoryName = trim((string) ($_POST['category_name'] ?? ''));
        $categoryId = resolveCategoryId($userId, $categoryName);
        $result = addTransaction($userId, $type, $description, $amount, $date, $categoryId);
        $_SESSION['flash'] = $result;
        header('Location: /?tab=' . urlencode($returnTab));
        exit;
    }

    if ($action === 'add_extra_income') {
        $description = trim((string) ($_POST['description'] ?? ''));
        $amount = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
        $date = (string) ($_POST['income_date'] ?? '');
        $result = addExtraIncome($userId, $description, $amount, $date);
        $_SESSION['flash'] = $result;
        header('Location: /?tab=' . urlencode($returnTab));
        exit;
    }

    if ($action === 'update_transaction') {
        $transactionId = (int) ($_POST['transaction_id'] ?? 0);
        $type = (string) ($_POST['type'] ?? '');
        $description = trim((string) ($_POST['description'] ?? ''));
        $amount = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
        $date = (string) ($_POST['transaction_date'] ?? '');
        $categoryName = trim((string) ($_POST['category_name'] ?? ''));
        $categoryId = resolveCategoryId($userId, $categoryName);
        $result = updateTransaction($userId, $transactionId, $type, $description, $amount, $date, $categoryId);
        $_SESSION['flash'] = $result;
        header('Location: /?tab=' . urlencode($returnTab));
        exit;
    }

    if ($action === 'update_extra_income') {
        $incomeId = (int) ($_POST['income_id'] ?? 0);
        $description = trim((string) ($_POST['description'] ?? ''));
        $amount = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
        $date = (string) ($_POST['income_date'] ?? '');
        $result = updateExtraIncome($userId, $incomeId, $description, $amount, $date);
        $_SESSION['flash'] = $result;
        header('Location: /?tab=' . urlencode($returnTab));
        exit;
    }

    if ($action === 'save_monthly_goal') {
        $monthKey = (string) ($_POST['month_key'] ?? date('Y-m'));
        $targetAmount = (float) str_replace(',', '.', (string) ($_POST['target_amount'] ?? '0'));
        $result = upsertMonthlyGoal($userId, $monthKey, $targetAmount);
        $_SESSION['flash'] = $result;
        header('Location: /?tab=' . urlencode($returnTab) . '&selected_month=' . urlencode($monthKey));
        exit;
    }

    if ($action === 'save_category_budget') {
        $monthKey = (string) ($_POST['month_key'] ?? date('Y-m'));
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $budgetAmount = (float) str_replace(',', '.', (string) ($_POST['budget_amount'] ?? '0'));
        $result = saveCategoryBudget($userId, $monthKey, $categoryId, $budgetAmount);
        $_SESSION['flash'] = $result;
        header('Location: /?tab=' . urlencode($returnTab) . '&selected_month=' . urlencode($monthKey));
        exit;
    }

    if ($action === 'add_recurring_entry') {
        $type = (string) ($_POST['type'] ?? '');
        $description = trim((string) ($_POST['description'] ?? ''));
        $amount = (float) str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
        $dayOfMonth = (int) ($_POST['day_of_month'] ?? 1);
        $categoryName = trim((string) ($_POST['category_name'] ?? ''));
        $categoryId = resolveCategoryId($userId, $categoryName);
        $result = addRecurringEntry($userId, $type, $description, $amount, $dayOfMonth, $categoryId);
        $_SESSION['flash'] = $result;
        header('Location: /?tab=' . urlencode($returnTab));
        exit;
    }

    if ($action === 'delete_transaction') {
        $transactionId = (int) ($_POST['transaction_id'] ?? 0);
        if ($transactionId > 0) {
            deleteTransaction($userId, $transactionId);
            $_SESSION['flash'] = ['ok' => true, 'message' => 'Lancamento removido.'];
        }
        header('Location: /?tab=' . urlencode($returnTab));
        exit;
    }

    if ($action === 'delete_extra_income') {
        $incomeId = (int) ($_POST['income_id'] ?? 0);
        if ($incomeId > 0) {
            deleteExtraIncome($userId, $incomeId);
            $_SESSION['flash'] = ['ok' => true, 'message' => 'Receita extra removida.'];
        }
        header('Location: /?tab=' . urlencode($returnTab));
        exit;
    }

    if ($action === 'delete_recurring_entry') {
        $recurringId = (int) ($_POST['recurring_id'] ?? 0);
        if ($recurringId > 0) {
            deleteRecurringEntry($userId, $recurringId);
            $_SESSION['flash'] = ['ok' => true, 'message' => 'Conta recorrente removida.'];
        }
        header('Location: /?tab=' . urlencode($returnTab));
        exit;
    }

    if ($action === 'toggle_recurring_entry') {
        $recurringId = (int) ($_POST['recurring_id'] ?? 0);
        $active = (int) ($_POST['active'] ?? 0) === 1;
        if ($recurringId > 0) {
            setRecurringEntryStatus($userId, $recurringId, $active);
            $_SESSION['flash'] = ['ok' => true, 'message' => $active ? 'Recorrencia ativada.' : 'Recorrencia pausada.'];
        }
        header('Location: /?tab=' . urlencode($returnTab));
        exit;
    }
}

$isAuth = isLoggedIn();
$totals = [];
$transactions = [];
$monthlySpending = [];
$extraIncomes = [];
$categories = [];
$transactionsTotal = 0;
$incomesTotal = 0;
$recurringEntries = [];
$selectedTransaction = null;
$selectedIncome = null;
$currentGoal = 0.0;
$budgetVsActual = [];

if ($isAuth) {
    $userId = (int) currentUserId();
    createDefaultCategories($userId);
    processRecurringEntries($userId);
    $categories = getCategories($userId);
    $totals = getTotals($userId);
    $transactions = getTransactions(
        $userId,
        $transactionsPerPage,
        $transactionOffset,
        $filterMonth !== '' ? $filterMonth : null,
        $searchQuery !== '' ? $searchQuery : null
    );
    $monthlySpending = getMonthlySpending($userId);
    $extraIncomes = getExtraIncomes($userId, $extraIncomesPerPage, $incomeOffset);
    $transactionsTotal = countTransactions($userId, $filterMonth !== '' ? $filterMonth : null, $searchQuery !== '' ? $searchQuery : null);
    $incomesTotal = countExtraIncomes($userId);
    $selectedTransaction = $editTransactionId > 0 ? getTransactionById($userId, $editTransactionId) : null;
    $selectedIncome = $editIncomeId > 0 ? getExtraIncomeById($userId, $editIncomeId) : null;
    $currentGoal = getMonthlyGoal($userId, $selectedMonth);
    $budgetVsActual = getBudgetVsActualByCategory($userId, $selectedMonth);
    $recurringEntries = getRecurringEntries($userId);

    $exportType = (string) ($_GET['export'] ?? '');
    if ($exportType === 'monthly') {
        $filename = 'granaflow-gastos-por-mes-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'wb');
        if ($output !== false) {
            // BOM UTF-8 para abrir acentuacao corretamente no Excel.
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Mes', 'Receitas extras', 'Custos', 'Gastos', 'Total gasto', 'Saldo do mes', '% da receita total'], ';');

            foreach ($monthlySpending as $row) {
                fputcsv($output, [
                    date('m/Y', strtotime((string) $row['month_key'] . '-01')),
                    number_format((float) $row['total_extra_incomes'], 2, ',', '.'),
                    number_format((float) $row['total_costs'], 2, ',', '.'),
                    number_format((float) $row['total_expenses'], 2, ',', '.'),
                    number_format((float) $row['total_spent'], 2, ',', '.'),
                    number_format((float) $row['monthly_balance'], 2, ',', '.'),
                    number_format((float) $row['percent_of_total_income'], 2, ',', '.') . '%',
                ], ';');
            }

            fclose($output);
        }
        exit;
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GranaFlow</title>
    <?php if ($isAuth): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/css/app.css">

</head>
<body>
    <div class="container">
        <div class="header-actions">
            <div>
                <span class="brand-badge">Finance Control</span>
                <h1>GranaFlow</h1>
                <p class="hero-copy">Controle financeiro mensal com visual premium para acompanhar salario, custos, gastos e saldo em tempo real.</p>
            </div>
            <?php if ($isAuth): ?>
                <form method="post" style="width:auto;">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
                    <button class="secondary" style="width:auto;">Sair</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($flash): ?>
            <div class="flash <?= $flash['ok'] ? 'ok' : 'error' ?>">
                <?= htmlspecialchars((string) $flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($isAuth): ?>
            <nav class="tabs" aria-label="Abas do dashboard">
                <a class="tab-link <?= $tab === 'dashboard' ? 'active' : '' ?>" href="/?tab=dashboard">Visao geral</a>
                <a class="tab-link <?= $tab === 'monthly' ? 'active' : '' ?>" href="/?tab=monthly">Gastos por Mês</a>
            </nav>
            <?php if ($tab === 'dashboard'): ?>
                <div class="quick-actions">
                    <a class="quick-chip" href="#form-lancamento">+ Lancamento</a>
                    <a class="quick-chip" href="#form-receita">+ Receita extra</a>
                    <a class="quick-chip" href="#form-recorrencia">+ Recorrente</a>
                    <a class="quick-chip" href="#historico-lancamentos">Historico</a>
                </div>
            <?php endif; ?>
            <p class="quick-help">Dica: salve seu salario, adicione receitas extras quando houver, e lance custos/gastos para acompanhar seu saldo real.</p>
        <?php endif; ?>

        <?php if (!$isAuth && $page === 'login'): ?>
            <div class="grid grid-2 auth-shell">
                <section class="card">
                    <h2 class="section-title">Entrar</h2>
                    <p class="section-subtitle">Acesse sua conta para acompanhar seu mes financeiro.</p>
                    <form method="post">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                        <label>E-mail</label>
                        <input type="email" name="email" placeholder="voce@exemplo.com" autocomplete="email" required>
                        <label class="mt-12">Senha</label>
                        <input type="password" name="password" placeholder="Digite sua senha" autocomplete="current-password" required>
                        <button class="mt-16">Acessar</button>
                    </form>
                </section>
                <section class="card">
                    <h2 class="section-title">Criar conta</h2>
                    <p class="section-subtitle">Crie sua conta e comece a organizar suas financas.</p>
                    <form method="post">
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                        <label>Nome</label>
                        <input type="text" name="name" placeholder="Seu nome completo" autocomplete="name" required>
                        <label class="mt-12">E-mail</label>
                        <input type="email" name="email" placeholder="voce@exemplo.com" autocomplete="email" required>
                        <label class="mt-12">Senha</label>
                        <input type="password" name="password" placeholder="Minimo 6 caracteres" autocomplete="new-password" required minlength="6">
                        <button class="mt-16">Cadastrar</button>
                    </form>
                </section>
            </div>
        <?php else: ?>
            <?php requireAuth(); ?>
            <?php if ($tab === 'monthly'): ?>
                <div class="grid">
                    <section class="card">
                        <h2>Gastos por Mês</h2>
                        <p class="section-subtitle">Acompanhe a evolucao mensal dos seus custos e gastos.</p>
                        <canvas id="monthlyChart" height="120"></canvas>
                    </section>
                    <section class="card">
                        <h3>Resumo mensal</h3>
                        <p class="section-subtitle">Consolidado por Mês com base na receita total (salario + receitas extras).</p>
                        <p style="margin-bottom: 14px;">
                            <a class="tab-link active" href="/?tab=monthly&export=monthly" style="display:inline-block;">Exportar em Excel (.csv)</a>
                        </p>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>Mês</th>
                                    <th>Receitas extras</th>
                                    <th>Custos</th>
                                    <th>Gastos</th>
                                    <th>Total</th>
                                    <th>Saldo do Mês</th>
                                    <th>% da receita total</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($monthlySpending)): ?>
                                    <tr><td colspan="7"><div class="empty-state">Sem dados mensais ainda. Lance movimentacoes para visualizar.</div></td></tr>
                                <?php else: ?>
                                    <?php foreach ($monthlySpending as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) date('m/Y', strtotime((string) $row['month_key'] . '-01'))) ?></td>
                                            <td>R$ <?= number_format((float) $row['total_extra_incomes'], 2, ',', '.') ?></td>
                                            <td>R$ <?= number_format((float) $row['total_costs'], 2, ',', '.') ?></td>
                                            <td>R$ <?= number_format((float) $row['total_expenses'], 2, ',', '.') ?></td>
                                            <td>R$ <?= number_format((float) $row['total_spent'], 2, ',', '.') ?></td>
                                            <td>R$ <?= number_format((float) $row['monthly_balance'], 2, ',', '.') ?></td>
                                            <td><?= number_format((float) $row['percent_of_total_income'], 2, ',', '.') ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            <?php else: ?>
            <div class="grid">
                <section class="grid grid-2">
                    <article class="card" id="form-recorrencia">
                        <h3 class="section-title">Contas recorrentes automáticas</h3>
                        <p class="section-subtitle">Configure contas que devem ser lancadas automaticamente todo Mês.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="add_recurring_entry">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
                            <label>Tipo</label>
                            <select name="type" required>
                                <option value="cost">Custo fixo</option>
                                <option value="expense">Gasto</option>
                            </select>
                            <label class="mt-12">Categoria</label>
                            <input type="text" name="category_name" list="category-options" placeholder="Ex.: Moradia, Internet">
                            <label class="mt-12">Descricao</label>
                            <input type="text" name="description" placeholder="Ex.: Aluguel, Internet, Netflix" required>
                            <div class="row mt-12">
                                <div>
                                    <label>Valor</label>
                                    <input type="number" step="0.01" min="0.01" name="amount" required>
                                </div>
                                <div>
                                    <label>Dia do Mês</label>
                                    <input type="number" min="1" max="31" name="day_of_month" value="<?= date('d') ?>" required>
                                </div>
                            </div>
                            <button class="mt-16">Salvar recorrencia</button>
                        </form>
                    </article>
                    <article class="card" id="form-lancamento">
                        <h3>Recorrencias cadastradas</h3>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>Descricao</th>
                                    <th>Tipo</th>
                                    <th>Dia</th>
                                    <th>Valor</th>
                                    <th>Status</th>
                                    <th>Acoes</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($recurringEntries)): ?>
                                    <tr><td colspan="6"><div class="empty-state">Nenhuma recorrencia cadastrada.</div></td></tr>
                                <?php else: ?>
                                    <?php foreach ($recurringEntries as $entry): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) $entry['description']) ?></td>
                                            <td><?= $entry['type'] === 'cost' ? 'Custo fixo' : 'Gasto' ?></td>
                                            <td><?= (int) $entry['day_of_month'] ?></td>
                                            <td>R$ <?= number_format((float) $entry['amount'], 2, ',', '.') ?></td>
                                            <td><?= (int) $entry['is_active'] === 1 ? 'Ativa' : 'Pausada' ?></td>
                                            <td>
                                                <div style="display:flex; gap:8px; align-items:center;">
                                                    <form method="post" style="width:auto;">
                                                        <input type="hidden" name="action" value="toggle_recurring_entry">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                        <input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
                                                        <input type="hidden" name="recurring_id" value="<?= (int) $entry['id'] ?>">
                                                        <input type="hidden" name="active" value="<?= (int) $entry['is_active'] === 1 ? '0' : '1' ?>">
                                                        <button class="secondary" style="width:auto;"><?= (int) $entry['is_active'] === 1 ? 'Pausar' : 'Ativar' ?></button>
                                                    </form>
                                                    <form method="post" style="width:auto;" onsubmit="return confirm('Deseja remover esta recorrencia?');">
                                                        <input type="hidden" name="action" value="delete_recurring_entry">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                        <input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
                                                        <input type="hidden" name="recurring_id" value="<?= (int) $entry['id'] ?>">
                                                        <button class="btn-danger" style="width:auto;">Excluir</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                </section>

                <section class="grid grid-2">
                    <article class="card" id="form-receita">
                        <h3 class="section-title">Meta mensal</h3>
                        <p class="section-subtitle">Defina quanto deseja guardar no Mês selecionado.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="save_monthly_goal">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
                            <label>Mês de referencia</label>
                            <input type="month" name="month_key" value="<?= htmlspecialchars($selectedMonth) ?>" required>
                            <label class="mt-12">Meta de economia (R$)</label>
                            <input type="number" step="0.01" min="0" name="target_amount" value="<?= htmlspecialchars((string) $currentGoal) ?>" required>
                            <button class="mt-16">Salvar meta</button>
                        </form>
                    </article>
                    <article class="card">
                        <h3 class="section-title">Orcamento por categoria</h3>
                        <p class="section-subtitle">Defina limite por categoria para o Mês selecionado.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="save_category_budget">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
                            <label>Mês de referencia</label>
                            <input type="month" name="month_key" value="<?= htmlspecialchars($selectedMonth) ?>" required>
                            <label class="mt-12">Categoria</label>
                            <select name="category_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int) $category['id'] ?>"><?= htmlspecialchars((string) $category['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="mt-12">Valor do orcamento (R$)</label>
                            <input type="number" step="0.01" min="0" name="budget_amount" required>
                            <button class="mt-16">Salvar orcamento</button>
                        </form>
                    </article>
                </section>

                <section class="card" id="historico-lancamentos">
                    <h2>Resumo do Mês</h2>
                    <div class="summary">
                        <div class="metric">Salario<strong>R$ <?= number_format((float) $totals['salary'], 2, ',', '.') ?></strong></div>
                        <div class="metric">Receitas extras<strong>R$ <?= number_format((float) $totals['extra_incomes'], 2, ',', '.') ?></strong></div>
                        <div class="metric">Receita total<strong>R$ <?= number_format((float) $totals['total_income'], 2, ',', '.') ?></strong></div>
                        <div class="metric">Custos<strong>R$ <?= number_format((float) $totals['costs'], 2, ',', '.') ?></strong></div>
                        <div class="metric">Gastos<strong>R$ <?= number_format((float) $totals['expenses'], 2, ',', '.') ?></strong></div>
                        <div class="metric">Total gasto<strong>R$ <?= number_format((float) $totals['spent'], 2, ',', '.') ?></strong></div>
                        <div class="metric">Saldo<strong>R$ <?= number_format((float) $totals['remaining'], 2, ',', '.') ?></strong></div>
                    </div>
                </section>

                <section class="grid grid-2">
                    <article class="card">
                        <h3 class="section-title">Atualizar salario mensal</h3>
                        <p class="section-subtitle">Informe quanto voce recebe por mes.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="save_salary">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
                            <label>Salario (R$)</label>
                            <input type="number" step="0.01" min="0" name="monthly_salary" value="<?= htmlspecialchars((string) $totals['salary']) ?>" placeholder="Ex.: 3500.00" required>
                            <button class="mt-16">Salvar salario</button>
                        </form>
                    </article>
                    <article class="card">
                        <h3 class="section-title"><?= $selectedTransaction ? 'Editar lancamento' : 'Novo lancamento' ?></h3>
                        <p class="section-subtitle">Registre custos fixos e gastos pontuais do dia a dia.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="<?= $selectedTransaction ? 'update_transaction' : 'add_transaction' ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
                            <?php if ($selectedTransaction): ?>
                                <input type="hidden" name="transaction_id" value="<?= (int) $selectedTransaction['id'] ?>">
                            <?php endif; ?>
                            <label>Tipo</label>
                            <select name="type" required>
                                <option value="cost" <?= $selectedTransaction && $selectedTransaction['type'] === 'cost' ? 'selected' : '' ?>>Custo fixo</option>
                                <option value="expense" <?= $selectedTransaction && $selectedTransaction['type'] === 'expense' ? 'selected' : '' ?>>Gasto</option>
                            </select>
                            <label class="mt-12">Categoria</label>
                            <input type="text" name="category_name" list="category-options" value="<?= htmlspecialchars((string) ($selectedTransaction['category_name'] ?? '')) ?>" placeholder="Digite ou selecione uma categoria">
                            <datalist id="category-options">
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= htmlspecialchars((string) $category['name']) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <label class="mt-12">Descricao</label>
                            <input type="text" name="description" value="<?= htmlspecialchars((string) ($selectedTransaction['description'] ?? '')) ?>" placeholder="Ex.: Aluguel, mercado, internet..." required>
                            <div class="row mt-12">
                                <div>
                                    <label>Valor</label>
                                    <input type="number" step="0.01" min="0.01" name="amount" value="<?= htmlspecialchars((string) ($selectedTransaction['amount'] ?? '')) ?>" placeholder="Ex.: 120.50" required>
                                </div>
                                <div>
                                    <label>Data</label>
                                    <input type="date" name="transaction_date" value="<?= htmlspecialchars((string) ($selectedTransaction['transaction_date'] ?? date('Y-m-d'))) ?>" required>
                                </div>
                            </div>
                            <button class="mt-16"><?= $selectedTransaction ? 'Salvar alteracoes' : 'Adicionar' ?></button>
                            <?php if ($selectedTransaction): ?>
                                <p class="mt-12"><a class="tab-link" href="/?tab=dashboard">Cancelar edicao</a></p>
                            <?php endif; ?>
                        </form>
                    </article>
                </section>

                <section class="grid grid-2">
                    <article class="card">
                        <h3 class="section-title"><?= $selectedIncome ? 'Editar receita extra' : 'Adicionar receita extra' ?></h3>
                        <p class="section-subtitle">Registre entradas adicionais como freela, comissao ou renda extra.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="<?= $selectedIncome ? 'update_extra_income' : 'add_extra_income' ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                            <input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
                            <?php if ($selectedIncome): ?>
                                <input type="hidden" name="income_id" value="<?= (int) $selectedIncome['id'] ?>">
                            <?php endif; ?>
                            <label>Descricao</label>
                            <input type="text" name="description" value="<?= htmlspecialchars((string) ($selectedIncome['description'] ?? '')) ?>" placeholder="Ex.: Freela, bonus, venda..." required>
                            <div class="row mt-12">
                                <div>
                                    <label>Valor</label>
                                    <input type="number" step="0.01" min="0.01" name="amount" value="<?= htmlspecialchars((string) ($selectedIncome['amount'] ?? '')) ?>" placeholder="Ex.: 850.00" required>
                                </div>
                                <div>
                                    <label>Data</label>
                                    <input type="date" name="income_date" value="<?= htmlspecialchars((string) ($selectedIncome['income_date'] ?? date('Y-m-d'))) ?>" required>
                                </div>
                            </div>
                            <button class="mt-16"><?= $selectedIncome ? 'Salvar alteracoes' : 'Adicionar receita' ?></button>
                            <?php if ($selectedIncome): ?>
                                <p class="mt-12"><a class="tab-link" href="/?tab=dashboard">Cancelar edicao</a></p>
                            <?php endif; ?>
                        </form>
                    </article>
                    <article class="card">
                        <h3>Historico de receitas extras</h3>
                        <p class="section-subtitle">Exibindo as 20 receitas extras mais recentes.</p>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Descricao</th>
                                    <th>Valor</th>
                                    <th>Acoes</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($extraIncomes)): ?>
                                    <tr><td colspan="4"><div class="empty-state">Nenhuma receita extra cadastrada ainda.</div></td></tr>
                                <?php else: ?>
                                    <?php foreach ($extraIncomes as $income): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) $income['income_date']) ?></td>
                                            <td><?= htmlspecialchars((string) $income['description']) ?></td>
                                            <td>R$ <?= number_format((float) $income['amount'], 2, ',', '.') ?></td>
                                            <td>
                                                <div style="display:flex; gap:8px; align-items:center;">
                                                    <a class="tab-link" href="/?tab=dashboard&edit_income=<?= (int) $income['id'] ?>&tp=<?= $transactionsPage ?>&ip=<?= $incomesPage ?>&month=<?= urlencode($filterMonth) ?>&q=<?= urlencode($searchQuery) ?>">Editar</a>
                                                    <form method="post" onsubmit="return confirm('Deseja realmente excluir esta receita extra?');" style="width:auto;">
                                                        <input type="hidden" name="action" value="delete_extra_income">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                        <input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
                                                        <input type="hidden" name="income_id" value="<?= (int) $income['id'] ?>">
                                                        <button class="btn-danger" style="width:auto;">Excluir</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php $incomesPages = max(1, (int) ceil($incomesTotal / $extraIncomesPerPage)); ?>
                        <?php if ($incomesPages > 1): ?>
                            <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap;">
                                <?php if ($incomesPage > 1): ?>
                                    <a class="tab-link" href="/?tab=dashboard&ip=<?= $incomesPage - 1 ?>&month=<?= urlencode($filterMonth) ?>&q=<?= urlencode($searchQuery) ?>">Anterior</a>
                                <?php endif; ?>
                                <span class="tab-link active">Pagina <?= $incomesPage ?> de <?= $incomesPages ?></span>
                                <?php if ($incomesPage < $incomesPages): ?>
                                    <a class="tab-link" href="/?tab=dashboard&ip=<?= $incomesPage + 1 ?>&month=<?= urlencode($filterMonth) ?>&q=<?= urlencode($searchQuery) ?>">Proxima</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                </section>

                <section class="grid grid-2">
                    <article class="card">
                        <h3>Grafico de pizza (base receita total)</h3>
                        <canvas id="salaryPieChart" width="400" height="260"></canvas>
                        <p class="chart-caption">O grafico mostra a divisao da sua receita total (salario + extras) entre custos, gastos e saldo.</p>
                    </article>
                    <article class="card">
                        <h3>Percentuais</h3>
                        <p>Custos: <strong><?= number_format((float) $totals['costs_percent_of_total_income'], 2, ',', '.') ?>%</strong> da receita total</p>
                        <p>Gastos: <strong><?= number_format((float) $totals['expenses_percent_of_total_income'], 2, ',', '.') ?>%</strong> da receita total</p>
                        <p>Saldo livre: <strong><?= number_format($totals['total_income'] > 0 ? max(0.0, ($totals['remaining'] / $totals['total_income']) * 100) : 0, 2, ',', '.') ?>%</strong> da receita total</p>
                        <p>Meta mensal (<?= htmlspecialchars(date('m/Y', strtotime($selectedMonth . '-01'))) ?>): <strong>R$ <?= number_format($currentGoal, 2, ',', '.') ?></strong></p>
                        <p>Progresso da meta: <strong><?= number_format($currentGoal > 0 ? max(0.0, min(100.0, ($totals['remaining'] / $currentGoal) * 100)) : 0, 2, ',', '.') ?>%</strong></p>
                    </article>
                </section>

                <section class="card">
                    <h3>Orçamento vs real por categoria (<?= htmlspecialchars(date('m/Y', strtotime($selectedMonth . '-01'))) ?>)</h3>
                    <p class="section-subtitle">Barras: verde até 85% do orçamento, amarelo até 100%, vermelho acima do orçamento. Ajuste o Mês de referência nas metas acima.</p>
                    <?php if (empty($budgetVsActual)): ?>
                        <div class="empty-state">Nenhum dado neste Mês: defina orçamentos por categoria ou lance gastos com categoria.</div>
                    <?php else: ?>
                        <div class="budget-v-list">
                            <?php foreach ($budgetVsActual as $row): ?>
                                <?php
                                $b = (float) $row['budget'];
                                $s = (float) $row['spent'];
                                $st = (string) $row['status'];
                                $pct = (float) $row['percent_of_budget'];
                                $bw = (float) $row['bar_width'];
                                $badge = match ($st) {
                                    'ok' => ['label' => 'Dentro do plano', 'class' => 'ok'],
                                    'warn' => ['label' => 'Atenção', 'class' => 'warn'],
                                    'over' => ['label' => 'Acima do orçamento', 'class' => 'over'],
                                    default => ['label' => 'Sem orçamento', 'class' => 'none'],
                                };
                                ?>
                                <div class="budget-v-row">
                                    <div class="budget-v-head">
                                        <strong><?= htmlspecialchars((string) $row['category_name']) ?></strong>
                                        <span class="budget-v-badge <?= htmlspecialchars($badge['class']) ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                    </div>
                                    <div class="budget-v-meta">
                                        Real: <strong>R$ <?= number_format($s, 2, ',', '.') ?></strong>
                                        <?php if ($b > 0): ?>
                                            &nbsp;·&nbsp; Orçamento: <strong>R$ <?= number_format($b, 2, ',', '.') ?></strong>
                                            &nbsp;·&nbsp; <?= number_format($pct, 1, ',', '.') ?>% usado
                                        <?php else: ?>
                                            &nbsp;·&nbsp; Orçamento não definido para esta categoria
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($b > 0): ?>
                                        <div class="budget-v-bar mt-12">
                                            <div class="budget-v-fill status-<?= htmlspecialchars($st) ?>" style="width: <?= min(100, max(0, $bw)) ?>%;"></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="card">
                    <h3>Historico de lancamentos</h3>
                    <p class="section-subtitle">Exibindo os 20 lancamentos mais recentes.</p>
                    <form method="get" class="row" style="margin-bottom: 12px;">
                        <input type="hidden" name="tab" value="dashboard">
                        <div>
                            <label>Filtrar por Mês</label>
                            <input type="month" name="month" value="<?= htmlspecialchars($filterMonth) ?>">
                        </div>
                        <div>
                            <label>Buscar por descricao</label>
                            <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Ex.: mercado">
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <button type="submit">Aplicar filtros</button>
                        </div>
                    </form>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th>Categoria</th>
                                <th>Descricao</th>
                                <th>Valor</th>
                                <th>Acoes</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($transactions)): ?>
                                <tr><td colspan="6"><div class="empty-state">Nenhum lancamento cadastrado ainda. Adicione o primeiro no formulario acima.</div></td></tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) $transaction['transaction_date']) ?></td>
                                        <td>
                                            <span class="badge <?= $transaction['type'] === 'cost' ? 'cost' : 'expense' ?>">
                                                <?= $transaction['type'] === 'cost' ? 'Custo fixo' : 'Gasto' ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($transaction['category_name'] ?? 'Sem categoria')) ?></td>
                                        <td><?= htmlspecialchars((string) $transaction['description']) ?></td>
                                        <td>R$ <?= number_format((float) $transaction['amount'], 2, ',', '.') ?></td>
                                        <td>
                                            <div style="display:flex; gap:8px; align-items:center;">
                                                <a class="tab-link" href="/?tab=dashboard&edit_tx=<?= (int) $transaction['id'] ?>&tp=<?= $transactionsPage ?>&ip=<?= $incomesPage ?>&month=<?= urlencode($filterMonth) ?>&q=<?= urlencode($searchQuery) ?>">Editar</a>
                                                <form method="post" onsubmit="return confirm('Deseja realmente excluir este lancamento?');" style="width:auto;">
                                                    <input type="hidden" name="action" value="delete_transaction">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                                    <input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
                                                    <input type="hidden" name="transaction_id" value="<?= (int) $transaction['id'] ?>">
                                                    <button class="btn-danger" style="width:auto;">Excluir</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php $transactionsPages = max(1, (int) ceil($transactionsTotal / $transactionsPerPage)); ?>
                    <?php if ($transactionsPages > 1): ?>
                        <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap;">
                            <?php if ($transactionsPage > 1): ?>
                                <a class="tab-link" href="/?tab=dashboard&tp=<?= $transactionsPage - 1 ?>&month=<?= urlencode($filterMonth) ?>&q=<?= urlencode($searchQuery) ?>">Anterior</a>
                            <?php endif; ?>
                            <span class="tab-link active">Pagina <?= $transactionsPage ?> de <?= $transactionsPages ?></span>
                            <?php if ($transactionsPage < $transactionsPages): ?>
                                <a class="tab-link" href="/?tab=dashboard&tp=<?= $transactionsPage + 1 ?>&month=<?= urlencode($filterMonth) ?>&q=<?= urlencode($searchQuery) ?>">Proxima</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($isAuth && $tab === 'dashboard'): ?>
    <nav class="mobile-fabbar" aria-label="Atalhos rapidos mobile">
        <a href="#form-lancamento">+ Lanc</a>
        <a href="#form-receita">+ Receita</a>
        <a href="#form-recorrencia">+ Recorr</a>
        <a href="#historico-lancamentos">Historico</a>
    </nav>
    <?php endif; ?>

    <?php
    $granaflowPage = ['tab' => $tab];
    if ($isAuth && $tab === 'dashboard') {
        $granaflowPage['dashboardChart'] = [
            'salary' => (float) $totals['salary'],
            'extraIncomes' => (float) $totals['extra_incomes'],
            'costs' => (float) $totals['costs'],
            'expenses' => (float) $totals['expenses'],
        ];
    }
    if ($isAuth && $tab === 'monthly') {
        $granaflowPage['monthlyRows'] = $monthlySpending;
    }
    ?>
    <script>window.__GRANAFLOW__ = <?= json_encode($granaflowPage, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="/assets/js/app.js" defer></script>
</body>
</html>
