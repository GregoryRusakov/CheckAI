// Исходный файл UTF-8; Apache явно указывает эту кодировку для JS и CSS.
// Тема оформления: явный выбор пользователя хранится в localStorage и
// перекрывает системную настройку. Скрипт подключён без defer, чтобы атрибут
// data-theme успел появиться до первой отрисовки.
(function () {
    var KEY = 'checkai-theme';
    var root = document.documentElement;

    function saved() {
        try {
            return localStorage.getItem(KEY);
        } catch (error) {
            return null;
        }
    }

    function effective() {
        var chosen = root.getAttribute('data-theme');
        if (chosen === 'light' || chosen === 'dark') {
            return chosen;
        }
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function apply(theme) {
        if (theme === 'light' || theme === 'dark') {
            root.setAttribute('data-theme', theme);
        } else {
            root.removeAttribute('data-theme');
        }

        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.setAttribute('content', effective() === 'dark' ? '#121614' : '#f1f2ee');
        }

        var buttons = document.querySelectorAll('[data-theme-set]');
        Array.prototype.forEach.call(buttons, function (button) {
            button.setAttribute('aria-pressed', String(button.getAttribute('data-theme-set') === effective()));
        });
    }

    var initial = saved();
    if (initial === 'light' || initial === 'dark') {
        root.setAttribute('data-theme', initial);
    }

    document.addEventListener('DOMContentLoaded', function () {
        apply(root.getAttribute('data-theme'));

        Array.prototype.forEach.call(document.querySelectorAll('[data-theme-set]'), function (button) {
            button.addEventListener('click', function () {
                var theme = button.getAttribute('data-theme-set');
                try {
                    localStorage.setItem(KEY, theme);
                } catch (error) {
                    // Приватный режим: тема применится к текущей странице.
                }
                apply(theme);
            });
        });

        var media = window.matchMedia('(prefers-color-scheme: dark)');
        var onChange = function () {
            if (!saved()) {
                apply(null);
            }
        };
        if (typeof media.addEventListener === 'function') {
            media.addEventListener('change', onChange);
        }
    });
})();
