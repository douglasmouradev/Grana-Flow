(function () {
    'use strict';

    const flashBox = document.querySelector('.flash');
    if (flashBox) {
        setTimeout(function () {
            flashBox.classList.add('hide');
            setTimeout(function () {
                flashBox.remove();
            }, 260);
        }, 2800);
    }

    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            const submitButton = form.querySelector('button[type="submit"], button:not([type])');
            if (submitButton) {
                submitButton.classList.add('submit-loading');
                submitButton.disabled = true;
            }
        });
    });

    const cfg = window.__GRANAFLOW__ || {};
    if (typeof Chart === 'undefined') {
        return;
    }

    if (cfg.tab === 'dashboard' && cfg.dashboardChart) {
        const d = cfg.dashboardChart;
        const totalIncome = d.salary + d.extraIncomes;
        const costs = d.costs;
        const expenses = d.expenses;
        const remaining = Math.max(0, totalIncome - costs - expenses);
        const el = document.getElementById('salaryPieChart');
        if (el) {
            new Chart(el, {
                type: 'pie',
                data: {
                    labels: ['Custos fixos', 'Gastos', 'Saldo'],
                    datasets: [{
                        data: [costs, expenses, remaining],
                        backgroundColor: ['#1f6feb', '#d17700', '#0f9d58']
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }
    }

    if (cfg.tab === 'monthly' && Array.isArray(cfg.monthlyRows)) {
        const monthlyRows = cfg.monthlyRows;
        const monthLabels = monthlyRows.map(function (item) { return item.month_key; }).reverse();
        const monthExtraIncomes = monthlyRows.map(function (item) { return Number(item.total_extra_incomes); }).reverse();
        const monthCosts = monthlyRows.map(function (item) { return Number(item.total_costs); }).reverse();
        const monthExpenses = monthlyRows.map(function (item) { return Number(item.total_expenses); }).reverse();

        const monthlyCtx = document.getElementById('monthlyChart');
        if (monthlyCtx) {
            new Chart(monthlyCtx, {
                type: 'bar',
                data: {
                    labels: monthLabels,
                    datasets: [
                        {
                            label: 'Receitas extras',
                            data: monthExtraIncomes,
                            backgroundColor: '#16a34a'
                        },
                        {
                            label: 'Custos',
                            data: monthCosts,
                            backgroundColor: '#4f46e5'
                        },
                        {
                            label: 'Gastos',
                            data: monthExpenses,
                            backgroundColor: '#0ea5e9'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom' }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }
    }
})();
