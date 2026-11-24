const chart = document.querySelector('[data-chart]');

if (chart) {
    const data = JSON.parse(chart.dataset.chart);
    const plot = chart.querySelector('.chart__plot');
    const scroll = chart.querySelector('.chart__scroll');
    const empty = chart.querySelector('.chart__empty');
    const tooltip = chart.querySelector('.chart__tooltip');
    const tooltipTotal = tooltip.querySelector('.chart__tooltip-total');
    const tooltipType = tooltip.querySelector('.chart__tooltip-type');
    const controls = [...chart.querySelectorAll('[data-chart-type]')];
    const colors = ['#328263', '#b67a32', '#547ca6', '#9a6089', '#9a6351', '#7c8050', '#528d93', '#8071a6'];
    const namespace = 'http://www.w3.org/2000/svg';
    const number = new Intl.NumberFormat('ru-RU');

    const element = (name, attributes, label) => {
        const node = document.createElementNS(namespace, name);
        Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, String(value)));
        if (label !== undefined) node.textContent = label;
        return node;
    };

    chart.querySelectorAll('[data-chart-swatch]').forEach((swatch) => {
        const icon = element('svg', {viewBox: '0 0 16 16', width: 16, height: 16});
        icon.append(element('rect', {width: 16, height: 16, rx: 2, fill: colors[Number(swatch.dataset.chartSwatch) % colors.length]}));
        swatch.append(icon);
    });

    const positionTooltip = (clientX, clientY) => {
        const left = Math.max(8, Math.min(clientX + 12, window.innerWidth - tooltip.offsetWidth - 8));
        const top = Math.max(8, Math.min(clientY + 12, window.innerHeight - tooltip.offsetHeight - 8));
        tooltip.style.left = `${left}px`;
        tooltip.style.top = `${top}px`;
    };

    const draw = () => {
        const enabled = controls.map((control) => control.checked);
        const totals = data.days.map((day) => day.values.reduce((sum, value, index) => sum + (enabled[index] ? value : 0), 0));
        const max = totals.reduce((highest, total) => Math.max(highest, total), 1);
        const compact = data.days.length > 21;
        const gap = compact ? 2.8 : 28;
        const availableWidth = Math.max(600, scroll.clientWidth);
        const barWidth = compact
            ? Math.max(12, Math.min(36, Math.floor(((availableWidth - 72) / data.days.length - gap) * 10) / 10))
            : 36;
        const barStep = barWidth + gap;
        const width = Math.max(availableWidth, 72 + data.days.length * barStep);
        const height = 360;
        const top = 16;
        const bottom = 276;
        const chartHeight = bottom - top;
        plot.replaceChildren();
        plot.setAttribute('viewBox', `0 0 ${width} ${height}`);
        plot.setAttribute('width', width);
        plot.setAttribute('height', height);
        tooltip.hidden = true;
        empty.hidden = totals.some((total) => total > 0);

        const tickCount = Math.min(4, max);
        for (let tick = 0; tick <= tickCount; tick += 1) {
            const y = bottom - chartHeight * tick / tickCount;
            plot.append(element('line', {x1: 60, y1: y, x2: width - 8, y2: y, class: 'chart__grid'}));
            plot.append(element('text', {x: 52, y: y + 4, 'text-anchor': 'end', class: 'chart__axis'}, number.format(Math.round(max * tick / tickCount))));
        }

        data.days.forEach((day, dayIndex) => {
            const x = 72 + dayIndex * barStep;
            let y = bottom;
            day.values.forEach((value, typeIndex) => {
                if (!enabled[typeIndex] || value === 0) return;
                const barHeight = value / max * chartHeight;
                y -= barHeight;
                const totalLabel = `${day.date} · Всего: ${number.format(totals[dayIndex])}`;
                const typeLabel = `${data.types[typeIndex]}: ${number.format(value)}`;
                const params = new URLSearchParams({from: day.date, to: day.date, bot_type: data.typeIds[typeIndex], filters_set: '1'});
                if (data.site) params.set('site', data.site);
                Object.entries(data.filters).forEach(([key, value]) => params.set(key, value));
                const link = element('a', {href: `/?${params}#reports`, class: 'chart__sector', 'aria-label': `${totalLabel}. ${typeLabel}. Открыть отчёт`, 'aria-describedby': 'chart-tooltip'});
                const bar = element('rect', {x, y, width: barWidth, height: barHeight, fill: colors[typeIndex % colors.length]});
                const showTooltip = (clientX, clientY) => {
                    tooltipTotal.textContent = totalLabel;
                    tooltipType.textContent = typeLabel;
                    tooltip.hidden = false;
                    positionTooltip(clientX, clientY);
                };
                link.addEventListener('pointerenter', (event) => showTooltip(event.clientX, event.clientY));
                link.addEventListener('pointermove', (event) => positionTooltip(event.clientX, event.clientY));
                link.addEventListener('pointerleave', () => { tooltip.hidden = true; });
                link.addEventListener('focus', () => {
                    const bounds = bar.getBoundingClientRect();
                    showTooltip(bounds.left + bounds.width / 2, bounds.top);
                });
                link.addEventListener('blur', () => { tooltip.hidden = true; });
                link.append(bar);
                plot.append(link);
            });
            const [year, month, date] = day.date.split('-');
            const labelX = x + barWidth / 2;
            const labelY = bottom + 8;
            plot.append(element('text', {x: labelX, y: labelY, transform: `rotate(90 ${labelX} ${labelY})`, class: 'chart__axis'}, `${date}.${month}.${year.slice(-2)}`));
        });
    };

    controls.forEach((control) => control.addEventListener('change', draw));
    draw();
    if (typeof ResizeObserver !== 'undefined') {
        let previousWidth = scroll.clientWidth;
        new ResizeObserver(() => {
            if (scroll.clientWidth !== previousWidth) {
                previousWidth = scroll.clientWidth;
                draw();
            }
        }).observe(scroll);
    } else {
        window.addEventListener('resize', draw);
    }
}
