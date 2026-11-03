/**
 * Rendu ECharts pour les widgets JS Filament (HasJsRenderer) et le composant <x-echart>.
 *
 * La configuration reçue de PHP : { option, height, group, curveEditor }
 *  - option : option ECharts (JSON). Les chaînes "@nom" (voir FORMATTERS) sont remplacées par des fonctions.
 *  - group  : les graphiques d'un même groupe partagent infobulle et zoom (echarts.connect).
 *  - curveEditor : poignées déplaçables pour régler la loi d'eau à la souris (voir setupCurveEditor).
 */
import * as echarts from 'echarts/core'
import { BarChart, CustomChart, GaugeChart, LineChart, ScatterChart } from 'echarts/charts'
import {
    AxisPointerComponent,
    DataZoomComponent,
    DatasetComponent,
    GraphicComponent,
    GridComponent,
    LegendComponent,
    MarkAreaComponent,
    MarkLineComponent,
    MarkPointComponent,
    TitleComponent,
    ToolboxComponent,
    TooltipComponent,
    VisualMapComponent,
} from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'
import langFR from 'echarts/lib/i18n/langFR.js'

echarts.use([
    BarChart, CustomChart, GaugeChart, LineChart, ScatterChart,
    AxisPointerComponent, DataZoomComponent, DatasetComponent, GraphicComponent, GridComponent, LegendComponent,
    MarkAreaComponent, MarkLineComponent, MarkPointComponent, TitleComponent, ToolboxComponent, TooltipComponent,
    VisualMapComponent, CanvasRenderer,
])
echarts.registerLocale('FR', langFR)

const nf1 = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 })
const nf2 = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 })
const pad = (n) => String(n).padStart(2, '0')

const unit = (u, f = nf1) => (v) => (v === null || v === undefined || Number.isNaN(v) ? '—' : `${f.format(v)} ${u}`)

