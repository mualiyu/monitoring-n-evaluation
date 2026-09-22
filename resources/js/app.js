/*
 * The only bundled JavaScript in the platform.
 *
 * Alpine ships inside Livewire, so it is deliberately not imported here — a
 * second copy would register two `x-data` handlers on every element.
 *
 * ApexCharts is DYNAMICALLY imported inside the chart factory, not bundled
 * here. Statically imported it is 270KB gzipped on every page of the platform,
 * including the inspection form a field monitor opens on an old Android over
 * 3G — and most pages have no chart at all. Vite code-splits the dynamic
 * import, so the library is fetched by the handful of screens that plot
 * something and by nobody else.
 *
 * Leaflet follows the same rule on the app surfaces: the GIS dashboards import
 * it (and its stylesheet) on demand inside the projectMap factory below. The
 * public portal's map is separate and loads a pinned, integrity-checked copy
 * from the CDN its CSP allow-lists.
 */

/*
 * The chart factory behind <x-ui.chart>.
 *
 * Every colour is read from the COMPUTED design tokens rather than written
 * here, so a per-tenant brand override re-skins the charts for free and dark
 * mode is the palette the token file selected for dark — not an automatic
 * flip of the light one.
 *
 * Deliberate omissions: no toolbar, no gradients, no 3D, no dashed grid, no
 * legend for a single series (the figcaption names it). Text never wears the
 * data colour; it wears the ink tokens.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('uiChart', (config) => ({
        chart: null,
        observer: null,

        tokens() {
            const style = getComputedStyle(document.documentElement)
            const token = (name, fallback) => (style.getPropertyValue(name) || fallback).trim()

            return {
                highlight: token('--chart-highlight', '#23714b'),
                neutral: token('--chart-1', '#8e9c94'),
                grid: token('--chart-grid', '#e5e7eb'),
                axis: token('--chart-axis', '#6b7280'),
                ink: token('--ink', '#1f2937'),
                inkMuted: token('--ink-muted', '#6b7280'),
                surface: token('--surface-raised', '#ffffff'),
            }
        },

        formatValue(value, index) {
            const display = config.displays?.[index]

            if (display) {
                return display
            }

            if (config.format === 'percent') {
                return `${Math.round(value * 10) / 10}%`
            }

            return new Intl.NumberFormat().format(value)
        },

        options() {
            const t = this.tokens()
            const horizontal = config.type === 'bar'
            const emphasised = config.highlights?.some(Boolean)

            // One series means one colour. The neutral step is used ONLY when
            // the caller has marked a row as the one that matters — the
            // documented emphasis pattern, never a value-ramp across bars
            // (that would re-encode bar length as hue and spend the only free
            // channel on information the length already carries).
            const colours = config.values.map((_, i) =>
                emphasised ? (config.highlights[i] ? t.highlight : t.neutral) : t.highlight
            )

            return {
                chart: {
                    type: horizontal ? 'bar' : config.type,
                    height: config.height,
                    fontFamily: 'inherit',
                    background: 'transparent',
                    toolbar: { show: false },
                    animations: { enabled: true, speed: 250 },
                    parentHeightOffset: 0,
                },
                series: [{ name: config.title || 'Value', data: config.values }],
                colors: colours,
                // Colour by point, so emphasis follows the ENTITY rather than
                // its position after a filter.
                plotOptions: {
                    bar: {
                        horizontal,
                        distributed: true,
                        // Capped, never filling the slot: the leftover band is air.
                        barHeight: '58%',
                        columnWidth: '52%',
                        borderRadius: 4,
                        borderRadiusApplication: 'end',
                    },
                },
                // distributed:true would otherwise draw a legend swatch per bar.
                legend: { show: false },
                stroke: { width: config.type === 'line' || config.type === 'area' ? 2 : 0, curve: 'straight', lineCap: 'round' },
                fill: { type: 'solid', opacity: config.type === 'area' ? 0.1 : 1 },
                dataLabels: {
                    enabled: horizontal,
                    // Outside the bar end, so a long value can never be clipped
                    // by a short bar.
                    offsetX: 22,
                    textAnchor: 'start',
                    style: { fontSize: '12px', fontWeight: 600, colors: [t.ink] },
                    background: { enabled: false },
                    formatter: (value, { dataPointIndex }) => this.formatValue(value, dataPointIndex),
                },
                grid: {
                    borderColor: t.grid,
                    strokeDashArray: 0,
                    xaxis: { lines: { show: horizontal } },
                    yaxis: { lines: { show: !horizontal } },
                    padding: { left: 4, right: horizontal ? 28 : 4, top: 0, bottom: 0 },
                },
                xaxis: {
                    categories: config.labels,
                    axisBorder: { color: t.grid },
                    axisTicks: { color: t.grid },
                    labels: { style: { colors: t.axis, fontSize: '12px' } },
                },
                yaxis: {
                    labels: { style: { colors: t.axis, fontSize: '12px' }, maxWidth: 180 },
                },
                tooltip: {
                    theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
                    y: { formatter: (value, { dataPointIndex }) => this.formatValue(value, dataPointIndex) },
                },
                states: { hover: { filter: { type: 'lighten', value: 0.08 } } },
            }
        },

        async render() {
            // Fetched on demand — see the note at the top of this file.
            const { default: ApexCharts } = await import('apexcharts')

            // The component can be torn down (a Livewire navigation, a lazy
            // card replaced) while that fetch is in flight.
            if (!this.$refs.plot?.isConnected) {
                return
            }

            this.chart = new ApexCharts(this.$refs.plot, this.options())
            await this.chart.render()

            // The theme toggle flips a class on <html>; the tokens change with
            // it, so the chart re-reads them rather than keeping the palette it
            // was born with.
            this.observer = new MutationObserver(() => this.chart?.updateOptions(this.options(), false, false))
            this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })

            this.$el.addEventListener('livewire:navigating', () => this.teardown(), { once: true })
        },

        teardown() {
            this.observer?.disconnect()
            this.chart?.destroy()
            this.chart = null
        },

        destroy() {
            this.teardown()
        },
    }))

    /*
     * The GIS dashboards' map (livewire/shared/partials/project-map).
     *
     * Leaflet owns the canvas, so the element is wire:ignore'd and Livewire
     * never morphs it. First paint reads the pins embedded in the page; every
     * filter change after that arrives as a `project-map-updated` event carrying
     * the new pins, and the marker layer is redrawn in place — the basemap, zoom
     * and tile cache survive.
     *
     * Popups and markers are built from DOM nodes and textContent, never from
     * HTML strings: titles and site names are staff-entered, and staff-entered
     * is not trusted. Marker glyphs are cloned from server-rendered <template>s
     * of our own icon set.
     */
    window.Alpine.data('projectMap', (config) => ({
        map: null,
        layer: null,
        L: null,
        pending: null,

        async init() {
            const [{ default: L }] = await Promise.all([
                import('leaflet'),
                import('leaflet/dist/leaflet.css'),
            ])

            // Torn down (Livewire navigation) while the chunk was in flight.
            if (!this.$refs.canvas?.isConnected) {
                return
            }

            this.L = L
            this.map = L.map(this.$refs.canvas, { scrollWheelZoom: false, worldCopyJump: true })
            // No hard-coded centre: this platform ships to more than one
            // state. The first draw fits the map to the pins.
            this.map.setView([0, 0], 2)

            L.tileLayer(config.tiles.url, {
                maxZoom: config.tiles.maxZoom,
                attribution: config.tiles.attribution,
            }).addTo(this.map)

            this.layer = L.featureGroup().addTo(this.map)

            // A filter change can land before Leaflet has loaded.
            this.draw(this.pending ?? JSON.parse(this.$refs.pins.textContent || '[]'))
            this.pending = null
        },

        icon(pin) {
            const template = this.$refs['icon-' + pin.category]
            const element = template
                ? template.content.firstElementChild.cloneNode(true)
                : document.createElement('span')

            return this.L.divIcon({
                html: element,
                className: 'map-marker',
                iconSize: [28, 28],
                iconAnchor: [14, 14],
                popupAnchor: [0, -14],
            })
        },

        popup(pin) {
            const wrap = document.createElement('div')
            wrap.className = 'space-y-1 text-sm'

            const line = (text, className = '') => {
                if (!text) {
                    return
                }
                const node = document.createElement('div')
                node.className = className
                node.textContent = text
                wrap.appendChild(node)
            }

            line(pin.title, 'font-semibold text-ink')
            line(pin.reference, 'font-mono text-xs text-ink-muted')
            line(pin.entity, 'text-ink-muted')
            line([pin.site, pin.lga].filter(Boolean).join(' · '), 'text-ink-muted')
            // Status in words, always — the marker colour is never the only
            // place it is stated.
            line(pin.overdue ? `${pin.status_label} · ${pin.category_label}` : pin.status_label, 'font-medium text-ink')
            line(config.labels.progress.replace(':percent', pin.progress))
            if (pin.value) {
                line(config.labels.value.replace(':value', pin.value))
            }

            const link = document.createElement('a')
            link.href = config.projectUrl.replace('__ULID__', encodeURIComponent(pin.ulid))
            link.textContent = config.labels.open
            link.className = 'font-medium underline underline-offset-2'
            wrap.appendChild(link)

            return wrap
        },

        draw(pins) {
            if (!this.map) {
                this.pending = pins
                return
            }

            this.layer.clearLayers()

            // Drawn in reverse so the first pins — overdue ones, by the
            // server's ordering — sit on top where markers overlap.
            ;[...pins].reverse().forEach((pin) => {
                // Leaflet applies `alt` only to <img> icons, so on these div icons
                // the category must travel in the title (the tooltip) and the
                // aria-label (the accessible name of the focusable marker).
                const name = `${pin.title} — ${pin.category_label}`
                const marker = this.L.marker([pin.lat, pin.lng], {
                    icon: this.icon(pin),
                    title: name,
                    keyboard: true,
                    riseOnHover: true,
                })
                    .bindPopup(this.popup(pin), { maxWidth: 280 })
                    .addTo(this.layer)

                marker.getElement()?.setAttribute('aria-label', name)
            })

            if (pins.length > 0) {
                this.map.fitBounds(this.layer.getBounds(), { padding: [32, 32], maxZoom: 14 })
            }
        },

        destroy() {
            this.map?.remove()
            this.map = null
        },
    }))
})
