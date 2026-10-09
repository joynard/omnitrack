/* ==========================================================================
   Completion chart for the Projects and Tasks pages.

   Reads /board/summary -- the snapshot the app already computes for the AI
   palette -- and draws a stacked status bar per group plus one bar per
   category. Hand-built DOM: no chart library, no CDN, so it renders identically
   offline.

   The plain stat cards are NOT this file's job; the Projects page fills those
   from the same endpoint in its own inline script.

   A page opts in with markup, not with code:
     <div class="progress-panel" id="progress-panel" data-progress="projects,modules,categories">
   ========================================================================== */

(function () {
    'use strict';

    var SUMMARY_API = '/board/summary';

    var panel = document.getElementById('progress-panel');

    // Only the pages that declare a panel do any work.
    if (!panel) {
        return;
    }

    var SEGMENTS = [
        { key: 'done', label: 'Selesai', className: 'is-done' },
        { key: 'in_progress', label: 'In progress', className: 'is-progress' },
        { key: 'pending', label: 'Pending', className: 'is-pending' }
    ];

    function percentage(part, total) {
        return total > 0 ? Math.round((part / total) * 100) : 0;
    }

    /** One stacked bar. Zero-width segments are skipped rather than rendered. */
    function buildBar(counts, total) {
        var track = document.createElement('div');
        track.className = 'bar-track';

        SEGMENTS.forEach(function (segment) {
            var value = counts[segment.key] || 0;

            if (value <= 0) {
                return;
            }

            var fill = document.createElement('div');
            fill.className = 'bar-seg ' + segment.className;
            fill.style.width = (value / total) * 100 + '%';
            fill.title = segment.label + ': ' + value;
            track.appendChild(fill);
        });

        return track;
    }

    /** One labelled row: name, "done/total · pct%", then the bar. */
    function buildRow(label, counts) {
        var total = (counts.done || 0) + (counts.in_progress || 0) + (counts.pending || 0);

        var row = document.createElement('div');
        row.className = 'bar-row';

        var head = document.createElement('div');
        head.className = 'bar-row-head';

        var name = document.createElement('span');
        name.className = 'bar-row-label';
        // textContent: category names are user input.
        name.textContent = label;

        var value = document.createElement('span');
        value.className = 'bar-row-value';
        value.textContent = (counts.done || 0) + '/' + total + ' · ' + percentage(counts.done || 0, total) + '%';

        head.appendChild(name);
        head.appendChild(value);
        row.appendChild(head);

        if (total === 0) {
            var empty = document.createElement('p');
            empty.className = 'meta';
            empty.textContent = 'Belum ada data.';
            row.appendChild(empty);

            return row;
        }

        row.appendChild(buildBar(counts, total));

        return row;
    }

    /** Pull the three status counts for one prefix out of the flat stats map. */
    function countsFor(stats, prefix) {
        return {
            done: stats[prefix + '_done'] || 0,
            in_progress: stats[prefix + '_in_progress'] || 0,
            pending: stats[prefix + '_pending'] || 0
        };
    }

    function buildLegend() {
        var wrap = document.createElement('div');
        wrap.className = 'bar-legend';

        SEGMENTS.forEach(function (segment) {
            var item = document.createElement('span');
            item.className = 'legend-item';

            var swatch = document.createElement('span');
            swatch.className = 'legend-swatch ' + segment.className;

            var text = document.createElement('span');
            text.textContent = segment.label;

            item.appendChild(swatch);
            item.appendChild(text);
            wrap.appendChild(item);
        });

        return wrap;
    }

    function renderPanel(stats, categories) {
        var wanted = (panel.dataset.progress || '').split(',').filter(Boolean);

        panel.innerHTML = '';
        panel.appendChild(buildLegend());

        wanted.forEach(function (group) {
            if (group === 'categories') {
                return;
            }

            if (group === 'projects' || group === 'modules' || group === 'tasks') {
                panel.appendChild(buildRow(
                    group.charAt(0).toUpperCase() + group.slice(1),
                    countsFor(stats, group)
                ));
            }
        });

        if (wanted.indexOf('categories') === -1 || !categories.length) {
            return;
        }

        var heading = document.createElement('h3');
        heading.className = 'bar-group-title';
        heading.textContent = 'Per kategori';
        panel.appendChild(heading);

        categories.forEach(function (category) {
            panel.appendChild(buildRow(category.name, {
                done: category.projects_done || 0,
                in_progress: category.projects_in_progress || 0,
                pending: category.projects_pending || 0
            }));
        });
    }

    async function load() {
        var result = await window.Omnitrack.request(SUMMARY_API);

        if (!result.ok) {
            panel.innerHTML = '<p class="meta">Gagal memuat ringkasan.</p>';

            return;
        }

        var body = result.body || {};

        renderPanel(body.stats || {}, body.category_progress || []);
    }

    load();

    // Both boards dispatch this after every successful mutation, so the chart
    // follows the data without a reload.
    document.addEventListener('omnitrack:refresh', load);
})();
