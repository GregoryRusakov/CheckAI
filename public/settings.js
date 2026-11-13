const domain = document.querySelector('input[name="domain"]');
const logPath = document.querySelector('input[name="log_path"]');

if (domain && logPath) {
    let previousSuggestion = '';
    domain.addEventListener('input', () => {
        const name = domain.value.trim().toLowerCase();
        const suggestion = name ? name + '.access.log' : '';
        if (!logPath.value || logPath.value === previousSuggestion) {
            logPath.value = suggestion;
        }
        previousSuggestion = suggestion;
    });
}

document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

document.querySelectorAll('button[data-confirm]').forEach((button) => {
    button.addEventListener('click', (event) => {
        if (!window.confirm(button.dataset.confirm)) {
            event.preventDefault();
        }
    });
});