const FORMATTERS = {
    '@temp': unit('°C'),
    '@kelvin': unit('K'),
    '@kg': unit('kg'),
    '@kg2': unit('kg', nf2),
    '@pct': unit('%', nf1),
    '@kw': unit('kW'),
    '@kwh': unit('kWh'),
    '@min': unit('min', nf1),
    '@eur': unit('€', nf2),
    '@num': (v) => (v === null || v === undefined ? '—' : nf1.format(v)),
    '@hour': (v) => {
        const d = new Date(v)
        return `${pad(d.getHours())}:${pad(d.getMinutes())}`
    },
    '@day': (v) => {
        const d = new Date(v)
        return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}`
    },
    '@daylong': (v) => new Date(v).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }),
    '@month': (v) => new Date(v).toLocaleDateString('fr-FR', { month: 'short', year: '2-digit' }),
    '@axisTemp': (v) => `${v} °C`,
    // Infobulle des nuages de points « signature » : [T ext, kg, date]
    '@signatureTooltip': (p) => `${p.value[2] ?? ''}<br/>T ext : ${nf1.format(p.value[0])} °C<br/>${nf1.format(p.value[1])} kg/j`,
    // Infobulle du nuage loi d'eau : [T ext, consigne, mesurée, écart ambiance]
    '@curveTooltip': (p) => `T ext ${nf1.format(p.value[0])} °C<br/>Départ consigne ${nf1.format(p.value[1])} °C<br/>Départ mesuré ${nf1.format(p.value[2])} °C<br/>Écart ambiance ${nf1.format(p.value[3])} K`,
    // Frise (type Gantt) : data = [ligne, début, fin, libellé, couleur]
    '@ganttRender': (params, api) => {
        const row = api.value(0)
        const start = api.coord([api.value(1), row])
        const end = api.coord([api.value(2), row])
        const h = api.size([0, 1])[1] * 0.7
        const rect = { x: start[0], y: start[1] - h / 2, width: Math.max(1, end[0] - start[0]), height: h }
        const clipped = echarts.graphic.clipRectByRect(rect, {
            x: params.coordSys.x, y: params.coordSys.y, width: params.coordSys.width, height: params.coordSys.height,
        })
        return clipped && { type: 'rect', transition: ['shape'], shape: clipped, style: { fill: api.visual('color') } }
    },
    '@ganttTooltip': (p) => {
        const s = new Date(p.value[1]), e = new Date(p.value[2])
        const mins = Math.round((e - s) / 60000)
        return `${p.value[3]}<br/>${pad(s.getHours())}:${pad(s.getMinutes())} → ${pad(e.getHours())}:${pad(e.getMinutes())} (${mins} min)`
    },
}

function hydrate(value) {
    if (typeof value === 'string' && FORMATTERS[value]) return FORMATTERS[value]
    if (Array.isArray(value)) return value.map(hydrate)
    if (value && typeof value === 'object') {
        const out = {}
        for (const [k, v] of Object.entries(value)) out[k] = hydrate(v)
        return out
    }
    return value
}

const isDark = () => document.documentElement.classList.contains('dark')

function baseOption(dark) {
    const text = dark ? '#d4d4d8' : '#3f3f46'
    const line = dark ? '#3f3f46' : '#e4e4e7'
    return {
        backgroundColor: 'transparent',
        textStyle: { fontFamily: 'inherit', color: text },
        color: ['#f59e0b', '#3b82f6', '#ef4444', '#10b981', '#8b5cf6', '#ec4899', '#14b8a6', '#64748b', '#eab308'],
        legend: { textStyle: { color: text } },
        tooltip: { confine: true },
        _axis: { axisLine: { lineStyle: { color: line } }, splitLine: { lineStyle: { color: line } }, axisLabel: { color: text } },
    }
}

function applyAxisTheme(option, dark) {
    const { _axis } = baseOption(dark)
    for (const key of ['xAxis', 'yAxis']) {
        if (!option[key]) continue
        const axes = Array.isArray(option[key]) ? option[key] : [option[key]]
        axes.forEach((a) => {
            a.axisLine = { ...(_axis.axisLine), ...(a.axisLine || {}) }
            a.splitLine = { ...(_axis.splitLine), ...(a.splitLine || {}) }
            a.axisLabel = { ...(_axis.axisLabel), ...(a.axisLabel || {}) }
        })
    }
    return option
}

function buildOption(configuration) {
    const dark = isDark()
    const base = baseOption(dark)
    delete base._axis
    const option = hydrate(structuredClone(configuration.option || {}))
    for (const k of Object.keys(option)) if (option[k] === null) delete option[k]
    return applyAxisTheme({ ...base, ...option, legend: { ...base.legend, ...(option.legend || {}) } }, dark)
}

/**
 * Poignées déplaçables sur la loi d'eau : deux points (T ext, T départ). À la fin du déplacement,
 * un évènement « oko-curve-drag » remonte avec les nouvelles coordonnées ; la page Livewire recalcule
 * pente et pied de courbe.
 */
function setupCurveEditor(chart, host, editor) {
    if (!editor || !editor.points) return
    const seriesIndex = editor.seriesIndex ?? 0

    const draw = () => {
        const points = editor.points
        chart.setOption({
            graphic: points.map((p, i) => ({
                id: `handle-${i}`,
                type: 'circle',
                position: chart.convertToPixel({ seriesIndex }, p),
                shape: { r: 9 },
                style: { fill: '#f59e0b', stroke: '#fff', lineWidth: 2, shadowBlur: 6, shadowColor: 'rgba(0,0,0,.35)' },
                cursor: 'ns-resize',
                draggable: true,
                z: 100,
                ondrag(e) {
                    const pos = chart.convertFromPixel({ seriesIndex }, [this.x, this.y])
                    // on ne déplace qu'en vertical : T ext fixe
                    const flow = Math.round(pos[1] * 2) / 2
                    this.x = chart.convertToPixel({ seriesIndex }, [points[i][0], flow])[0]
                    editor.points[i] = [points[i][0], flow]
                    preview()
                },
                ondragend() {
                    host.dispatchEvent(new CustomEvent('oko-curve-drag', { bubbles: true, detail: { points: editor.points } }))
                },
            })),
        })
    }

    // Aperçu : droite entre les deux poignées, prolongée
    const preview = () => {
        const [[t1, f1], [t2, f2]] = editor.points
        const slope = (f1 - f2) / (t2 - t1)
        const data = []
        for (let t = -15; t <= 20; t += 0.5) data.push([t, Math.round((f1 + slope * (t1 - t)) * 10) / 10])
        chart.setOption({ series: [{ id: 'preview', type: 'line', data, showSymbol: false, lineStyle: { type: 'dashed', width: 2, color: '#f59e0b' }, z: 5 }] })
    }

    draw()
    chart.on('finished', () => {
        // repositionnement après redimensionnement
        editor.points.forEach((p, i) => {
            const pos = chart.convertToPixel({ seriesIndex }, p)
            chart.setOption({ graphic: [{ id: `handle-${i}`, position: pos }] }, { silent: true })
        })
    })
}

export default function mountEchart({ host, props }) {
    let configuration = props.configuration || {}
    host.style.width = '100%'
    host.style.height = configuration.height || '360px'

    let chart = null
    const init = () => {
        chart?.dispose()
        chart = echarts.init(host, isDark() ? 'dark' : null, { renderer: 'canvas', locale: 'FR' })
        if (configuration.group) {
            chart.group = configuration.group
            echarts.connect(configuration.group)
        }
        chart.setOption(buildOption(configuration), true)
        setupCurveEditor(chart, host, configuration.curveEditor ? structuredClone(configuration.curveEditor) : null)
    }
    init()

    const resize = new ResizeObserver(() => chart?.resize())
    resize.observe(host)

    // Bascule clair / sombre de Filament
    const theme = new MutationObserver(() => init())
    theme.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })

    return {
        update({ configuration: next }) {
            configuration = next || {}
            host.style.height = configuration.height || '360px'
            chart.setOption(buildOption(configuration), true)
            setupCurveEditor(chart, host, configuration.curveEditor ? structuredClone(configuration.curveEditor) : null)
        },
        destroy() {
            resize.disconnect()
            theme.disconnect()
            chart?.dispose()
            host.replaceChildren()
        },
    }
}
