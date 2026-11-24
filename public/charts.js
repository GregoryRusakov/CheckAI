const chart = document.querySelector('[data-chart]');

if (chart) {
    const data = JSON.parse(chart.dataset.chart);
    const plot = chart.querySelector('.chart__plot');
    const empty = chart.querySelector('.chart__empty');
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

    const draw = () => {
        const enabled = controls.map((control) => control.checked);
        const totals = data.days.map((day) => day.values.reduce((sum, value, index) => sum + (enabled[index] ? value : 0), 0));
        const max = totals.reduce((highest, total) => Math.max(highest, total), 1);
        const width = Math.max(600, 72 + data.days.length * 64);
        const height = 320;
        const top = 16;
        const bottom = 276;
        const chartHeight = bottom - top;
        plot.replaceChildren();
        plot.setAttribute('viewBox', `0 0 ${width} ${height}`);
        plot.setAttribute('width', width);
        plot.setAttribute('height', height);
        empty.hidden = totals.some((total) => total > 0);

        const tickCount = Math.min(4, max);
        for (let tick = 0; tick <= tickCount; tick += 1) {
            const y = bottom - chartHeight * tick / tickCount;
            plot.append(element('line', {x1: 60, y1: y, x2: width - 8, y2: y, class: 'chart__grid'}));
            plot.append(element('text', {x: 52, y: y + 4, 'text-anchor': 'end', class: 'chart__axis'}, number.format(Math.round(max * tick / tickCount))));
        }

        data.days.forEach((day, dayIndex) => {
            const x = 72 + dayIndex * 64;
            let y = bottom;
            day.values.forEach((value, typeIndex) => {
                if (!enabled[typeIndex] || value === 0) return;
                const barHeight = value / max * chartHeight;
                y -= barHeight;
                const bar = element('rect', {x, y, width: 36, height: barHeight, fill: colors[typeIndex % colors.length]});
                bar.append(element('title', {}, `${day.date}: ${data.types[typeIndex]} — ${number.format(value)}`));
                plot.append(bar);
            });
            const [year, month, date] = day.date.split('-');
            plot.append(element('text', {x: x + 18, y: 298, 'text-anchor': 'middle', class: 'chart__axis'}, `${date}.${month}.${year.slice(-2)}`));
        });
    };

    controls.forEach((control) => control.addEventListener('change', draw));
    draw();
}
