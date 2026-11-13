// Busy-состояние формы: показываем, что запрос уже уходит, но кнопки остаются
// отправляемыми — значение кнопки не теряется при отправке.
document.querySelectorAll('form[data-busy]').forEach((form) => {
    form.addEventListener('submit', () => {
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button').forEach((button) => {
            button.setAttribute('aria-busy', 'true');
        });
    });
});
