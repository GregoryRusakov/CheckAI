const reportFilters = document.querySelector('#report-filters');

if (reportFilters) {
    const isDate = (input) => input.matches('input[type="date"]');
    let submitTimer = 0;

    const submit = () => {
        window.clearTimeout(submitTimer);
        submitTimer = 0;
        reportFilters.requestSubmit();
    };

    reportFilters.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-period-from][data-period-to]');
        if (!button) {
            return;
        }
        reportFilters.querySelector('[name="from"]').value = button.dataset.periodFrom;
        reportFilters.querySelector('[name="to"]').value = button.dataset.periodTo;
        submit();
    });

    // Выборы применяются сразу, даты — когда в поле собрана полная дата
    // или поле покинул фокус.
    reportFilters.addEventListener('change', (event) => {
        if (event.target.matches('input[type="checkbox"], select') || isDate(event.target)) {
            submit();
        }
    });

    // В части браузеров change у календаря приходит только после потери фокуса,
    // поэтому полную дату отправляем ещё и по input, с короткой паузой.
    reportFilters.addEventListener('input', (event) => {
        if (!isDate(event.target)) {
            return;
        }
        window.clearTimeout(submitTimer);
        submitTimer = 0;
        if (/^\d{4}-\d{2}-\d{2}$/.test(event.target.value)) {
            submitTimer = window.setTimeout(submit, 400);
        }
    });
}
